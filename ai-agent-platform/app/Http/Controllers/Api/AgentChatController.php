<?php

namespace App\Http\Controllers\Api;

use App\AI\Agents\OwnerAgent;
use App\AI\LlmModels\LlmModelCatalog;
use App\AI\Agents\McpToolPolicy;
use App\AI\Tools\Owner\CancelPendingAction;
use App\AI\Tools\Owner\ConfirmPendingAction;
use App\AI\Tools\ToolRegistry;
use App\Mcp\McpContext;
use App\Models\Business;
use App\Http\Controllers\Controller;
use App\Models\AgentAsset;
use App\Models\AgentChat;
use App\Models\AgentChatMessage;
use App\Models\AgentImageJob;
use App\Services\AgentChatTitleService;
use App\Services\AgentImageJobService;
use App\Services\Wallet\AiTaskBillingService;
use App\Services\Wallet\WalletService;
use App\Support\CurrentBusiness;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AgentChatController extends Controller
{
    public function __construct(
        private OwnerAgent $agent,
        private WalletService $wallets,
        private AiTaskBillingService $billing,
        private AgentImageJobService $imageJobs,
        private LlmModelCatalog $llmModels,
        private AgentChatTitleService $chatTitles,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $business = CurrentBusiness::require();
        $this->imageJobs->reconcileQueued($business);

        $data = $request->validate([
            'chat_id' => ['nullable', 'integer'],
        ]);

        $chat = $this->resolveChat($business->id, isset($data['chat_id']) ? (int) $data['chat_id'] : null, createIfMissing: false);

        $messages = [];
        if ($chat) {
            $messages = AgentChatMessage::query()
                ->where('business_id', $business->id)
                ->where('agent_chat_id', $chat->id)
                ->orderByDesc('id')
                ->limit(80)
                ->get()
                ->reverse()
                ->values()
                ->map(fn (AgentChatMessage $m) => $this->messagePayload($m))
                ->all();
        }

        return response()->json([
            'chat' => $chat?->toListArray(),
            'messages' => $messages,
            'pending_action' => $this->agent->openPending($business),
            'wallet' => $this->wallets->snapshotForBusiness($business, $request->user()),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $business = CurrentBusiness::require();
        $user = $request->user();

        $data = $request->validate([
            'text' => ['nullable', 'string', 'max:4000'],
            'voice_lang' => ['nullable', 'string', 'max:16'],
            'asset_ids' => ['nullable', 'array', 'max:10'],
            'asset_ids.*' => ['integer'],
            'chat_id' => ['nullable', 'integer'],
        ]);

        $text = trim((string) ($data['text'] ?? ''));
        $assetIds = array_values(array_unique(array_filter(array_map(
            'intval',
            is_array($data['asset_ids'] ?? null) ? $data['asset_ids'] : [],
        ))));

        $assets = collect();
        if ($assetIds !== []) {
            $assets = AgentAsset::query()
                ->forBusiness($business->id)
                ->whereIn('id', $assetIds)
                ->get();
            if ($assets->count() !== count($assetIds)) {
                throw ValidationException::withMessages([
                    'asset_ids' => 'One or more assets are invalid for this shop.',
                ]);
            }
        }

        if ($text === '' && $assets->isEmpty()) {
            throw ValidationException::withMessages([
                'text' => 'Message text or at least one attachment is required.',
            ]);
        }

        $chat = $this->resolveChat(
            $business->id,
            isset($data['chat_id']) ? (int) $data['chat_id'] : null,
            createIfMissing: true,
            userId: $user->id,
        );

        $business->loadMissing('agentSettings');
        $model = $this->llmModels->resolve($business->agentSettings?->llm_model);
        // Soft gate: catalog × multiplier so tool-loop turns rarely blow past the wallet mid-flight.
        $this->wallets->authorizeBusinessAi($business, $this->billing->chatAuthorizeDa($model['id']));

        $displayText = $text !== '' ? $text : '(attachment)';
        $assetPayload = $assets->map(fn (AgentAsset $a) => [
            'id' => $a->id,
            'url' => $a->absoluteUrl(),
            'mime' => $a->mime,
            'original_name' => $a->original_name,
        ])->values()->all();

        set_time_limit(300);

        $result = $this->agent->run(
            $business,
            $user,
            $displayText,
            $data['voice_lang'] ?? null,
            $this->ownerTools($business),
            $assets->all(),
            $chat->id,
        );

        $usage = is_array($result['usage'] ?? null) ? $result['usage'] : [];
        $needsTitle = $chat->title === null || $chat->title === '' || $chat->title === 'New chat';
        $titleForChat = null;
        if ($needsTitle) {
            $titleResult = $this->chatTitles->generate($business, $displayText, $assets->isNotEmpty());
            $usage = $this->mergeUsage($usage, $titleResult['usage']);
            $titleForChat = $titleResult['title'];
        }

        $charge = $this->billing->chargeChatTurn($business, $user, $model['id'], null, $usage);

        $userMessage = AgentChatMessage::query()->create([
            'business_id' => $business->id,
            'agent_chat_id' => $chat->id,
            'user_id' => $user->id,
            'role' => 'user',
            'content' => $displayText,
            'meta' => [
                'voice_lang' => $data['voice_lang'] ?? null,
                'wallet_ledger_id' => $charge?->wallet_ledger_id,
                'ai_task_charge_id' => $charge?->id,
                'usage' => $usage,
                'cost_usd' => $charge?->cost_usd,
                'cost_da' => $charge?->cost_da,
                'billed_from' => is_array($charge?->meta) ? ($charge->meta['billed_from'] ?? null) : null,
                'asset_ids' => $assets->pluck('id')->all(),
                'assets' => $assetPayload,
            ],
        ]);

        if ($charge) {
            $charge->reference_id = $userMessage->id;
            $charge->save();
        }

        $generatedAssets = array_values(array_filter(
            is_array($result['generated_assets'] ?? null) ? $result['generated_assets'] : [],
            fn ($row) => is_array($row) && ! empty($row['id']),
        ));
        $generatedIds = array_map(fn (array $row) => (int) $row['id'], $generatedAssets);

        $pendingJobs = array_values(array_filter(
            is_array($result['pending_image_jobs'] ?? null) ? $result['pending_image_jobs'] : [],
            fn ($row) => is_array($row) && ! empty($row['id']),
        ));

        $assistant = AgentChatMessage::query()->create([
            'business_id' => $business->id,
            'agent_chat_id' => $chat->id,
            'user_id' => null,
            'role' => 'assistant',
            'content' => $result['reply'],
            'meta' => [
                'tool_calls' => $result['tool_calls'] ?? [],
                'error' => $result['error'] ?? null,
                'asset_ids' => $generatedIds,
                'assets' => $generatedAssets,
                'image_jobs' => array_map(fn (array $row) => [
                    'id' => (int) $row['id'],
                    'status' => (string) ($row['status'] ?? AgentImageJob::STATUS_QUEUED),
                ], $pendingJobs),
            ],
        ]);

        $chat->touchActivity(
            $titleForChat
            ?? ($displayText !== '(attachment)' ? mb_substr($displayText, 0, 60) : null),
        );

        foreach ($pendingJobs as $row) {
            $job = AgentImageJob::query()
                ->forBusiness($business->id)
                ->whereKey((int) $row['id'])
                ->first();
            if ($job) {
                $this->imageJobs->linkToMessage($job, $assistant);
            }
        }

        $assistant->refresh();

        return response()->json([
            'chat' => $chat->fresh()->toListArray(),
            'message' => $this->messagePayload($assistant),
            'user_message' => $this->messagePayload($userMessage),
            'pending_action' => $result['pending_action'],
            'wallet' => $this->wallets->snapshotForBusiness($business->fresh(), $user),
        ]);
    }

    public function confirm(Request $request): JsonResponse
    {
        $business = CurrentBusiness::require();
        $user = $request->user();

        $data = $request->validate([
            'action_id' => ['required', 'integer'],
            'chat_id' => ['nullable', 'integer'],
        ]);

        $tool = app(ConfirmPendingAction::class);
        $result = $tool->handle($business, [
            'action_id' => (int) $data['action_id'],
            'confirmed' => true,
        ], ['user_id' => $user->id]);

        if (\App\AI\Runtime\AiRuntime::usesSk($business)) {
            try {
                app(\App\AI\Runtime\SkAgentClient::class)->resumeApproval(
                    $business,
                    (string) $data['action_id'],
                    empty($result['error']),
                    $user->id,
                    isset($data['chat_id']) ? (string) $data['chat_id'] : null,
                );
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $ok = empty($result['error']);
        $content = $ok
            ? 'Done — the pending action was confirmed and executed.'
            : ('Could not confirm: '.($result['error'] ?? 'unknown error'));

        $chat = $this->resolveChat(
            $business->id,
            isset($data['chat_id']) ? (int) $data['chat_id'] : null,
            createIfMissing: false,
        );

        $assistant = AgentChatMessage::query()->create([
            'business_id' => $business->id,
            'agent_chat_id' => $chat?->id ?? $this->latestChatId($business->id),
            'user_id' => null,
            'role' => 'assistant',
            'content' => $content,
            'meta' => ['confirm_result' => $result],
        ]);
        $chat?->touchActivity();

        return response()->json([
            'ok' => $ok,
            'result' => $result,
            'message' => $this->messagePayload($assistant),
            'pending_action' => $this->agent->openPending($business),
            'wallet' => $this->wallets->snapshotForBusiness($business, $user),
        ], $ok ? 200 : 422);
    }

    public function updatePendingChannels(Request $request): JsonResponse
    {
        $business = CurrentBusiness::require();

        $data = $request->validate([
            'action_id' => ['required', 'integer'],
            'account_ids' => ['required', 'array', 'min:1'],
            'account_ids.*' => ['integer'],
        ]);

        $result = $this->agent->updatePendingChannels(
            $business,
            (int) $data['action_id'],
            $data['account_ids'],
        );

        $ok = empty($result['error']);

        return response()->json([
            'ok' => $ok,
            'error' => $result['error'] ?? null,
            'pending_action' => $result['pending_action'] ?? $this->agent->openPending($business),
        ], $ok ? 200 : 422);
    }

    public function cancel(Request $request): JsonResponse
    {
        $business = CurrentBusiness::require();
        $user = $request->user();

        $data = $request->validate([
            'action_id' => ['required', 'integer'],
            'chat_id' => ['nullable', 'integer'],
        ]);

        $tool = app(CancelPendingAction::class);
        $result = $tool->handle($business, [
            'action_id' => (int) $data['action_id'],
        ], ['user_id' => $user->id]);

        if (\App\AI\Runtime\AiRuntime::usesSk($business)) {
            try {
                app(\App\AI\Runtime\SkAgentClient::class)->resumeApproval(
                    $business,
                    (string) $data['action_id'],
                    false,
                    $user->id,
                    isset($data['chat_id']) ? (string) $data['chat_id'] : null,
                );
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $ok = empty($result['error']);
        $content = $ok
            ? 'Cancelled — I will not run that action.'
            : ('Could not cancel: '.($result['error'] ?? 'unknown error'));

        $chat = $this->resolveChat(
            $business->id,
            isset($data['chat_id']) ? (int) $data['chat_id'] : null,
            createIfMissing: false,
        );

        $assistant = AgentChatMessage::query()->create([
            'business_id' => $business->id,
            'agent_chat_id' => $chat?->id ?? $this->latestChatId($business->id),
            'user_id' => null,
            'role' => 'assistant',
            'content' => $content,
            'meta' => ['cancel_result' => $result],
        ]);
        $chat?->touchActivity();

        return response()->json([
            'ok' => $ok,
            'result' => $result,
            'message' => $this->messagePayload($assistant),
            'pending_action' => $this->agent->openPending($business),
            'wallet' => $this->wallets->snapshotForBusiness($business, $user),
        ], $ok ? 200 : 422);
    }

    private function ownerTools(Business $business): ToolRegistry
    {
        return app(McpToolPolicy::class)->for(McpContext::SURFACE_OWNER, $business);
    }

    private function resolveChat(int $businessId, ?int $chatId, bool $createIfMissing, ?int $userId = null): ?AgentChat
    {
        if ($chatId) {
            $chat = AgentChat::query()
                ->where('business_id', $businessId)
                ->whereKey($chatId)
                ->first();
            if (! $chat) {
                throw ValidationException::withMessages([
                    'chat_id' => 'Chat not found for this shop.',
                ]);
            }

            return $chat;
        }

        if (! $createIfMissing) {
            return AgentChat::query()
                ->where('business_id', $businessId)
                ->orderByDesc('last_message_at')
                ->orderByDesc('id')
                ->first();
        }

        return AgentChat::query()->create([
            'business_id' => $businessId,
            'user_id' => $userId,
            'title' => 'New chat',
            'last_message_at' => now(),
        ]);
    }

    private function latestChatId(int $businessId): ?int
    {
        return AgentChat::query()
            ->where('business_id', $businessId)
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->value('id');
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $extra
     * @return array{prompt_tokens: int, completion_tokens: int, cost_usd: float, fal_calls: int, calls_with_cost: int}
     */
    private function mergeUsage(array $base, array $extra): array
    {
        return [
            'prompt_tokens' => (int) ($base['prompt_tokens'] ?? 0) + (int) ($extra['prompt_tokens'] ?? 0),
            'completion_tokens' => (int) ($base['completion_tokens'] ?? 0) + (int) ($extra['completion_tokens'] ?? 0),
            'cost_usd' => round((float) ($base['cost_usd'] ?? 0) + (float) ($extra['cost_usd'] ?? 0), 8),
            'fal_calls' => (int) ($base['fal_calls'] ?? 0) + (int) ($extra['fal_calls'] ?? 0),
            'calls_with_cost' => (int) ($base['calls_with_cost'] ?? 0) + (int) ($extra['calls_with_cost'] ?? 0),
        ];
    }

    /**
     * @return array{id: int, role: string, content: string, created_at: ?string, meta: ?array, assets: list<array<string, mixed>>}
     */
    private function messagePayload(AgentChatMessage $m): array
    {
        $meta = is_array($m->meta) ? $m->meta : [];
        $assets = is_array($meta['assets'] ?? null) ? $meta['assets'] : [];
        $jobs = AgentImageJob::query()
            ->where('agent_chat_message_id', $m->id)
            ->orderBy('id')
            ->get()
            ->map(fn (AgentImageJob $job) => $job->toPublicArray())
            ->all();
        if ($jobs !== []) {
            $meta['image_jobs'] = $jobs;
        }
        unset($meta['prompt']);
        if (isset($meta['tool_calls']) && is_array($meta['tool_calls'])) {
            $meta['tool_calls'] = array_map(function ($call) {
                if (! is_array($call)) {
                    return $call;
                }
                if (isset($call['arguments']['prompt'])) {
                    unset($call['arguments']['prompt']);
                }
                if (isset($call['result']['prompt_used'])) {
                    unset($call['result']['prompt_used']);
                }

                return $call;
            }, $meta['tool_calls']);
        }

        return [
            'id' => $m->id,
            'role' => $m->role,
            'content' => $m->content,
            'created_at' => optional($m->created_at)?->toIso8601String(),
            'meta' => $meta,
            'assets' => $assets,
            'image_jobs' => $jobs,
        ];
    }
}
