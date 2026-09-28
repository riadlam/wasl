<?php

namespace App\AI\Runtime;

use App\Models\AgentAsset;
use App\Models\AgentChatMessage;
use App\Models\Business;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class SkAgentClient
{
    /**
     * @param  list<AgentAsset>  $attachments
     * @return array{
     *   reply: string,
     *   pending_action: ?array,
     *   tool_calls: list<array<string, mixed>>,
     *   generated_assets: list<array<string, mixed>>,
     *   pending_image_jobs: list<array<string, mixed>>,
     *   usage: array{prompt_tokens: int, completion_tokens: int, cost_usd: float, fal_calls: int, calls_with_cost: int},
     *   error?: string,
     *   citations?: list<array<string, mixed>>,
     *   session_id?: string,
     *   runtime?: string
     * }
     */
    public function ownerChat(
        Business $business,
        User $actor,
        string $userText,
        ?string $voiceLang,
        array $attachments = [],
        ?int $agentChatId = null,
        ?string $systemPrompt = null,
        ?string $llmModel = null,
        array $toolAllowlist = [],
    ): array {
        $history = $this->ownerHistory($business, $agentChatId);
        $payload = [
            'text' => $userText,
            'voice_lang' => $voiceLang,
            'agent_chat_id' => $agentChatId,
            'attachments' => array_map(fn (AgentAsset $a) => [
                'id' => $a->id,
                'url' => $a->absoluteUrl(),
                'mime' => $a->mime,
                'original_name' => $a->original_name,
                'kind' => str_starts_with((string) $a->mime, 'image/') ? 'image' : 'other',
            ], $attachments),
            'history' => $history,
            'system_prompt' => $systemPrompt,
            'llm_model' => $this->resolveProviderModel($llmModel),
            'tool_allowlist' => $toolAllowlist,
        ];

        return $this->post('/v1/owner/chat', $business, $payload, $actor->id);
    }

    /**
     * @return array{
     *   reply: string,
     *   tool_calls: list<array<string, mixed>>,
     *   usage: array{prompt_tokens: int, completion_tokens: int, cost_usd: float, fal_calls: int, calls_with_cost: int},
     *   error?: string,
     *   citations?: list<array<string, mixed>>,
     *   session_id?: string,
     *   runtime?: string
     * }
     */
    public function customerTurn(
        Business $business,
        Message $inbound,
        array $history,
        ?string $systemPrompt = null,
        ?string $llmModel = null,
        bool $allowReply = true,
        array $toolAllowlist = [],
        array $knownCheckout = [],
    ): array {
        $conversation = $inbound->conversation;
        $payload = [
            'message_id' => $inbound->id,
            'conversation_id' => $conversation?->id ?? 0,
            'text' => (string) $inbound->text,
            'message_type' => $inbound->type === 'comment' ? 'comment' : 'dm',
            'media_url' => $inbound->media_url,
            'media_type' => $inbound->media_type,
            'history' => array_values(array_map(function ($row) {
                $out = [
                    'role' => (($row['role'] ?? '') === 'assistant') ? 'assistant' : 'user',
                    'content' => self::asText($row['content'] ?? ''),
                ];
                if (! empty($row['at'])) {
                    $out['at'] = self::asText($row['at']);
                }

                return $out;
            }, $history)),
            'system_prompt' => $systemPrompt,
            'llm_model' => $this->resolveProviderModel($llmModel),
            'allow_reply' => $allowReply,
            'customer_id' => $conversation?->customer_id,
            'social_account_id' => $conversation?->social_account_id,
            'tool_allowlist' => $toolAllowlist,
            'known_checkout' => $knownCheckout,
        ];

        return $this->post('/v1/customer/turn', $business, $payload, null);
    }

    /**
     * @param  array<string, mixed>  $corpus
     * @return array{ok: bool, chunks_upserted?: int, namespaces?: list<string>, summary?: string, error?: string}
     */
    public function buildIdentity(
        Business $business,
        int $socialAccountId,
        array $corpus,
        ?string $llmModel = null,
    ): array {
        try {
            $response = Http::timeout((int) config('ai_runtime.timeout', 200))
                ->withHeaders($this->headers($business))
                ->post(config('ai_runtime.url').'/v1/identity/build', [
                    'business_id' => $business->id,
                    'social_account_id' => $socialAccountId,
                    'corpus' => $corpus,
                    'llm_model' => $this->resolveProviderModel($llmModel),
                ]);

            if (! $response->successful()) {
                return ['ok' => false, 'error' => 'http_'.$response->status().': '.$response->body()];
            }

            $data = $response->json();
            if (! is_array($data)) {
                return ['ok' => false, 'error' => 'invalid_json'];
            }

            return [
                'ok' => (bool) ($data['ok'] ?? false),
                'chunks_upserted' => (int) ($data['chunks_upserted'] ?? 0),
                'namespaces' => is_array($data['namespaces'] ?? null) ? $data['namespaces'] : [],
                'summary' => (string) ($data['summary'] ?? ''),
                'storage' => (string) ($data['storage'] ?? 'supabase'),
                'error' => isset($data['error']) ? (string) $data['error'] : null,
            ];
        } catch (Throwable $e) {
            report($e);

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * @return array{ok: bool, hits?: list<array<string, mixed>>, error?: string}
     */
    public function searchKnowledge(
        Business $business,
        string $query,
        ?string $namespace = null,
        int $topK = 6,
    ): array {
        try {
            $response = Http::timeout((int) config('ai_runtime.timeout', 200))
                ->withHeaders($this->headers($business))
                ->post(config('ai_runtime.url').'/v1/knowledge/search', [
                    'query' => $query,
                    'namespace' => $namespace,
                    'top_k' => $topK,
                ]);

            if (! $response->successful()) {
                return ['ok' => false, 'error' => 'http_'.$response->status()];
            }

            $data = $response->json();

            return [
                'ok' => true,
                'hits' => is_array($data['hits'] ?? null) ? $data['hits'] : [],
            ];
        } catch (Throwable $e) {
            report($e);

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{ok: bool, chunks_upserted?: int, error?: string}
     */
    public function ingestKnowledge(
        Business $business,
        string $namespace,
        string $sourceType,
        string $sourceId,
        string $content,
        array $metadata = [],
    ): array {
        try {
            $response = Http::timeout((int) config('ai_runtime.timeout', 200))
                ->withHeaders($this->headers($business))
                ->post(config('ai_runtime.url').'/v1/knowledge/ingest', [
                    'business_id' => $business->id,
                    'namespace' => $namespace,
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'content' => $content,
                    'metadata' => $metadata,
                ]);

            if (! $response->successful()) {
                return ['ok' => false, 'error' => 'http_'.$response->status().': '.$response->body()];
            }

            return array_merge(['ok' => true], $response->json() ?? []);
        } catch (Throwable $e) {
            report($e);

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Persist a durable business memory (business_memories + memories embeddings).
     *
     * @param  array<string, mixed>  $metadata
     * @return array{ok: bool, error?: string, key?: string}
     */
    public function rememberMemory(
        Business $business,
        string $key,
        string $content,
        array $metadata = [],
    ): array {
        try {
            $response = Http::timeout((int) config('ai_runtime.timeout', 200))
                ->withHeaders($this->headers($business))
                ->post(config('ai_runtime.url').'/v1/memory/remember', [
                    'key' => mb_substr($key, 0, 120),
                    'content' => $content,
                    'metadata' => $metadata,
                ]);

            if (! $response->successful()) {
                return ['ok' => false, 'error' => 'http_'.$response->status().': '.$response->body()];
            }

            $json = $response->json() ?? [];

            return array_merge(['ok' => (bool) ($json['ok'] ?? true)], $json);
        } catch (Throwable $e) {
            report($e);

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function resumeApproval(Business $business, string $approvalId, bool $approved, ?int $userId = null, ?string $sessionId = null): array
    {
        return $this->post('/v1/approvals/'.$approvalId.'/resume', $business, [
            'approved' => $approved,
            'session_id' => $sessionId,
        ], $userId);
    }

    /**
     * Campaign brief turn via Owner/Identity/OwnerApprover (fills agent-activity.log).
     *
     * @param  list<array{role: string, content: string}>  $history
     * @return array{
     *   ready: bool,
     *   message: string,
     *   question: string,
     *   tool_calls: list<array<string, mixed>>,
     *   approver: ?array<string, mixed>,
     *   agents: array<string, mixed>,
     *   usage: array{prompt_tokens: int, completion_tokens: int, cost_usd: float, fal_calls: int, calls_with_cost: int},
     *   runtime: string,
     *   error?: string
     * }
     */
    public function campaignBrief(
        Business $business,
        User $actor,
        string $contextBlock,
        string $languageHint,
        array $history = [],
        ?string $llmModel = null,
    ): array {
        try {
            $response = Http::timeout(90)
                ->connectTimeout(5)
                ->withHeaders($this->headers($business, $actor->id))
                ->post(config('ai_runtime.url').'/v1/campaigns/brief', [
                    'context_block' => $contextBlock,
                    'language_hint' => $languageHint,
                    'history' => array_values(array_map(fn ($row) => [
                        'role' => (($row['role'] ?? '') === 'assistant') ? 'assistant' : 'user',
                        'content' => self::asText($row['content'] ?? ''),
                    ], $history)),
                    'llm_model' => $this->resolveProviderModel($llmModel),
                ]);

            if (! $response->successful()) {
                throw new RuntimeException('SK campaign brief HTTP '.$response->status().': '.$response->body());
            }

            $data = $response->json();
            if (! is_array($data)) {
                throw new RuntimeException('SK campaign brief returned non-JSON');
            }

            $usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];

            return [
                'ready' => (bool) ($data['ready'] ?? false),
                'message' => self::asText($data['message'] ?? ''),
                'question' => self::asText($data['question'] ?? ''),
                'tool_calls' => is_array($data['tool_calls'] ?? null) ? $data['tool_calls'] : [],
                'approver' => is_array($data['approver'] ?? null) ? $data['approver'] : null,
                'agents' => is_array($data['agents'] ?? null) ? $data['agents'] : [],
                'usage' => [
                    'prompt_tokens' => (int) ($usage['prompt_tokens'] ?? 0),
                    'completion_tokens' => (int) ($usage['completion_tokens'] ?? 0),
                    'cost_usd' => (float) ($usage['cost_usd'] ?? 0),
                    'fal_calls' => (int) ($usage['fal_calls'] ?? 0),
                    'calls_with_cost' => (int) ($usage['calls_with_cost'] ?? 0),
                ],
                'runtime' => self::asText($data['runtime'] ?? 'sk') ?: 'sk',
                'error' => isset($data['error']) ? self::asText($data['error']) : null,
            ];
        } catch (ConnectionException $e) {
            report($e);

            return [
                'ready' => false,
                'message' => '',
                'question' => '',
                'tool_calls' => [],
                'approver' => null,
                'agents' => [],
                'usage' => ['prompt_tokens' => 0, 'completion_tokens' => 0, 'cost_usd' => 0.0, 'fal_calls' => 0, 'calls_with_cost' => 0],
                'runtime' => 'sk',
                'error' => 'AI runtime unreachable: '.$e->getMessage(),
            ];
        } catch (Throwable $e) {
            report($e);

            return [
                'ready' => false,
                'message' => '',
                'question' => '',
                'tool_calls' => [],
                'approver' => null,
                'agents' => [],
                'usage' => ['prompt_tokens' => 0, 'completion_tokens' => 0, 'cost_usd' => 0.0, 'fal_calls' => 0, 'calls_with_cost' => 0],
                'runtime' => 'sk',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Campaign tease via CaptionWriter ↔ CaptionApprover (+ identity consult).
     *
     * @return array{
     *   title: string,
     *   caption: string,
     *   hashtags: list<string>,
     *   image_prompt: string,
     *   approved: bool,
     *   needs_owner_edit: bool,
     *   agents: array<string, mixed>,
     *   approver: ?array<string, mixed>,
     *   usage: array{prompt_tokens: int, completion_tokens: int, cost_usd: float, fal_calls: int, calls_with_cost: int},
     *   runtime: string,
     *   error?: string
     * }
     */
    public function campaignTease(
        Business $business,
        User $actor,
        string $focus,
        string $platform = 'facebook',
        string $kind = 'post',
        ?string $llmModel = null,
    ): array {
        try {
            $hardRules = app(\App\Services\Agents\BehaviorRulesPrompt::class)->block($business);

            $response = Http::timeout((int) config('ai_runtime.timeout', 200))
                ->withHeaders($this->headers($business, $actor->id))
                ->post(config('ai_runtime.url').'/v1/campaigns/tease', [
                    'focus' => $focus,
                    'platform' => $platform,
                    'kind' => $kind,
                    'llm_model' => $this->resolveProviderModel($llmModel),
                    'hard_business_rules' => $hardRules,
                ]);

            if (! $response->successful()) {
                throw new RuntimeException('SK campaign tease HTTP '.$response->status().': '.$response->body());
            }

            $data = $response->json();
            if (! is_array($data)) {
                throw new RuntimeException('SK campaign tease returned non-JSON');
            }

            $usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];
            $tags = [];
            if (is_array($data['hashtags'] ?? null)) {
                foreach ($data['hashtags'] as $tag) {
                    $tag = trim((string) $tag);
                    if ($tag !== '') {
                        $tags[] = str_starts_with($tag, '#') ? $tag : '#'.$tag;
                    }
                }
            }

            return [
                'title' => self::asText($data['title'] ?? ''),
                'caption' => self::asText($data['caption'] ?? ''),
                'hashtags' => array_values(array_slice($tags, 0, 5)),
                'image_prompt' => self::asText($data['image_prompt'] ?? ''),
                'approved' => (bool) ($data['approved'] ?? false),
                'needs_owner_edit' => (bool) ($data['needs_owner_edit'] ?? false),
                'agents' => is_array($data['agents'] ?? null) ? $data['agents'] : [],
                'approver' => is_array($data['approver'] ?? null) ? $data['approver'] : null,
                'usage' => [
                    'prompt_tokens' => (int) ($usage['prompt_tokens'] ?? 0),
                    'completion_tokens' => (int) ($usage['completion_tokens'] ?? 0),
                    'cost_usd' => (float) ($usage['cost_usd'] ?? 0),
                    'fal_calls' => (int) ($usage['fal_calls'] ?? 0),
                    'calls_with_cost' => (int) ($usage['calls_with_cost'] ?? 0),
                ],
                'runtime' => self::asText($data['runtime'] ?? 'sk') ?: 'sk',
                'error' => isset($data['error']) ? self::asText($data['error']) : null,
            ];
        } catch (ConnectionException $e) {
            report($e);

            return [
                'title' => '',
                'caption' => '',
                'hashtags' => [],
                'image_prompt' => '',
                'approved' => false,
                'needs_owner_edit' => true,
                'agents' => [],
                'approver' => null,
                'usage' => ['prompt_tokens' => 0, 'completion_tokens' => 0, 'cost_usd' => 0.0, 'fal_calls' => 0, 'calls_with_cost' => 0],
                'runtime' => 'sk',
                'error' => 'AI runtime unreachable: '.$e->getMessage(),
            ];
        } catch (Throwable $e) {
            report($e);

            return [
                'title' => '',
                'caption' => '',
                'hashtags' => [],
                'image_prompt' => '',
                'approved' => false,
                'needs_owner_edit' => true,
                'agents' => [],
                'approver' => null,
                'usage' => ['prompt_tokens' => 0, 'completion_tokens' => 0, 'cost_usd' => 0.0, 'fal_calls' => 0, 'calls_with_cost' => 0],
                'runtime' => 'sk',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Enhance a rejected campaign caption (Telegram Regenerate) via PostEnhancerAgent.
     *
     * @param  list<string>  $previousHashtags
     * @return array{
     *   title: string,
     *   caption: string,
     *   hashtags: list<string>,
     *   image_prompt: string,
     *   improvement_notes: string,
     *   agents: array<string, mixed>,
     *   usage: array{prompt_tokens: int, completion_tokens: int, cost_usd: float, fal_calls: int, calls_with_cost: int},
     *   runtime: string,
     *   error?: string
     * }
     */
    public function campaignEnhance(
        Business $business,
        User $actor,
        string $previousCaption,
        string $previousTitle = '',
        array $previousHashtags = [],
        string $focus = '',
        string $platform = 'facebook',
        string $kind = 'post',
        string $contentMode = 'product_images',
        ?string $llmModel = null,
        string $forbiddenHooks = '',
        string $slotIdea = '',
        string $slotOffer = '',
    ): array {
        try {
            $hardRules = app(\App\Services\Agents\BehaviorRulesPrompt::class)->block($business);

            $response = Http::timeout((int) config('ai_runtime.timeout', 200))
                ->withHeaders($this->headers($business, $actor->id))
                ->post(config('ai_runtime.url').'/v1/campaigns/enhance', [
                    'previous_caption' => $previousCaption,
                    'previous_title' => $previousTitle,
                    'previous_hashtags' => array_values($previousHashtags),
                    'focus' => $focus,
                    'platform' => $platform,
                    'kind' => $kind,
                    'content_mode' => $contentMode,
                    'llm_model' => $this->resolveProviderModel($llmModel),
                    'forbidden_hooks' => $forbiddenHooks,
                    'slot_idea' => $slotIdea,
                    'slot_offer' => $slotOffer,
                    'hard_business_rules' => $hardRules,
                ]);

            if (! $response->successful()) {
                throw new RuntimeException('SK campaign enhance HTTP '.$response->status().': '.$response->body());
            }

            $data = $response->json();
            if (! is_array($data)) {
                throw new RuntimeException('SK campaign enhance returned non-JSON');
            }

            $usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];
            $tags = [];
            if (is_array($data['hashtags'] ?? null)) {
                foreach ($data['hashtags'] as $tag) {
                    $tag = trim((string) $tag);
                    if ($tag !== '') {
                        $tags[] = str_starts_with($tag, '#') ? $tag : '#'.$tag;
                    }
                }
            }

            return [
                'title' => self::asText($data['title'] ?? ''),
                'caption' => self::asText($data['caption'] ?? ''),
                'hashtags' => array_values(array_slice($tags, 0, 5)),
                'image_prompt' => self::asText($data['image_prompt'] ?? ''),
                'improvement_notes' => self::asText($data['improvement_notes'] ?? ''),
                'agents' => is_array($data['agents'] ?? null) ? $data['agents'] : [],
                'usage' => [
                    'prompt_tokens' => (int) ($usage['prompt_tokens'] ?? 0),
                    'completion_tokens' => (int) ($usage['completion_tokens'] ?? 0),
                    'cost_usd' => (float) ($usage['cost_usd'] ?? 0),
                    'fal_calls' => (int) ($usage['fal_calls'] ?? 0),
                    'calls_with_cost' => (int) ($usage['calls_with_cost'] ?? 0),
                ],
                'runtime' => self::asText($data['runtime'] ?? 'sk') ?: 'sk',
                'error' => isset($data['error']) ? self::asText($data['error']) : null,
            ];
        } catch (ConnectionException $e) {
            report($e);

            return [
                'title' => '',
                'caption' => '',
                'hashtags' => [],
                'image_prompt' => '',
                'improvement_notes' => '',
                'agents' => [],
                'usage' => ['prompt_tokens' => 0, 'completion_tokens' => 0, 'cost_usd' => 0.0, 'fal_calls' => 0, 'calls_with_cost' => 0],
                'runtime' => 'sk',
                'error' => 'AI runtime unreachable: '.$e->getMessage(),
            ];
        } catch (Throwable $e) {
            report($e);

            return [
                'title' => '',
                'caption' => '',
                'hashtags' => [],
                'image_prompt' => '',
                'improvement_notes' => '',
                'agents' => [],
                'usage' => ['prompt_tokens' => 0, 'completion_tokens' => 0, 'cost_usd' => 0.0, 'fal_calls' => 0, 'calls_with_cost' => 0],
                'runtime' => 'sk',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Assign distinct ideas/offers to campaign slots at launch.
     *
     * @param  list<array{slot_id: int, kind?: string, day_index?: int, asset_id?: int|null}>  $slots
     * @param  list<array<string, mixed>>  $imageAnalyses
     * @return array{slots: list<array<string, mixed>>, usage: array<string, mixed>, runtime: string, error?: string|null}
     */
    public function planCampaignSlots(
        Business $business,
        User $actor,
        array $slots,
        array $imageAnalyses = [],
        string $understanding = '',
        string $productFocus = '',
        ?string $llmModel = null,
        string $hardBusinessRules = '',
        string $processDecisions = '',
    ): array {
        try {
            $response = Http::timeout((int) config('ai_runtime.timeout', 200))
                ->withHeaders($this->headers($business, $actor->id))
                ->post(config('ai_runtime.url').'/v1/campaigns/plan_slots', [
                    'slots' => array_values($slots),
                    'image_analyses' => array_values($imageAnalyses),
                    'understanding' => $understanding,
                    'product_focus' => $productFocus,
                    'llm_model' => $this->resolveProviderModel($llmModel),
                    'hard_business_rules' => $hardBusinessRules,
                    'process_decisions' => $processDecisions,
                ]);

            if (! $response->successful()) {
                throw new RuntimeException('SK plan_slots HTTP '.$response->status().': '.$response->body());
            }
            $data = $response->json();
            if (! is_array($data)) {
                throw new RuntimeException('SK plan_slots returned non-JSON');
            }
            $usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];

            return [
                'slots' => is_array($data['slots'] ?? null) ? $data['slots'] : [],
                'agents' => is_array($data['agents'] ?? null) ? $data['agents'] : [],
                'usage' => [
                    'prompt_tokens' => (int) ($usage['prompt_tokens'] ?? 0),
                    'completion_tokens' => (int) ($usage['completion_tokens'] ?? 0),
                    'cost_usd' => (float) ($usage['cost_usd'] ?? 0),
                    'fal_calls' => (int) ($usage['fal_calls'] ?? 0),
                    'calls_with_cost' => (int) ($usage['calls_with_cost'] ?? 0),
                ],
                'runtime' => self::asText($data['runtime'] ?? 'sk') ?: 'sk',
                'error' => isset($data['error']) ? self::asText($data['error']) : null,
            ];
        } catch (Throwable $e) {
            report($e);

            return [
                'slots' => [],
                'agents' => [],
                'usage' => ['prompt_tokens' => 0, 'completion_tokens' => 0, 'cost_usd' => 0.0, 'fal_calls' => 0, 'calls_with_cost' => 0],
                'runtime' => 'sk',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Draft one campaign slot via runtime RAG (identity + memories).
     *
     * @return array{
     *   title: string,
     *   caption: string,
     *   hashtags: list<string>,
     *   image_prompt: string,
     *   agents: array<string, mixed>,
     *   usage: array{prompt_tokens: int, completion_tokens: int, cost_usd: float, fal_calls: int, calls_with_cost: int},
     *   runtime: string,
     *   error?: string|null
     * }
     */
    public function draftCampaignSlot(
        Business $business,
        User $actor,
        string $focus,
        string $platform = 'facebook',
        string $kind = 'post',
        string $contentMode = 'product_images',
        string $slotIdea = '',
        string $slotOffer = '',
        string $slotAngle = '',
        string $forbiddenHooks = '',
        ?string $imageDataUrl = null,
        string $imageDescription = '',
        ?string $llmModel = null,
    ): array {
        try {
            $hardRules = app(\App\Services\Agents\BehaviorRulesPrompt::class)->block($business);

            $response = Http::timeout((int) config('ai_runtime.timeout', 200))
                ->withHeaders($this->headers($business, $actor->id))
                ->post(config('ai_runtime.url').'/v1/campaigns/draft_slot', [
                    'focus' => $focus,
                    'platform' => $platform,
                    'kind' => $kind,
                    'content_mode' => $contentMode,
                    'slot_idea' => $slotIdea,
                    'slot_offer' => $slotOffer,
                    'slot_angle' => $slotAngle,
                    'forbidden_hooks' => $forbiddenHooks,
                    'image_data_url' => $imageDataUrl,
                    'image_description' => $imageDescription,
                    'llm_model' => $this->resolveProviderModel($llmModel),
                    'hard_business_rules' => $hardRules,
                ]);

            if (! $response->successful()) {
                throw new RuntimeException('SK draft_slot HTTP '.$response->status().': '.$response->body());
            }
            $data = $response->json();
            if (! is_array($data)) {
                throw new RuntimeException('SK draft_slot returned non-JSON');
            }
            $usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];
            $tags = [];
            if (is_array($data['hashtags'] ?? null)) {
                foreach ($data['hashtags'] as $tag) {
                    $tag = trim((string) $tag);
                    if ($tag !== '') {
                        $tags[] = str_starts_with($tag, '#') ? $tag : '#'.$tag;
                    }
                }
            }

            return [
                'title' => self::asText($data['title'] ?? ''),
                'caption' => self::asText($data['caption'] ?? ''),
                'hashtags' => array_values(array_slice($tags, 0, 5)),
                'image_prompt' => self::asText($data['image_prompt'] ?? ''),
                'agents' => is_array($data['agents'] ?? null) ? $data['agents'] : [],
                'usage' => [
                    'prompt_tokens' => (int) ($usage['prompt_tokens'] ?? 0),
                    'completion_tokens' => (int) ($usage['completion_tokens'] ?? 0),
                    'cost_usd' => (float) ($usage['cost_usd'] ?? 0),
                    'fal_calls' => (int) ($usage['fal_calls'] ?? 0),
                    'calls_with_cost' => (int) ($usage['calls_with_cost'] ?? 0),
                ],
                'runtime' => self::asText($data['runtime'] ?? 'sk') ?: 'sk',
                'error' => isset($data['error']) ? self::asText($data['error']) : null,
            ];
        } catch (Throwable $e) {
            report($e);

            return [
                'title' => '',
                'caption' => '',
                'hashtags' => [],
                'image_prompt' => '',
                'agents' => [],
                'usage' => ['prompt_tokens' => 0, 'completion_tokens' => 0, 'cost_usd' => 0.0, 'fal_calls' => 0, 'calls_with_cost' => 0],
                'runtime' => 'sk',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * PostCrafter vision + identity campaign plan / tease.
     *
     * @param  list<array{url: string, label?: string}>  $imageUrls
     * @param  list<int>  $channelIds
     * @return array{
     *   title: string,
     *   caption: string,
     *   hashtags: list<string>,
     *   image_prompt: string,
     *   analysis: string,
     *   plan_notes: string,
     *   product_focus: string,
     *   multi_product: bool,
     *   plan_meta: array<string, mixed>,
     *   approved: bool,
     *   needs_owner_edit: bool,
     *   agents: array<string, mixed>,
     *   approver: ?array<string, mixed>,
     *   usage: array{prompt_tokens: int, completion_tokens: int, cost_usd: float, fal_calls: int, calls_with_cost: int},
     *   runtime: string,
     *   error?: string
     * }
     */
    public function postCrafterPlan(
        Business $business,
        User $actor,
        string $focus,
        string $contentMode = 'ai_recent',
        string $platform = 'facebook',
        string $kind = 'post',
        array $imageUrls = [],
        array $channelIds = [],
        string $briefNotes = '',
        ?string $llmModel = null,
    ): array {
        $empty = [
            'title' => '',
            'caption' => '',
            'hashtags' => [],
            'image_prompt' => '',
            'analysis' => '',
            'plan_notes' => '',
            'product_focus' => '',
            'multi_product' => false,
            'plan_meta' => [],
            'approved' => false,
            'needs_owner_edit' => true,
            'agents' => [],
            'approver' => null,
            'usage' => ['prompt_tokens' => 0, 'completion_tokens' => 0, 'cost_usd' => 0.0, 'fal_calls' => 0, 'calls_with_cost' => 0],
            'runtime' => 'sk',
            'error' => null,
        ];

        try {
            $response = Http::timeout((int) config('ai_runtime.timeout', 200))
                ->withHeaders($this->headers($business, $actor->id))
                ->post(config('ai_runtime.url').'/v1/post_crafter/plan', [
                    'focus' => $focus,
                    'brief_notes' => $briefNotes,
                    'content_mode' => $contentMode,
                    'platform' => $platform,
                    'kind' => $kind,
                    'image_urls' => array_values(array_map(fn ($row) => [
                        'url' => (string) ($row['url'] ?? ''),
                        'label' => (string) ($row['label'] ?? ''),
                    ], $imageUrls)),
                    'channel_ids' => array_values(array_map('intval', $channelIds)),
                    'llm_model' => $this->resolveProviderModel($llmModel),
                ]);

            if (! $response->successful()) {
                throw new RuntimeException('SK post_crafter HTTP '.$response->status().': '.$response->body());
            }

            $data = $response->json();
            if (! is_array($data)) {
                throw new RuntimeException('SK post_crafter returned non-JSON');
            }

            $usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];
            $tags = [];
            if (is_array($data['hashtags'] ?? null)) {
                foreach ($data['hashtags'] as $tag) {
                    $tag = trim((string) $tag);
                    if ($tag !== '') {
                        $tags[] = str_starts_with($tag, '#') ? $tag : '#'.$tag;
                    }
                }
            }

            $caption = self::asText($data['tease_caption'] ?? ($data['caption'] ?? ''));

            return [
                'title' => self::asText($data['title'] ?? ''),
                'caption' => $caption,
                'hashtags' => array_values(array_slice($tags, 0, 5)),
                'image_prompt' => self::asText($data['image_prompt'] ?? ''),
                'analysis' => self::asText($data['analysis'] ?? ''),
                'plan_notes' => self::asText($data['plan_notes'] ?? ''),
                'product_focus' => self::asText($data['product_focus'] ?? ''),
                'multi_product' => (bool) ($data['multi_product'] ?? false),
                'plan_meta' => is_array($data['plan_meta'] ?? null) ? $data['plan_meta'] : [],
                'approved' => (bool) ($data['approved'] ?? false),
                'needs_owner_edit' => (bool) ($data['needs_owner_edit'] ?? false),
                'agents' => is_array($data['agents'] ?? null) ? $data['agents'] : [],
                'approver' => is_array($data['approver'] ?? null) ? $data['approver'] : null,
                'usage' => [
                    'prompt_tokens' => (int) ($usage['prompt_tokens'] ?? 0),
                    'completion_tokens' => (int) ($usage['completion_tokens'] ?? 0),
                    'cost_usd' => (float) ($usage['cost_usd'] ?? 0),
                    'fal_calls' => (int) ($usage['fal_calls'] ?? 0),
                    'calls_with_cost' => (int) ($usage['calls_with_cost'] ?? 0),
                ],
                'runtime' => self::asText($data['runtime'] ?? 'sk') ?: 'sk',
                'error' => isset($data['error']) ? self::asText($data['error']) : null,
            ];
        } catch (ConnectionException $e) {
            report($e);
            $empty['error'] = 'AI runtime unreachable: '.$e->getMessage();

            return $empty;
        } catch (Throwable $e) {
            report($e);
            $empty['error'] = $e->getMessage();

            return $empty;
        }
    }

    /**
     * Cheap vision-only understand (no tease generation).
     *
     * @param  list<array{url: string, label?: string}>  $imageUrls
     * @return array{
     *   summary: string,
     *   product_focus: string,
     *   multi_product: bool,
     *   image_analyses: list<array<string, mixed>>,
     *   usage: array{prompt_tokens: int, completion_tokens: int, cost_usd: float, fal_calls: int, calls_with_cost: int},
     *   runtime: string,
     *   error?: string
     * }
     */
    public function postCrafterUnderstand(
        Business $business,
        User $actor,
        array $imageUrls = [],
        string $focus = '',
        string $contentMode = 'product_images',
        ?string $llmModel = null,
        string $replyLanguage = '',
    ): array {
        $empty = [
            'summary' => '',
            'product_focus' => '',
            'multi_product' => false,
            'image_analyses' => [],
            'claims' => [],
            'process_options' => [],
            'usage' => ['prompt_tokens' => 0, 'completion_tokens' => 0, 'cost_usd' => 0.0, 'fal_calls' => 0, 'calls_with_cost' => 0],
            'runtime' => 'sk',
            'error' => null,
        ];

        try {
            $response = Http::timeout((int) config('ai_runtime.timeout', 200))
                ->withHeaders($this->headers($business, $actor->id))
                ->post(config('ai_runtime.url').'/v1/post_crafter/understand', [
                    'focus' => $focus,
                    'content_mode' => $contentMode,
                    'image_urls' => array_values(array_map(fn ($row) => [
                        'url' => (string) ($row['url'] ?? ''),
                        'label' => (string) ($row['label'] ?? ''),
                    ], $imageUrls)),
                    'llm_model' => $this->resolveProviderModel($llmModel),
                    'reply_language' => $replyLanguage,
                ]);

            if (! $response->successful()) {
                throw new RuntimeException('SK post_crafter understand HTTP '.$response->status().': '.$response->body());
            }

            $data = $response->json();
            if (! is_array($data)) {
                throw new RuntimeException('SK post_crafter understand returned non-JSON');
            }

            $usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];

            return [
                'summary' => self::asText($data['summary'] ?? ''),
                'product_focus' => self::asText($data['product_focus'] ?? ''),
                'multi_product' => (bool) ($data['multi_product'] ?? false),
                'image_analyses' => is_array($data['image_analyses'] ?? null) ? $data['image_analyses'] : [],
                'claims' => is_array($data['claims'] ?? null) ? $data['claims'] : [],
                'process_options' => is_array($data['process_options'] ?? null) ? $data['process_options'] : [],
                'usage' => [
                    'prompt_tokens' => (int) ($usage['prompt_tokens'] ?? 0),
                    'completion_tokens' => (int) ($usage['completion_tokens'] ?? 0),
                    'cost_usd' => (float) ($usage['cost_usd'] ?? 0),
                    'fal_calls' => (int) ($usage['fal_calls'] ?? 0),
                    'calls_with_cost' => (int) ($usage['calls_with_cost'] ?? 0),
                ],
                'runtime' => self::asText($data['runtime'] ?? 'sk') ?: 'sk',
                'error' => isset($data['error']) ? self::asText($data['error']) : null,
            ];
        } catch (ConnectionException $e) {
            report($e);
            $empty['error'] = 'AI runtime unreachable: '.$e->getMessage();

            return $empty;
        } catch (Throwable $e) {
            report($e);
            $empty['error'] = $e->getMessage();

            return $empty;
        }
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    private function ownerHistory(Business $business, ?int $agentChatId): array
    {
        try {
            $query = AgentChatMessage::query()
                ->where('business_id', $business->id)
                ->orderByDesc('id')
                ->limit(30);
            if ($agentChatId) {
                $query->where('agent_chat_id', $agentChatId);
            }

            return $query->get()->reverse()->values()
                ->filter(fn (AgentChatMessage $row) => in_array($row->role, ['user', 'assistant'], true))
                ->map(fn (AgentChatMessage $row) => [
                    'role' => $row->role,
                    'content' => (string) $row->content,
                ])
                ->values()
                ->all();
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function post(string $path, Business $business, array $payload, ?int $userId): array
    {
        try {
            $response = Http::timeout((int) config('ai_runtime.timeout', 200))
                ->withHeaders($this->headers($business, $userId))
                ->post(config('ai_runtime.url').$path, $payload);

            if (! $response->successful()) {
                throw new RuntimeException('SK runtime HTTP '.$response->status().': '.$response->body());
            }

            $data = $response->json();
            if (! is_array($data)) {
                throw new RuntimeException('SK runtime returned non-JSON body');
            }

            return $this->normalizeOwnerish($data);
        } catch (ConnectionException $e) {
            report($e);

            return $this->failure('AI runtime unreachable: '.$e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return $this->failure($e->getMessage());
        }
    }

    /**
     * Localize owner-facing strings via TranslationAgent (Gemini) + shop language settings.
     *
     * @param  array<string, string>  $texts
     * @return array{texts: array<string, string>, language: string, label: string, usage: array<string, mixed>, agents: array<string, mixed>, error?: string|null}
     */
    public function translateOwnerTexts(
        Business $business,
        User $actor,
        array $texts,
        string $languageHint = '',
        ?string $llmModel = null,
    ): array {
        $empty = [
            'texts' => $texts,
            'language' => $languageHint,
            'label' => '',
            'usage' => ['prompt_tokens' => 0, 'completion_tokens' => 0, 'cost_usd' => 0.0, 'fal_calls' => 0, 'calls_with_cost' => 0],
            'agents' => ['translator' => 'TranslationAgent'],
            'error' => null,
        ];
        $clean = [];
        foreach ($texts as $key => $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                $clean[(string) $key] = $value;
            }
        }
        if ($clean === []) {
            return $empty;
        }

        try {
            $response = Http::timeout(60)
                ->connectTimeout(5)
                ->withHeaders($this->headers($business, $actor->id))
                ->post(config('ai_runtime.url').'/v1/translation/translate', [
                    'texts' => $clean,
                    'language_hint' => $languageHint,
                    'llm_model' => $this->resolveProviderModel($llmModel),
                ]);

            if (! $response->successful()) {
                throw new RuntimeException('SK translation HTTP '.$response->status().': '.$response->body());
            }

            $data = $response->json();
            if (! is_array($data)) {
                throw new RuntimeException('SK translation returned non-JSON');
            }

            $outTexts = is_array($data['texts'] ?? null) ? $data['texts'] : [];
            $merged = $clean;
            foreach ($clean as $key => $original) {
                $candidate = trim((string) ($outTexts[$key] ?? ''));
                $merged[$key] = $candidate !== '' ? $candidate : $original;
            }
            $usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];

            return [
                'texts' => $merged,
                'language' => self::asText($data['language'] ?? $languageHint),
                'label' => self::asText($data['label'] ?? ''),
                'usage' => [
                    'prompt_tokens' => (int) ($usage['prompt_tokens'] ?? 0),
                    'completion_tokens' => (int) ($usage['completion_tokens'] ?? 0),
                    'cost_usd' => (float) ($usage['cost_usd'] ?? 0),
                    'fal_calls' => (int) ($usage['fal_calls'] ?? 0),
                    'calls_with_cost' => (int) ($usage['calls_with_cost'] ?? 0),
                ],
                'agents' => is_array($data['agents'] ?? null) ? $data['agents'] : ['translator' => 'TranslationAgent'],
                'error' => isset($data['error']) ? self::asText($data['error']) : null,
            ];
        } catch (ConnectionException $e) {
            report($e);
            $empty['error'] = 'AI runtime unreachable: '.$e->getMessage();

            return $empty;
        } catch (Throwable $e) {
            report($e);
            $empty['error'] = $e->getMessage();

            return $empty;
        }
    }

    /**
     * Map catalog keys (claude_sonnet) to OpenRouter/Fal model IDs.
     * Caller may already pass a provider id (anthropic/claude-...); leave those alone.
     */
    private function resolveProviderModel(?string $llmModel): ?string
    {
        if ($llmModel === null || trim($llmModel) === '') {
            return $llmModel;
        }
        $key = trim($llmModel);
        if (str_contains($key, '/')) {
            return $key;
        }

        return app(\App\AI\LlmModels\LlmModelCatalog::class)->resolve($key)['model'] ?? $key;
    }

    /**
     * @return array<string, string>
     */
    private function headers(Business $business, ?int $userId = null): array
    {
        $headers = [
            'Accept' => 'application/json',
            'X-Runtime-Key' => (string) config('ai_runtime.service_key'),
            'X-Business-Id' => (string) $business->id,
            'X-Correlation-Id' => (string) (request()->headers->get('X-Request-Id') ?: uniqid('sk_', true)),
        ];
        if ($userId) {
            $headers['X-User-Id'] = (string) $userId;
        }

        return $headers;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeOwnerish(array $data): array
    {
        $usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];

        return [
            'reply' => (string) ($data['reply'] ?? ''),
            'pending_action' => is_array($data['pending_action'] ?? null) ? $data['pending_action'] : null,
            'tool_calls' => is_array($data['tool_calls'] ?? null) ? $data['tool_calls'] : [],
            'generated_assets' => is_array($data['generated_assets'] ?? null) ? $data['generated_assets'] : [],
            'pending_image_jobs' => is_array($data['pending_image_jobs'] ?? null) ? $data['pending_image_jobs'] : [],
            'usage' => [
                'prompt_tokens' => (int) ($usage['prompt_tokens'] ?? 0),
                'completion_tokens' => (int) ($usage['completion_tokens'] ?? 0),
                'cost_usd' => (float) ($usage['cost_usd'] ?? 0),
                'fal_calls' => (int) ($usage['fal_calls'] ?? 0),
                'calls_with_cost' => (int) ($usage['calls_with_cost'] ?? 0),
            ],
            'citations' => is_array($data['citations'] ?? null) ? $data['citations'] : [],
            'session_id' => isset($data['session_id']) ? (string) $data['session_id'] : null,
            'runtime' => (string) ($data['runtime'] ?? 'sk'),
            'error' => isset($data['error']) ? (string) $data['error'] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function failure(string $message): array
    {
        return [
            'reply' => 'Sorry — the AI runtime is unavailable. Please try again in a moment.',
            'pending_action' => null,
            'tool_calls' => [],
            'generated_assets' => [],
            'pending_image_jobs' => [],
            'usage' => [
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'cost_usd' => 0.0,
                'fal_calls' => 0,
                'calls_with_cost' => 0,
            ],
            'error' => $message,
            'runtime' => 'sk',
        ];
    }

    /**
     * Safe cast — SK/SocialAPI payloads sometimes nest arrays where a string is expected.
     */
    private static function asText(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_string($value)) {
            return $value;
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_numeric($value)) {
            return (string) $value;
        }
        if (is_array($value)) {
            $encoded = json_encode($value, JSON_UNESCAPED_UNICODE);

            return is_string($encoded) ? $encoded : '';
        }

        return '';
    }
}
