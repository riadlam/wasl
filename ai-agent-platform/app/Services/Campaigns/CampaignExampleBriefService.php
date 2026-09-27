<?php

namespace App\Services\Campaigns;

use App\AI\Agents\AgentLoop;
use App\AI\Agents\McpToolPolicy;
use App\AI\LlmModels\LlmModelCatalog;
use App\AI\ReplyLanguage;
use App\AI\Runtime\AiRuntime;
use App\AI\Runtime\SkAgentClient;
use App\Mcp\McpContext;
use App\Models\AgentAsset;
use App\Models\AiCampaign;
use App\Models\Business;
use App\Models\Product;
use App\Models\User;
use App\Services\Wallet\AiTaskBillingService;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Short owner briefing before a campaign tease: ask only what is missing, in the shop language.
 * When AI_RUNTIME=sk, routes through CampaignBriefAgent (Owner + Identity A2A + OwnerApprover).
 */
class CampaignExampleBriefService
{
    public function __construct(
        private AgentLoop $loop,
        private McpToolPolicy $policy,
        private LlmModelCatalog $llmModels,
        private WalletService $wallets,
        private AiTaskBillingService $billing,
        private SkAgentClient $sk,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated campaign payload
     * @param  list<array{role: string, content: string}>  $messages
     * @return array{stage: string, message: string, messages: list<array{role: string, content: string}>, ready: bool}
     */
    public function turn(Business $business, User $user, array $data, array $messages = []): array
    {
        $traceId = (string) Str::uuid();
        $business->loadMissing('agentSettings');
        $modelKey = $business->agentSettings?->llm_model;
        $this->wallets->authorizeBusinessAi($business, $this->billing->chatAuthorizeDa($modelKey));

        $language = ReplyLanguage::forBusiness($business);
        $history = $this->cleanMessages($messages);
        $mode = (string) ($data['content_mode'] ?? '');

        // First product_images turn: cheap vision understand, then confirm message (no catalog tease yet).
        $understandingMeta = null;
        if ($mode === AiCampaign::MODE_PRODUCT_IMAGES && $history === [] && AiRuntime::usesSk($business)) {
            $understandingMeta = $this->runVisionUnderstand($business, $user, $data, $modelKey, $traceId);
            if (($understandingMeta['summary'] ?? '') !== '') {
                $data['_vision_understanding'] = (string) $understandingMeta['summary'];
                if (($understandingMeta['product_focus'] ?? '') !== '') {
                    $data['_vision_understanding'] .= "\nProduct focus: ".$understandingMeta['product_focus'];
                }
            }
        }

        $context = $this->contextBlock($business, $data);

        CampaignTrace::info('campaigns.brief.start', [
            'trace_id' => $traceId,
            'business_id' => $business->id,
            'user_id' => $user->id,
            'language' => $language,
            'model_key' => $modelKey,
            'history_turns' => count($history),
            'content_mode' => $data['content_mode'] ?? null,
            'focus_preview' => CampaignTrace::clip((string) ($data['focus_prompt'] ?? ''), 400),
            'incoming_roles' => array_map(fn ($m) => $m['role'] ?? '?', $history),
            'runtime' => AiRuntime::driver($business),
            'has_vision_understanding' => $understandingMeta !== null,
        ]);

        // Deterministic first-turn confirm for product_images when vision succeeded — saves a brief LLM round.
        if ($understandingMeta && ($understandingMeta['summary'] ?? '') !== '' && $history === []) {
            $summary = trim((string) $understandingMeta['summary']);
            $confirmCards = $this->buildConfirmCards($understandingMeta, $language, $mode, $data);
            $focus = trim((string) ($understandingMeta['product_focus'] ?? ''));
            // English draft — TranslationAgent localizes in finalizeTurn.
            $message = "I understand this for the tease:\n{$summary}";
            if ($focus !== '') {
                $message .= "\nFocus: {$focus}";
            }
            $message .= $confirmCards === []
                ? "\n\nNothing else to clarify — confirm to generate the sample."
                : "\n\nConfirm or deny the post-process decisions, then generate the sample.";

            $this->billing->chargeChatTurn($business, $user, $modelKey, null, $understandingMeta['usage'] ?? []);

            return $this->finalizeTurn(
                $business,
                $user,
                $data,
                $history,
                ['ready' => true, 'message' => $message, 'question' => ''],
                $language,
                $traceId,
                'post_crafter_understand',
                [
                    'tools' => [],
                    'usage' => $understandingMeta['usage'] ?? [],
                    'exhausted' => false,
                    'runtime' => 'sk',
                    'agents' => [
                        'brief' => 'PostCrafterAgent',
                        'step' => 'understand',
                        'writer' => 'CampaignCreativeAgent',
                        'approver' => 'PostCrafterApprover',
                        'translator' => 'TranslationAgent',
                    ],
                    'understanding' => $summary,
                    'product_focus' => $understandingMeta['product_focus'] ?? '',
                    'image_analyses' => $understandingMeta['image_analyses'] ?? [],
                    'confirm_cards' => $confirmCards,
                    'awaiting_accept' => true,
                ],
            );
        }

        if (AiRuntime::usesSk($business)) {
            $out = $this->turnViaSk($business, $user, $data, $history, $language, $context, $modelKey, $traceId);
        } else {
            $out = $this->turnViaPhp($business, $user, $data, $history, $language, $context, $modelKey, $traceId);
        }

        if ($understandingMeta) {
            $out['understanding'] = $understandingMeta['summary'] ?? ($out['understanding'] ?? null);
            $out['image_analyses'] = $understandingMeta['image_analyses'] ?? [];
            $out['product_focus'] = $understandingMeta['product_focus'] ?? null;
            $out['awaiting_accept'] = (bool) ($out['ready'] ?? false);
            $out['confirm_cards'] = $this->buildConfirmCards($understandingMeta, $language, $mode, $data);
        } elseif (! empty($out['ready'])) {
            $out['awaiting_accept'] = true;
            if ($mode === AiCampaign::MODE_AI_RECENT) {
                $focus = trim((string) ($data['focus_prompt'] ?? ''));
                $out['understanding'] = $focus !== '' ? $focus : ($out['understanding'] ?? $out['message'] ?? null);
            }
            $out['confirm_cards'] = $this->buildConfirmCards(
                [
                    'summary' => (string) ($out['understanding'] ?? ''),
                    'product_focus' => (string) ($out['product_focus'] ?? ''),
                    'claims' => [],
                    'process_options' => [],
                ],
                $language,
                $mode,
                $data,
            );
        }

        return $out;
    }

    /**
     * Process decision cards only (vision understanding is trusted — no Confirm/Deny on image facts).
     * Language/tone come from shop settings; never invent Darija/French cards here.
     *
     * @param  array<string, mixed>  $understandingMeta
     * @param  array<string, mixed>  $data
     * @return list<array{id: string, text: string, group: string, required: bool, default_on: bool}>
     */
    private function buildConfirmCards(array $understandingMeta, string $language, string $mode, array $data): array
    {
        $cards = [];
        $process = is_array($understandingMeta['process_options'] ?? null) ? $understandingMeta['process_options'] : [];
        foreach ($process as $i => $opt) {
            if (! is_array($opt)) {
                continue;
            }
            $text = trim((string) ($opt['text'] ?? $opt['label'] ?? ''));
            if ($text === '') {
                continue;
            }
            // Never surface language / naive CTA cards even if a model slips them through.
            if (preg_match('/darija|fran[cç]ais|french|shop energy|call to action|\bcta\b|reply language/iu', $text)) {
                continue;
            }
            $cards[] = [
                'id' => (string) ($opt['id'] ?? 'process_'.($i + 1)),
                'text' => mb_substr($text, 0, 180),
                'group' => 'process',
                'required' => true,
                'default_on' => (bool) ($opt['default_on'] ?? false),
            ];
        }

        return $cards;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{summary: string, product_focus: string, multi_product: bool, image_analyses: list<array<string, mixed>>, usage: array<string, mixed>}|null
     */
    private function runVisionUnderstand(
        Business $business,
        User $user,
        array $data,
        ?string $modelKey,
        string $traceId,
    ): ?array {
        $ids = array_values(array_map('intval', $data['asset_ids'] ?? []));
        if ($ids === []) {
            return null;
        }
        $assets = AgentAsset::query()->forBusiness($business->id)->whereIn('id', $ids)->get();
        $imageUrls = [];
        foreach ($assets as $asset) {
            try {
                // Prefer inline data URL — remote LLMs cannot reliably fetch ngrok/LAN storage URLs
                // (free ngrok interstitial + slow fetch causes PHP max_execution_time hangs).
                $url = $asset->dataUrl() ?: $asset->absoluteUrl();
                if ($url === '') {
                    continue;
                }
                $imageUrls[] = [
                    'url' => $url,
                    'label' => 'asset_'.$asset->id,
                ];
            } catch (\Throwable) {
                continue;
            }
        }
        if ($imageUrls === []) {
            return null;
        }

        $resolved = $this->llmModels->resolve($modelKey);
        $result = $this->sk->postCrafterUnderstand(
            $business,
            $user,
            $imageUrls,
            trim((string) ($data['focus_prompt'] ?? '')),
            AiCampaign::MODE_PRODUCT_IMAGES,
            $resolved['model'] ?? null,
            ReplyLanguage::forBusiness($business),
        );

        if (! empty($result['error']) && trim((string) ($result['summary'] ?? '')) === '') {
            CampaignTrace::warning('campaigns.brief.vision_understand_failed', [
                'trace_id' => $traceId,
                'business_id' => $business->id,
                'error' => $result['error'],
            ]);

            return null;
        }

        return $result;
    }

    /**
     * @param  list<array{role: string, content: string}>  $history
     * @return array<string, mixed>
     */
    private function turnViaSk(
        Business $business,
        User $user,
        array $data,
        array $history,
        string $language,
        string $context,
        ?string $modelKey,
        string $traceId,
    ): array {
        $model = $this->llmModels->resolve($modelKey);
        $sk = $this->sk->campaignBrief(
            $business,
            $user,
            $context."\nDraft all owner-facing message/question fields in English. "
                ."TranslationAgent will localize to the shop reply language after this turn.",
            $language,
            $history,
            $model['model'] ?? null,
        );

        if (! empty($sk['error']) && trim((string) (is_scalar($sk['message'] ?? null) ? $sk['message'] : '')) === '') {
            CampaignTrace::warning('campaigns.brief.sk_fallback_php', [
                'trace_id' => $traceId,
                'business_id' => $business->id,
                'error' => $sk['error'],
            ]);

            // Don't 500 the UI when SocialAPI/runtime hangs — finish via PHP brief path.
            return $this->turnViaPhp($business, $user, $data, $history, $language, $context, $modelKey, $traceId);
        }

        $this->billing->chargeChatTurn($business, $user, $modelKey, null, $sk['usage'] ?? []);

        $parsed = [
            'ready' => (bool) ($sk['ready'] ?? false),
            'message' => $this->asText($sk['message'] ?? ''),
            'question' => $this->asText($sk['question'] ?? ''),
        ];

        return $this->finalizeTurn(
            $business,
            $user,
            $data,
            $history,
            $parsed,
            $language,
            $traceId,
            $model['model'] ?? 'sk',
            [
                'tools' => $sk['tool_calls'] ?? [],
                'usage' => $sk['usage'] ?? [],
                'exhausted' => false,
                'runtime' => 'sk',
                'approver' => $sk['approver'] ?? null,
                'agents' => $sk['agents'] ?? [
                    'brief' => 'CampaignBriefAgent',
                    'identity' => 'BusinessIdentityAgent',
                    'approver' => 'OwnerApprover',
                    'writer' => 'CampaignCreativeAgent',
                    'caption_approver' => 'CaptionApprover',
                    'translator' => 'TranslationAgent',
                ],
            ],
        );
    }

    /**
     * @param  list<array{role: string, content: string}>  $history
     * @return array<string, mixed>
     */
    private function turnViaPhp(
        Business $business,
        User $user,
        array $data,
        array $history,
        string $language,
        string $context,
        ?string $modelKey,
        string $traceId,
    ): array {
        $langBlock = ReplyLanguage::instruction($language);
        $system = <<<TEXT
You are Wasl's campaign brief assistant for this shop. You are not writing the post yet — you gather facts so the caption writer never invents product mechanics.
{$langBlock}
{$context}

Rules:
- Chat naturally: short messages (1–3 sentences). Friendly senior SMM tone.
- Prefer ONE primary script (Arabic Darija OR Latin). Do not mix Latin words inside Arabic sentences.
- Ask ONE clear question at a time when something important is missing.
- In product_images mode: if VISION UNDERSTANDING is present, echo ONLY those products. Never invent catalog SKUs.
- Ready=true ONLY when understanding of images/focus is clear enough for a SAMPLE tease (owner will Accept next).
- Never invent prices, stock, or offer mechanics yourself.
- Max 3 questions in the whole briefing.
- When ready=true, question must be empty and message restates understanding and asks the owner to confirm before generating the sample.
- Reply with JSON only: {"ready":false,"message":"...","question":"..."} or {"ready":true,"message":"...","question":""}
TEXT;

        $llmMessages = [['role' => 'system', 'content' => $system]];
        if ($history === []) {
            $llmMessages[] = ['role' => 'user', 'content' => 'Start the briefing. Greet briefly and ask the most important missing question, or say you are ready if the brief already names a clear product/angle with enough facts.'];
        } else {
            foreach ($history as $row) {
                $llmMessages[] = ['role' => $row['role'], 'content' => $row['content']];
            }
            $llmMessages[] = ['role' => 'user', 'content' => 'Continue the briefing chat. Ask the next missing question, or set ready=true if you can generate an honest tease now. JSON only.'];
        }

        $model = $this->llmModels->resolve($modelKey);
        try {
            $loop = $this->loop->run(
                $business,
                $llmMessages,
                $this->policy->for(McpContext::SURFACE_CAMPAIGN, $business),
                [],
                ['model' => $model['model'], 'temperature' => 0.35, 'max_tokens' => 400, 'timeout' => 45],
                null,
                4,
            );
        } catch (\Throwable $e) {
            CampaignTrace::error('campaigns.brief.llm_failed', [
                'trace_id' => $traceId,
                'business_id' => $business->id,
                'model' => $model['model'] ?? null,
            ], $e);
            throw $e;
        }
        $this->billing->chargeChatTurn($business, $user, $modelKey, null, $loop->usage);

        return $this->finalizeTurn(
            $business,
            $user,
            $data,
            $history,
            $this->parse($loop->text),
            $language,
            $traceId,
            $model['model'],
            [
                'tools' => $loop->toolLog,
                'usage' => $loop->usage,
                'exhausted' => $loop->exhausted ?? false,
                'runtime' => 'php',
                'raw' => (string) $loop->text,
                'agents' => [
                    'brief' => 'CampaignBriefAgent',
                    'writer' => 'CampaignCreativeAgent',
                    'approver' => 'CaptionApprover',
                    'translator' => 'TranslationAgent',
                ],
            ],
        );
    }

    /**
     * @param  list<array{role: string, content: string}>  $history
     * @param  array{ready: bool, message: string, question: string}  $parsed
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function finalizeTurn(
        Business $business,
        User $user,
        array $data,
        array $history,
        array $parsed,
        string $language,
        string $traceId,
        string $modelName,
        array $meta,
    ): array {
        $assistantQuestions = count(array_filter($history, fn ($m) => ($m['role'] ?? '') === 'assistant'));
        $userAnswers = count(array_filter($history, fn ($m) => ($m['role'] ?? '') === 'user'));
        $assistantQuestionsAfter = $assistantQuestions + 1;

        $forcedReady = false;
        $maxQuestions = 3;
        $focus = trim((string) ($data['focus_prompt'] ?? ''));
        $focusRich = mb_strlen($focus) >= 24;

        if (! $parsed['ready']) {
            if ($assistantQuestionsAfter >= $maxQuestions) {
                $forcedReady = true;
            } elseif ($userAnswers >= 2) {
                $forcedReady = true;
            } elseif ($focusRich && $userAnswers >= 1) {
                $forcedReady = true;
            } elseif ($history === [] && $this->looksLikeEnoughFocus($focus)) {
                $forcedReady = true;
            } elseif ($focusRich && $assistantQuestionsAfter >= 2 && $userAnswers >= 1) {
                $forcedReady = true;
            }
        }

        if ($parsed['ready'] && $parsed['question'] !== '') {
            $parsed['question'] = '';
        }

        if ($forcedReady) {
            $parsed['ready'] = true;
            $parsed['question'] = '';
            if ($parsed['message'] === '' || $this->looksLikeQuestion($parsed['message'])) {
                $parsed['message'] = 'Perfect — confirm my summary to generate the sample post, or correct me.';
            }
            CampaignTrace::info('campaigns.brief.forced_ready', [
                'trace_id' => $traceId,
                'business_id' => $business->id,
                'assistant_questions' => $assistantQuestionsAfter,
                'user_answers' => $userAnswers,
                'focus_rich' => $focusRich,
            ]);
        }

        // product_images: never force-ready on empty history without vision (avoids catalog invention).
        if (($data['content_mode'] ?? '') === AiCampaign::MODE_PRODUCT_IMAGES
            && $history === []
            && empty($meta['understanding'])
            && empty($data['_vision_understanding'])) {
            $parsed['ready'] = false;
            $forcedReady = false;
        }
        $assistant = trim($parsed['message'].($parsed['question'] !== '' ? "\n\n".$parsed['question'] : ''));
        if ($assistant === '') {
            CampaignTrace::error('campaigns.brief.empty_assistant', [
                'trace_id' => $traceId,
                'business_id' => $business->id,
                'raw_preview' => CampaignTrace::clip((string) ($meta['raw'] ?? '')),
                'tools' => CampaignTrace::summarizeTools($meta['tools'] ?? []),
                'exhausted' => $meta['exhausted'] ?? false,
            ]);
            throw new RuntimeException('The brief assistant did not return a message.');
        }

        $confirmCards = is_array($meta['confirm_cards'] ?? null) ? $meta['confirm_cards'] : [];
        $understanding = isset($meta['understanding']) ? trim((string) $meta['understanding']) : '';

        // Final-mile: TranslationAgent + shop language settings (Gemini). Internal drafts stay English.
        $localized = $this->localizeOwnerFacing(
            $business,
            $user,
            $language,
            $assistant,
            $understanding,
            $confirmCards,
            $traceId,
        );
        $assistant = $this->softenScriptMix($localized['message']);
        $understanding = $localized['understanding'] !== ''
            ? $this->softenScriptMix($localized['understanding'])
            : $understanding;
        $confirmCards = $localized['confirm_cards'];
        if ($localized['agents'] !== []) {
            $meta['agents'] = array_merge(
                is_array($meta['agents'] ?? null) ? $meta['agents'] : [],
                $localized['agents'],
            );
        }

        $next = $history;
        $next[] = ['role' => 'assistant', 'content' => $assistant];

        $agents = is_array($meta['agents'] ?? null) ? $meta['agents'] : [
            'brief' => 'CampaignBriefAgent',
            'writer' => 'CampaignCreativeAgent',
            'approver' => 'CaptionApprover',
            'translator' => 'TranslationAgent',
        ];

        CampaignTrace::info('campaigns.brief.turn', [
            'trace_id' => $traceId,
            'business_id' => $business->id,
            'model' => $modelName,
            'ready' => $parsed['ready'],
            'forced_ready' => $forcedReady,
            'stage' => $parsed['ready'] ? 'ready' : 'ask',
            'assistant_preview' => CampaignTrace::clip($assistant, 800),
            'tools' => CampaignTrace::summarizeTools($meta['tools'] ?? []),
            'usage' => $meta['usage'] ?? [],
            'exhausted' => $meta['exhausted'] ?? false,
            'history_after' => count($next),
            'runtime' => $meta['runtime'] ?? 'php',
            'approver' => $meta['approver'] ?? null,
            'agents' => $agents,
            'translated_language' => $localized['language'] ?? null,
        ]);

        return [
            'stage' => $parsed['ready'] ? 'ready' : 'ask',
            'ready' => $parsed['ready'],
            'message' => $assistant,
            'messages' => $next,
            'trace_id' => $traceId,
            'forced_ready' => $forcedReady,
            'runtime' => $meta['runtime'] ?? 'php',
            'approver' => $meta['approver'] ?? null,
            'agents' => $agents,
            'understanding' => $understanding !== '' ? $understanding : ($meta['understanding'] ?? null),
            'product_focus' => $meta['product_focus'] ?? null,
            'image_analyses' => is_array($meta['image_analyses'] ?? null) ? $meta['image_analyses'] : [],
            'confirm_cards' => $confirmCards,
            'awaiting_accept' => (bool) ($meta['awaiting_accept'] ?? $parsed['ready']),
        ];
    }

    /**
     * @param  list<array{id?: string, text?: string, group?: string, required?: bool, default_on?: bool}>  $confirmCards
     * @return array{message: string, understanding: string, confirm_cards: list<array<string, mixed>>, language: string, agents: array<string, mixed>}
     */
    private function localizeOwnerFacing(
        Business $business,
        User $user,
        string $language,
        string $message,
        string $understanding,
        array $confirmCards,
        string $traceId,
    ): array {
        $batch = ['message' => $message];
        if ($understanding !== '') {
            $batch['understanding'] = $understanding;
        }
        foreach ($confirmCards as $i => $card) {
            if (! is_array($card)) {
                continue;
            }
            $text = trim((string) ($card['text'] ?? ''));
            if ($text !== '') {
                $batch['card_'.$i] = $text;
            }
        }

        if (! AiRuntime::usesSk($business)) {
            return [
                'message' => $message,
                'understanding' => $understanding,
                'confirm_cards' => $confirmCards,
                'language' => $language,
                'agents' => [],
            ];
        }

        $result = $this->sk->translateOwnerTexts($business, $user, $batch, $language);
        if (! empty($result['error'])) {
            CampaignTrace::warning('campaigns.brief.translate_failed', [
                'trace_id' => $traceId,
                'business_id' => $business->id,
                'error' => $result['error'],
            ]);

            return [
                'message' => $message,
                'understanding' => $understanding,
                'confirm_cards' => $confirmCards,
                'language' => $language,
                'agents' => ['translator' => 'TranslationAgent', 'error' => $result['error']],
            ];
        }

        $texts = is_array($result['texts'] ?? null) ? $result['texts'] : [];
        $outCards = [];
        foreach ($confirmCards as $i => $card) {
            if (! is_array($card)) {
                continue;
            }
            $key = 'card_'.$i;
            if (isset($texts[$key]) && trim((string) $texts[$key]) !== '') {
                $card['text'] = trim((string) $texts[$key]);
            }
            $outCards[] = $card;
        }

        return [
            'message' => trim((string) ($texts['message'] ?? $message)) ?: $message,
            'understanding' => isset($texts['understanding'])
                ? trim((string) $texts['understanding'])
                : $understanding,
            'confirm_cards' => $outCards,
            'language' => (string) ($result['language'] ?? $language),
            'agents' => is_array($result['agents'] ?? null)
                ? $result['agents']
                : ['translator' => 'TranslationAgent'],
        ];
    }

    /**
     * Flatten briefing transcript into notes for the caption draft.
     *
     * @param  list<array{role?: string, content?: string}>  $messages
     */
    public function notesFromMessages(array $messages): string
    {
        $lines = [];
        foreach ($messages as $row) {
            $role = ($row['role'] ?? '') === 'assistant' ? 'AI' : 'Owner';
            $text = trim((string) ($row['content'] ?? ''));
            if ($text !== '') {
                $lines[] = $role.': '.$text;
            }
        }

        return $lines === [] ? '' : implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function contextBlock(Business $business, array $data): string
    {
        $focus = trim((string) ($data['focus_prompt'] ?? ''));
        $mode = (string) ($data['content_mode'] ?? '');
        $visionBlock = trim((string) ($data['_vision_understanding'] ?? ''));

        if ($mode === AiCampaign::MODE_PRODUCT_IMAGES) {
            $ids = array_map('intval', $data['asset_ids'] ?? []);
            $assets = AgentAsset::query()->forBusiness($business->id)->whereIn('id', $ids)->get();
            $assetLines = $assets->map(fn (AgentAsset $a) => "#{$a->id} ".($a->original_name ?: 'image'))->implode("\n");
            if ($assetLines === '') {
                $assetLines = '(none uploaded)';
            }

            $visionSection = $visionBlock !== ''
                ? "VISION UNDERSTANDING (authoritative for products — do NOT invent catalog SKUs):\n{$visionBlock}"
                : "VISION UNDERSTANDING: (pending — wait for image analysis; do not invent products from memory)";

            return <<<TEXT
Shop: {$business->name}
Content mode: product_images
Focus brief from owner: {$focus}
Uploaded images:
{$assetLines}
{$visionSection}
RULE: Product claims must come from VISION UNDERSTANDING or owner text only. Never invent monthly/weekly passes or catalog names that vision did not show.
TEXT;
        }

        $products = Product::query()
            ->forBusiness($business->id)
            ->where('status', 'active')
            ->orderByDesc('id')
            ->limit(8)
            ->get(['id', 'name', 'price', 'stock', 'description'])
            ->map(function (Product $p) {
                $desc = trim((string) $p->description);
                $tail = $desc !== '' ? ' · '.mb_substr($desc, 0, 80) : '';

                return "#{$p->id} {$p->name} · {$p->price} DA · stock {$p->stock}{$tail}";
            })
            ->implode("\n");

        return <<<TEXT
Shop: {$business->name}
Content mode: {$mode}
Focus brief from owner: {$focus}
Catalog sample (background only — prefer owner focus; do not invent offers the focus never named):
{$products}
TEXT;
    }

    /**
     * @param  list<array{role?: string, content?: string}>  $messages
     * @return list<array{role: string, content: string}>
     */
    private function cleanMessages(array $messages): array
    {
        $out = [];
        foreach (array_slice($messages, -12) as $row) {
            $role = ($row['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
            $content = trim((string) ($row['content'] ?? ''));
            if ($content !== '') {
                $out[] = ['role' => $role, 'content' => mb_substr($content, 0, 1500)];
            }
        }

        return $out;
    }

    /**
     * @return array{ready: bool, message: string, question: string}
     */
    private function parse(string $raw): array
    {
        $json = $raw;
        if (preg_match('/\{.*\}/s', $raw, $m)) {
            $json = $m[0];
        }
        $data = json_decode($json, true);
        if (! is_array($data)) {
            return ['ready' => false, 'message' => trim($raw), 'question' => ''];
        }

        return [
            'ready' => (bool) ($data['ready'] ?? false),
            'message' => $this->asText($data['message'] ?? ''),
            'question' => $this->asText($data['question'] ?? ''),
        ];
    }

    private function asText(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }
        if (is_numeric($value)) {
            return trim((string) $value);
        }
        if (is_array($value)) {
            $encoded = json_encode($value, JSON_UNESCAPED_UNICODE);

            return is_string($encoded) ? $encoded : '';
        }

        return '';
    }

    private function looksLikeQuestion(string $text): bool
    {
        $t = trim($text);

        return str_ends_with($t, '?')
            || str_ends_with($t, '؟')
            || (bool) preg_match('/\b(which|what|who|how|when|أين|واش|شنو|quel|quelle)\b/iu', $t);
    }

    private function looksLikeEnoughFocus(string $focus): bool
    {
        return (bool) preg_match(
            '/(product|offer|promo|diamond|pass|شحن|لعبة|منتج|عرض|سعر|pubg|free\s*fire|mlbb|mobile\s*legends|legend)/iu',
            $focus,
        );
    }

    private function softenScriptMix(string $text): string
    {
        if ($text === '') {
            return $text;
        }
        $hasAr = (bool) preg_match('/[\x{0600}-\x{06FF}]/u', $text);
        $hasLat = (bool) preg_match('/[A-Za-z]{2,}/', $text);
        if (! ($hasAr && $hasLat)) {
            return $text;
        }
        $text = preg_replace('/([\x{0600}-\x{06FF}])([A-Za-z])/u', '$1 $2', $text) ?? $text;
        $text = preg_replace('/([A-Za-z])([\x{0600}-\x{06FF}])/u', '$1 $2', $text) ?? $text;

        return $text;
    }
}
