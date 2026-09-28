<?php

namespace App\AI\Agents;

use App\AI\Memory\ConversationMemory;
use App\AI\ReplyLanguage;
use App\AI\Skills\ReplyGroundingGuard;
use App\AI\Prompts\BusinessAgentPrompt;
use App\AI\Providers\FalLlmProvider;
use App\AI\Runtime\AiRuntime;
use App\AI\Runtime\SkAgentClient;
use App\AI\Rules\AgentRuleEngine;
use App\AI\LlmModels\LlmModelCatalog;
use App\Mcp\McpContext;
use App\Models\CustomerAiSetting;
use App\Models\AgentRun;
use App\Models\AgentToolCall;
use App\Models\AiProfilePerChannel;
use App\Models\Message;
use App\Services\ConversationService;
use App\Services\CustomerService;
use App\Services\WorkflowService;
use Throwable;

class BusinessAgent
{
    public function __construct(
        private FalLlmProvider $llm,
        private AgentLoop $loop,
        private McpToolPolicy $policy,
        private ConversationMemory $memory,
        private BusinessAgentPrompt $prompt,
        private AgentRuleEngine $rules,
        private ConversationService $conversations,
        private WorkflowService $workflows,
        private SkAgentClient $skClient,
        private CustomerApprover $customerApprover,
        private CustomerService $customers,
    ) {}

    public function run(Message $inbound): AgentRun
    {
        $conversation = $inbound->conversation()->with(['customer', 'business.agent', 'business.agentSettings', 'socialAccount'])->firstOrFail();
        $business = $conversation->business;
        $agent = $business->agent;
        $settings = $business->agentSettings;
        $customerAi = CustomerAiSetting::forBusiness($business);
        $allowReply = $this->shouldReply($inbound, $conversation, $agent);
        $leadWorkflows = $this->workflows->promptLines($business);
        $classify = $leadWorkflows !== [];

        $run = AgentRun::query()->create([
            'business_id' => $business->id,
            'conversation_id' => $conversation->id,
            'message_id' => $inbound->id,
            'status' => 'running',
            'provider' => 'fal',
            'model' => $this->customerModel($customerAi),
            'started_at' => now(),
            'metadata' => [
                'allow_reply' => $allowReply,
                'classify' => $classify,
            ],
        ]);

        if (! $conversation->ai_enabled || ($agent && ! $agent->ai_enabled)) {
            $run->update(['status' => 'skipped', 'completed_at' => now(), 'error' => 'AI disabled']);

            return $run;
        }

        if (! $allowReply && ! $classify) {
            $run->update(['status' => 'skipped', 'completed_at' => now(), 'error' => 'No reply and no lead workflows']);

            return $run;
        }

        $replyLanguage = ReplyLanguage::forCustomerAi($business);
        $handoffRule = $this->rules->matchHandoff($business, (string) $inbound->text)?->name
            ?? (($keyword = $customerAi->matchesHandoffKeyword((string) $inbound->text)) !== null ? 'keyword:'.$keyword : null);

        if ($allowReply && $handoffRule !== null) {
            $conversation->update(['ai_enabled' => false, 'status' => 'human']);
            $reply = ReplyLanguage::line('handoff', $replyLanguage);
            $this->conversations->storeOutboundAi($conversation, $reply, $run->model);
            $run->update([
                'status' => 'handed_off',
                'completed_at' => now(),
                'metadata' => ['rule' => $handoffRule, 'reply' => $reply],
            ]);
            try {
                app(\App\Services\Telegram\TelegramMerchantNotifier::class)->aiNeedsHuman(
                    $business,
                    $conversation,
                    (string) $handoffRule,
                );
            } catch (\Throwable) {
            }

            return $run->fresh('toolCalls');
        }

        if (! $allowReply && $handoffRule !== null) {
            $conversation->update(['ai_enabled' => false, 'status' => 'human']);
            $run->update([
                'status' => 'handed_off',
                'completed_at' => now(),
                'metadata' => ['rule' => $handoffRule, 'silent' => true],
            ]);
            try {
                app(\App\Services\Telegram\TelegramMerchantNotifier::class)->aiNeedsHuman(
                    $business,
                    $conversation,
                    (string) $handoffRule,
                );
            } catch (\Throwable) {
            }

            return $run->fresh('toolCalls');
        }

        $surface = $inbound->type === 'comment' ? 'comment' : 'dm';
        $parentPostContext = null;
        if ($surface === 'comment') {
            $resolved = app(\App\Services\Comments\CommentPostContextResolver::class)
                ->forComment($business, $conversation->socialAccount, $this->commentPostId($inbound));
            $parentPostContext = is_array($resolved) ? ($resolved['block'] ?? null) : null;
        }

        $system = $this->prompt->system(
            $business,
            $agent,
            $settings,
            $conversation->customer,
            $leadWorkflows,
            $allowReply,
            $conversation->socialAccount,
            $this->channelProfile($conversation->social_account_id),
            $surface,
            $customerAi,
            $parentPostContext,
        );

        if (AiRuntime::usesSk($business)) {
            return $this->runViaSk($run, $inbound, $conversation, $business, $system, $allowReply, $replyLanguage);
        }

        $messages = array_merge(
            [['role' => 'system', 'content' => $system]],
            $this->memory->messages($conversation),
        );

        $tools = $this->policy->for(McpContext::SURFACE_CUSTOMER, $business);
        $context = [
            'customer_id' => $conversation->customer_id,
            'conversation_id' => $conversation->id,
            'agent_run_id' => $run->id,
        ];

        $finalText = null;
        $inputTokens = 0;
        $outputTokens = 0;
        $handedOff = false;
        $groundingSources = [(string) $inbound->text];
        $profile = $this->channelProfile($conversation->social_account_id);
        if ($profile) {
            $meta = is_array($profile->profile) ? $profile->profile : [];
            // Identity SoR is Supabase vectors — only ground on status summary, not a JSON blob.
            if (($meta['storage'] ?? '') === 'supabase' || isset($meta['chunks_upserted'])) {
                $groundingSources[] = json_encode([
                    'storage' => 'supabase',
                    'summary' => (string) ($meta['summary'] ?? ''),
                    'chunks_upserted' => (int) ($meta['chunks_upserted'] ?? 0),
                    'namespaces' => $meta['namespaces'] ?? [],
                ], JSON_UNESCAPED_UNICODE);
            } elseif ($meta !== []) {
                $groundingSources[] = json_encode($meta, JSON_UNESCAPED_UNICODE);
            }
        }
        $llmOptions = ['model' => $run->model];

        $afterTool = function (string $name, array $args, array $result, int $durationMs = 0) use ($run, &$groundingSources, &$handedOff): array {
            AgentToolCall::query()->create([
                'agent_run_id' => $run->id,
                'tool' => $name,
                'arguments' => $args,
                'result' => $result,
                'status' => isset($result['error']) ? 'error' : 'ok',
                'duration_ms' => $durationMs,
            ]);
            $groundingSources[] = json_encode($result, JSON_UNESCAPED_UNICODE);
            if (! empty($result['handoff'])) {
                $handedOff = true;

                return ['stop' => true];
            }

            return [];
        };

        try {
            $loop = $this->loop->run($business, $messages, $tools, $context, $llmOptions, $afterTool);
            $inputTokens = (int) $loop->usage['prompt_tokens'];
            $outputTokens = (int) $loop->usage['completion_tokens'];
            $messages = $loop->messages;
            $finalText = $handedOff
                ? ($allowReply ? ReplyLanguage::line('handoff', $replyLanguage) : '')
                : $loop->text;
        } catch (Throwable $e) {
            $usage = $e instanceof AgentLoopException ? $e->usage : [];
            $run->update([
                'status' => 'failed',
                'completed_at' => now(),
                'error' => $e->getMessage(),
                'input_tokens' => (int) ($usage['prompt_tokens'] ?? 0),
                'output_tokens' => (int) ($usage['completion_tokens'] ?? 0),
            ]);
            if ($allowReply) {
                $this->conversations->storeOutboundAi(
                    $conversation->fresh(),
                    ReplyLanguage::line('busy', $replyLanguage),
                    $run->model,
                );
            }

            return $run->fresh('toolCalls');
        }

        if ($allowReply) {
            if ($finalText === null || $finalText === '') {
                $finalText = ReplyLanguage::line('empty', $replyLanguage);
            }
            $guard = app(ReplyGroundingGuard::class);
            $finalText = $guard->ensure(
                $finalText,
                $groundingSources,
                function () use ($messages, $finalText, $replyLanguage, $llmOptions): string {
                    $messages[] = ['role' => 'assistant', 'content' => $finalText];
                    $messages[] = ['role' => 'user', 'content' => 'Rewrite the last reply in '.ReplyLanguage::normalize($replyLanguage).' without any number that was not in the shop data or the customer message.'];
                    $response = $this->llm->chat($messages, [], $llmOptions);
                    $content = $response['choices'][0]['message']['content'] ?? '';

                    return is_string($content) ? $content : '';
                },
                ReplyLanguage::line('will_check', $replyLanguage),
            );

            $approved = $this->customerApprover->reviewUntilApproved(
                $finalText,
                (string) $inbound->text,
                $groundingSources,
                function (string $feedback, string $previous) use ($messages, $replyLanguage, $llmOptions): array {
                    $msgs = $messages;
                    $msgs[] = ['role' => 'assistant', 'content' => $previous];
                    $msgs[] = [
                        'role' => 'user',
                        'content' => 'CustomerApprover rejected your reply. Feedback: '.$feedback
                            .'. Rewrite in '.ReplyLanguage::normalize($replyLanguage)
                            .' with no invented prices/stock. Reply text only.',
                    ];
                    $response = $this->llm->chat($msgs, [], $llmOptions);
                    $content = $response['choices'][0]['message']['content'] ?? '';
                    $usage = $response['usage'] ?? [];

                    return [
                        'reply' => is_string($content) ? $content : $previous,
                        'usage' => [
                            'prompt_tokens' => (int) ($usage['prompt_tokens'] ?? 0),
                            'completion_tokens' => (int) ($usage['completion_tokens'] ?? 0),
                        ],
                    ];
                },
                $run->model,
                2,
                app(\App\Services\Agents\BehaviorRulesPrompt::class)->block($business),
            );
            $finalText = (string) ($approved['reply'] ?? $finalText);
            AgentToolCall::query()->create([
                'agent_run_id' => $run->id,
                'tool' => 'customer_approver',
                'arguments' => [],
                'result' => [
                    'approver' => 'CustomerApprover',
                    'approved' => (bool) ($approved['approved'] ?? false),
                    'retried' => (bool) ($approved['retried'] ?? false),
                    'rounds' => $approved['rounds'] ?? [],
                ],
                'status' => 'ok',
                'duration_ms' => 0,
            ]);
            $inputTokens += (int) ($approved['usage']['prompt_tokens'] ?? 0);
            $outputTokens += (int) ($approved['usage']['completion_tokens'] ?? 0);

            $finalText = $this->clampReply($finalText, (int) $customerAi->max_reply_chars);
            $this->conversations->storeOutboundAi($conversation->fresh(), $finalText, $run->model);
        }

        $run->update([
            'status' => $handedOff ? 'handed_off' : 'completed',
            'completed_at' => now(),
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'metadata' => [
                'reply' => $allowReply ? $finalText : null,
                'allow_reply' => $allowReply,
                'classify' => $classify,
                'silent' => ! $allowReply,
            ],
        ]);

        return $run->fresh('toolCalls');
    }

    private function runViaSk(
        AgentRun $run,
        Message $inbound,
        $conversation,
        $business,
        string $system,
        bool $allowReply,
        string $replyLanguage,
    ): AgentRun {
        $history = array_values(array_filter(
            $this->memory->messages($conversation),
            fn ($row) => is_array($row) && in_array($row['role'] ?? null, ['user', 'assistant'], true),
        ));

        $tools = $this->policy->for(McpContext::SURFACE_CUSTOMER, $business);
        $allowlist = array_map(fn ($tool) => $tool->name(), $tools->all());

        $knownCheckout = [];
        $customer = $conversation?->customer;
        if ($customer) {
            $knownCheckout = $this->customers->knownCheckoutFacts($customer);
        }

        $result = $this->skClient->customerTurn(
            $business,
            $inbound,
            $history,
            $system,
            $run->model,
            $allowReply,
            $allowlist,
            $knownCheckout,
        );

        $handedOff = false;
        foreach ($result['tool_calls'] ?? [] as $call) {
            $toolName = (string) ($call['tool'] ?? '');
            $toolResult = is_array($call['result'] ?? null) ? $call['result'] : [];
            AgentToolCall::query()->create([
                'agent_run_id' => $run->id,
                'tool' => $toolName,
                'arguments' => is_array($call['arguments'] ?? null) ? $call['arguments'] : [],
                'result' => $toolResult,
                'status' => isset($toolResult['error']) ? 'error' : 'ok',
                'duration_ms' => 0,
            ]);
            if ($toolName === 'handoff_to_human' || ! empty($toolResult['handoff'])) {
                $handedOff = true;
            }
        }

        $finalText = (string) ($result['reply'] ?? '');
        if ($handedOff) {
            $finalText = $allowReply ? ReplyLanguage::line('handoff', $replyLanguage) : '';
            $conversation->update(['ai_enabled' => false, 'status' => 'human']);
        } elseif ($allowReply) {
            if ($finalText === '') {
                $finalText = ReplyLanguage::line('empty', $replyLanguage);
            }
            $guard = app(ReplyGroundingGuard::class);
            $groundingSources = [(string) $inbound->text];
            foreach ($result['citations'] ?? [] as $citation) {
                if (is_array($citation) && ! empty($citation['content'])) {
                    $groundingSources[] = (string) $citation['content'];
                }
            }
            foreach ($result['tool_calls'] ?? [] as $call) {
                $groundingSources[] = json_encode($call['result'] ?? [], JSON_UNESCAPED_UNICODE);
            }
            $customerAi = CustomerAiSetting::forBusiness($business);
            $finalText = $guard->ensure(
                $finalText,
                $groundingSources,
                fn () => $finalText,
                ReplyLanguage::line('will_check', $replyLanguage),
            );
            $finalText = $this->clampReply($finalText, (int) $customerAi->max_reply_chars);
            $this->conversations->storeOutboundAi($conversation->fresh(), $finalText, $run->model);
        }

        $usage = is_array($result['usage'] ?? null) ? $result['usage'] : [];
        $run->update([
            'status' => ! empty($result['error']) ? 'failed' : ($handedOff ? 'handed_off' : 'completed'),
            'completed_at' => now(),
            'error' => $result['error'] ?? null,
            'input_tokens' => (int) ($usage['prompt_tokens'] ?? 0),
            'output_tokens' => (int) ($usage['completion_tokens'] ?? 0),
            'provider' => 'sk',
            'metadata' => [
                'reply' => $allowReply ? $finalText : null,
                'allow_reply' => $allowReply,
                'runtime' => 'sk',
                'session_id' => $result['session_id'] ?? null,
            ],
        ]);

        return $run->fresh('toolCalls');
    }

    private function customerModel(CustomerAiSetting $customerAi): string
    {
        $key = (string) ($customerAi->llm_model ?? '');
        $catalog = app(LlmModelCatalog::class);
        if ($key !== '' && $catalog->isValid($key)) {
            return (string) $catalog->resolve($key)['model'];
        }

        return (string) config('services.fal.model');
    }

    private function clampReply(string $text, int $max): string
    {
        if ($max < 80 || mb_strlen($text) <= $max) {
            return $text;
        }

        $cut = mb_substr($text, 0, $max);
        $boundary = max((int) mb_strrpos($cut, "\n"), (int) mb_strrpos($cut, '. '), (int) mb_strrpos($cut, '؟'), (int) mb_strrpos($cut, '!'));

        return rtrim($boundary > $max / 2 ? mb_substr($cut, 0, $boundary + 1) : $cut);
    }

    private function shouldReply(Message $inbound, $conversation, $agent): bool
    {
        if (! $agent) {
            return false;
        }

        if ($conversation->platform === 'simulator') {
            return true;
        }

        if ($inbound->type === 'comment') {
            $postId = $this->commentPostId($inbound);
            if (! $postId || ! $conversation->social_account_id) {
                return false;
            }

            $workflow = app(\App\Services\WorkflowService::class)->findActiveEngagementForPost(
                (int) $conversation->business_id,
                (int) $conversation->social_account_id,
                $postId,
            );
            $step = $workflow?->publicReplyStep() ?? [];
            if (! $workflow || ! $workflow->stepEnabled($step) || ($step['mode'] ?? 'agent') === 'fixed') {
                return false;
            }

            return (bool) $agent->auto_reply_comments;
        }

        $platform = (string) ($conversation->platform ?? '');
        $text = (string) ($inbound->text ?? '');
        if ($platform !== '' && $text !== '' && $conversation->business_id) {
            $business = \App\Models\Business::query()->find($conversation->business_id);
            if ($business) {
                $dmWorkflow = app(\App\Services\WorkflowService::class)->findActiveDmKeywordWorkflow(
                    $business,
                    $platform,
                    $text,
                );
                if ($dmWorkflow) {
                    $step = $dmWorkflow->dmReplyStep() ?? [];
                    if (($step['mode'] ?? 'fixed') === 'agent') {
                        return true;
                    }

                    return false;
                }
            }
        }

        return (bool) $agent->auto_reply_dms;
    }

    private function commentPostId(Message $inbound): ?string
    {
        $meta = is_array($inbound->metadata) ? $inbound->metadata : [];
        foreach (['post_id', 'platform_post_id'] as $key) {
            $value = $meta[$key] ?? ($meta['metadata'][$key] ?? null);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function channelProfile(?int $socialAccountId): ?AiProfilePerChannel
    {
        if (! $socialAccountId) {
            return null;
        }

        return AiProfilePerChannel::query()
            ->ready()
            ->where('social_account_id', $socialAccountId)
            ->first();
    }
}
