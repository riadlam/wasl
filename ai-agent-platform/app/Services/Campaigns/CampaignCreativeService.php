<?php

namespace App\Services\Campaigns;

use App\AI\ImageModels\ImageModelCatalog;
use App\AI\ImageOnImageLanguage;
use App\AI\LlmModels\LlmModelCatalog;
use App\AI\Agents\AgentLoop;
use App\AI\Agents\CaptionApprover;
use App\AI\Agents\McpToolPolicy;
use App\AI\Providers\FalImageProvider;
use App\AI\ReplyLanguage;
use App\AI\Skills\ReplyGroundingGuard;
use App\AI\Skills\SkillRegistry;
use App\Mcp\McpContext;
use App\Exceptions\InsufficientWalletException;
use App\Models\AgentAsset;
use App\Models\AgentImageJob;
use App\Models\AiCampaignSlot;
use App\Models\Business;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Wallet\AiTaskBillingService;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class CampaignCreativeService
{
    public function __construct(
        private AgentLoop $loop,
        private McpToolPolicy $policy,
        private LlmModelCatalog $llmModels,
        private FalImageProvider $images,
        private ImageModelCatalog $imageModels,
        private WalletService $wallets,
        private AiTaskBillingService $billing,
        private CampaignMcpGateway $mcp,
        private CampaignVerifiedProductFacts $verifiedProducts,
        private CaptionApprover $captionApprover,
    ) {}

    /**
     * @param  list<int>  $channelIds
     */
    public function resolvePlatform(Business $business, array $channelIds): string
    {
        $ids = array_values(array_map('intval', $channelIds));
        if ($ids === []) {
            return 'facebook';
        }

        $accounts = SocialAccount::query()
            ->where('business_id', $business->id)
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        foreach ($ids as $id) {
            $platform = strtolower(trim((string) ($accounts->get($id)?->platform ?? '')));
            if ($platform !== '') {
                return $platform;
            }
        }

        return 'facebook';
    }

    public function imageSizeFor(string $platform, string $slotKind): string
    {
        if ($slotKind === AiCampaignSlot::KIND_STORY) {
            return 'portrait_16_9';
        }

        $platform = strtolower($platform);
        if (in_array($platform, ['linkedin', 'twitter', 'x', 'youtube'], true)) {
            return 'landscape_16_9';
        }

        return 'square_hd';
    }

    /**
     * Stories-only schedule → story sample; otherwise feed post.
     *
     * @param  list<array<string, mixed>>  $days
     */
    public function exampleSlotKind(array $days): string
    {
        $posts = 0;
        $stories = 0;
        foreach ($days as $day) {
            $posts += (int) ($day['posts'] ?? 0);
            $stories += (int) ($day['stories'] ?? 0);
        }

        return ($posts < 1 && $stories > 0)
            ? AiCampaignSlot::KIND_STORY
            : AiCampaignSlot::KIND_POST;
    }

    /**
     * @param  array{day_count?: int, posts?: int, stories?: int}|null  $campaign
     * @param  array{day_index?: int, previous_hooks?: string}|null  $slot  null drafts the tease
     * @return array{title: string, caption: string, hashtags: list<string>, image_prompt: string, usage: array<string, mixed>}
     */
    public function draft(
        Business $business,
        string $kind,
        string $platform,
        string $focus,
        string $context,
        ?AgentAsset $asset = null,
        ?array $campaign = null,
        ?array $slot = null,
        ?string $traceId = null,
    ): array {
        $traceId ??= (string) Str::uuid();
        $language = ReplyLanguage::forBusiness($business);
        $label = $language === ReplyLanguage::FRENCH ? 'French' : 'Algerian Darija';
        $langBlock = ReplyLanguage::creativeInstruction($language);
        $shape = $kind === AiCampaignSlot::KIND_STORY
            ? 'One short story line, under 80 characters, plus at most 2 hashtags. 1 emoji is fine.'
            : 'One feed caption: hook line + blank line + 2 short body lines + CTA. Exactly 3 niche hashtags. Always include 1–3 niche emojis (e.g. gaming ⚡🎮💎, delivery 🚚). ONLY the offer assigned for THIS slot (see SLOT IDEA / THIS SLOT OFFER / THIS IMAGE in focus) — never feature other campaign offers or dump a product list. Put hashtags only in the JSON hashtags array — do not duplicate them inside caption.';
        $size = $this->imageSizeFor($platform, $kind);

        $dayCount = max(1, (int) ($campaign['day_count'] ?? 1));
        $posts = max(0, (int) ($campaign['posts'] ?? 0));
        $stories = max(0, (int) ($campaign['stories'] ?? 0));
        $span = $dayCount === 1 ? '1 day' : "{$dayCount} days";
        $volume = trim(($posts > 0 ? "{$posts} posts" : '').($posts > 0 && $stories > 0 ? ' + ' : '').($stories > 0 ? "{$stories} stories" : ''));
        if ($volume === '') {
            $volume = 'their planned publishing cadence';
        }

        $dayIndex = isset($slot['day_index']) ? (int) $slot['day_index'] + 1 : null;
        $role = $dayIndex === null
            ? "This draft is a CAMPAIGN TEASE — a sample of what SCHEDULED posts will look like when the campaign runs across {$span} ({$volume}). The owner focus/brief is DIRECTION for agents (topic, style), NOT copy to print. Never put briefing chat or meta lines like \"make new posts / same concept\" into the caption or image. Show THIS shop's real category only from verified facts / recent posts / identity — never invent an unrelated product line."
            : "This is day {$dayIndex} of {$dayCount} in a {$span} campaign ({$volume}). Pick the angle for this day from the campaign rhythm and do not reuse these earlier hooks: ".($slot['previous_hooks'] ?? '(none yet)').'. Owner focus is scheduling direction, not literal on-image text. Stay inside THIS shop\'s evidenced category.';
        $skills = app(SkillRegistry::class);
        $skillBlock = $skills->promptFor('campaign', $business, ['reply_language' => $language]);
        $verifiedBlock = $this->verifiedProducts->block($business, $focus, $traceId);
        $verifiedEmpty = str_contains($verifiedBlock, '(none matched in catalog)');

        CampaignTrace::info('campaigns.draft.start', [
            'trace_id' => $traceId,
            'business_id' => $business->id,
            'kind' => $kind,
            'platform' => $platform,
            'language' => $language,
            'is_tease' => $dayIndex === null,
            'day_index' => $dayIndex,
            'image_size' => $size,
            'asset_id' => $asset?->id,
            'focus_preview' => CampaignTrace::clip($focus, 600),
            'context_chars' => mb_strlen($context),
            'context_skipped' => $context === '',
            'verified_empty' => $verifiedEmpty,
            'skills_chars' => mb_strlen($skillBlock),
        ]);

        $system = <<<TEXT
You are CampaignCreativeAgent — senior social media manager (7+ years) for this shop's {$platform} {$kind}.
{$langBlock}
{$shape}
{$role}
Also write a short owner-facing title (4–10 words in {$label}): the main idea of this post, so the owner sees what you aimed for. The title is NOT part of the published caption.
Image size: {$size} — write image_prompt as an English VISUAL scene (products/lifestyle matching THIS shop) that fits that frame. image_prompt must NEVER quote owner briefing, Arabizi instructions, or meta text. Any on-image slogan must be short marketing copy matching the caption theme, not \"نديرو بوستات…\".
Ground ONLY in VERIFIED_PRODUCT_FACTS, the owner focus/briefing (as intent), recent posts context, product image, and Wasl tool results. Prefer VERIFIED_PRODUCT_FACTS for any product claim. You may still call search_products, get_product or list_delivery_zones to confirm — never invent.
Product benefits: ONLY paraphrase what VERIFIED_PRODUCT_FACTS or the owner briefing explicitly support. If description is empty, sell name + price + CTA only — never invent pack contents, daily rewards, or game mechanics (e.g. do not invent "daily diamonds").
FORBIDDEN: inventing prices, discounts, stock, addresses, phone numbers, offer mechanics, or page facts not in verified facts / brief / tools. Forbidden: catalog dumps ("we have A, B, C"), "شوف المنتوجات", emoji walls, Egyptian Darija, Arabizi, mixing scripts inside one word.
Match the channel's tone and pacing from recent posts when present. Do not copy them verbatim.
After you draft, CaptionApprover will brand-check this tease before the owner sees it.
When done, reply with JSON only: {"title":"...","caption":"...","hashtags":["#a","#b","#c"],"image_prompt":"English scene for the post creative..."}

{$skillBlock}
TEXT;

        $userText = "Platform: {$platform}\nSlot: {$kind}\nCampaign: {$span} · {$volume}\nFocus: ".($focus !== '' ? $focus : '(none)')."\n{$verifiedBlock}\nRecent posts / context:\n".($context !== '' ? $context : '(none)');
        $logoHint = $this->channelLogoHint($business, $platform);
        if ($logoHint !== '') {
            $userText .= "\n".$logoHint;
        }
        if ($asset) {
            $userText .= "\nProduct image file: ".($asset->original_name ?: 'upload').' — use only what you see; do not invent product claims.';
        }

        $business->loadMissing('agentSettings');
        $model = $this->llmModels->resolve($business->agentSettings?->llm_model);
        $content = [['type' => 'text', 'text' => $userText]];
        $dataUrl = $asset?->dataUrl();
        $hasImage = is_string($dataUrl) && $dataUrl !== '';
        if ($hasImage) {
            $content[] = ['type' => 'image_url', 'image_url' => ['url' => $dataUrl]];
        } elseif ($asset) {
            CampaignTrace::warning('campaigns.draft.asset_image_skipped', [
                'trace_id' => $traceId,
                'business_id' => $business->id,
                'asset_id' => $asset->id,
                'skipped' => 'no_data_url',
            ]);
        }

        $options = [
            'model' => $model['model'],
            'temperature' => 0.3,
            'max_tokens' => 700,
            'timeout' => 60,
        ];

        CampaignTrace::info('campaigns.draft.llm_request', [
            'trace_id' => $traceId,
            'business_id' => $business->id,
            'model_key' => $business->agentSettings?->llm_model,
            'model' => $model['model'],
            'temperature' => $options['temperature'],
            'has_vision_image' => $hasImage,
            'system_preview' => CampaignTrace::clip($system, 2000),
            'user_preview' => CampaignTrace::clip($userText, 2500),
        ]);

        try {
            $loop = $this->loop->run(
                $business,
                [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $content],
                ],
                $this->policy->for(McpContext::SURFACE_CAMPAIGN, $business),
                [],
                $options,
                null,
                4,
            );
        } catch (Throwable $e) {
            CampaignTrace::error('campaigns.draft.llm_failed', [
                'trace_id' => $traceId,
                'business_id' => $business->id,
                'model' => $model['model'],
            ], $e);
            throw $e;
        }

        CampaignTrace::info('campaigns.draft.llm_response', [
            'trace_id' => $traceId,
            'business_id' => $business->id,
            'model' => $model['model'],
            'raw_preview' => CampaignTrace::clip((string) $loop->text, 2000),
            'tools' => CampaignTrace::summarizeTools($loop->toolLog),
            'tools_skipped' => $loop->toolLog === [],
            'usage' => $loop->usage,
            'exhausted' => $loop->exhausted,
            'stopped' => $loop->stopped,
        ]);

        $parsed = $this->parseDraft((string) $loop->text);
        if ($parsed['caption'] === '') {
            CampaignTrace::error('campaigns.draft.parse_empty_caption', [
                'trace_id' => $traceId,
                'business_id' => $business->id,
                'raw_preview' => CampaignTrace::clip((string) $loop->text),
                'parsed' => $parsed,
            ]);
            throw new RuntimeException('The model did not return a caption.');
        }

        $titleFallback = false;
        if ($parsed['title'] === '') {
            $parsed['title'] = $this->fallbackTitle($parsed['caption'], $label);
            $titleFallback = true;
            CampaignTrace::warning('campaigns.draft.title_fallback', [
                'trace_id' => $traceId,
                'business_id' => $business->id,
                'title' => $parsed['title'],
            ]);
        }
        $imagePromptFallback = false;
        if ($parsed['image_prompt'] === '') {
            $parsed['image_prompt'] = 'Clean professional product-focused social post creative for '.$platform.', no invented text overlays.';
            $imagePromptFallback = true;
            CampaignTrace::warning('campaigns.draft.image_prompt_fallback', [
                'trace_id' => $traceId,
                'business_id' => $business->id,
            ]);
        }

        $sources = [$focus, $context, $userText, $verifiedBlock];
        foreach ($loop->toolLog as $row) {
            $sources[] = (string) json_encode($row['result'], JSON_UNESCAPED_UNICODE);
        }
        $beforeGround = $parsed['caption'];
        $guard = app(ReplyGroundingGuard::class);
        $invented = $guard->inventedNumbers($beforeGround, $sources);
        $parsed['caption'] = $this->groundCaption($parsed['caption'], $sources);
        if ($invented !== []) {
            CampaignTrace::warning('campaigns.draft.grounding_stripped_numbers', [
                'trace_id' => $traceId,
                'business_id' => $business->id,
                'invented_numbers' => $invented,
                'caption_before' => CampaignTrace::clip($beforeGround, 800),
                'caption_after' => CampaignTrace::clip($parsed['caption'], 800),
            ]);
        }

        CampaignTrace::info('campaigns.draft.done', [
            'trace_id' => $traceId,
            'business_id' => $business->id,
            'title' => $parsed['title'],
            'title_fallback' => $titleFallback,
            'image_prompt_fallback' => $imagePromptFallback,
            'caption' => CampaignTrace::clip($parsed['caption'], 1200),
            'hashtags' => $parsed['hashtags'],
            'image_prompt' => CampaignTrace::clip($parsed['image_prompt'], 400),
            'usage' => $loop->usage,
        ]);

        $approverMeta = null;
        $usage = $loop->usage;
        // Tease path: CaptionApprover author–critic before showing the owner.
        if ($dayIndex === null) {
            $approved = $this->runTeaseApprover(
                $business,
                $parsed,
                $focus,
                $model['model'],
                $traceId,
            );
            $parsed['caption'] = $approved['caption'];
            $usage = $this->mergeUsageArrays($usage, $approved['usage'] ?? []);
            $approverMeta = [
                'agent' => 'CaptionApprover',
                'approved' => (bool) ($approved['approved'] ?? false),
                'needs_owner_edit' => (bool) ($approved['needs_owner_edit'] ?? false),
                'rounds' => count($approved['rounds'] ?? []),
            ];
            CampaignTrace::info('campaigns.draft.tease_approver', [
                'trace_id' => $traceId,
                'business_id' => $business->id,
                ...$approverMeta,
                'caption' => CampaignTrace::clip($parsed['caption'], 800),
            ]);
        }

        return [
            'title' => $parsed['title'],
            'caption' => $parsed['caption'],
            'hashtags' => $parsed['hashtags'],
            'image_prompt' => $parsed['image_prompt'],
            'usage' => $usage,
            'agents' => [
                'brief' => 'CampaignBriefAgent',
                'writer' => 'CampaignCreativeAgent',
                'approver' => $dayIndex === null ? 'CaptionApprover' : null,
            ],
            'approver' => $approverMeta,
        ];
    }

    /**
     * @param  array{title: string, caption: string, hashtags: list<string>, image_prompt: string}  $draft
     * @return array{caption: string, approved: bool, needs_owner_edit: bool, rounds: list<array<string, mixed>>, usage: array<string, mixed>}
     */
    private function runTeaseApprover(
        Business $business,
        array $draft,
        string $focus,
        string $model,
        string $traceId,
    ): array {
        $first = true;
        $hashtags = $draft['hashtags'];
        $title = $draft['title'];
        $imagePrompt = $draft['image_prompt'];

        return $this->captionApprover->reviewLoop(
            $business,
            $focus,
            function (string $ownerBrief, ?string $feedback, ?string $previous) use (
                &$first,
                $draft,
                $business,
                $model,
                $traceId,
                &$hashtags,
                &$title,
                &$imagePrompt,
            ): array {
                if ($first) {
                    $first = false;

                    return [
                        'caption' => $draft['caption'],
                        'usage' => ['prompt_tokens' => 0, 'completion_tokens' => 0],
                    ];
                }

                $rewrite = $this->rewriteTeaseCaption(
                    $business,
                    $previous ?: $draft['caption'],
                    (string) $feedback,
                    $ownerBrief,
                    $title,
                    $hashtags,
                    $imagePrompt,
                    $model,
                    $traceId,
                );
                if (($rewrite['title'] ?? '') !== '') {
                    $title = $rewrite['title'];
                }
                if (($rewrite['hashtags'] ?? []) !== []) {
                    $hashtags = $rewrite['hashtags'];
                }
                if (($rewrite['image_prompt'] ?? '') !== '') {
                    $imagePrompt = $rewrite['image_prompt'];
                }

                return [
                    'caption' => $rewrite['caption'],
                    'usage' => $rewrite['usage'] ?? ['prompt_tokens' => 0, 'completion_tokens' => 0],
                ];
            },
            $model,
        );
    }

    /**
     * @param  list<string>  $hashtags
     * @return array{title: string, caption: string, hashtags: list<string>, image_prompt: string, usage: array<string, mixed>}
     */
    private function rewriteTeaseCaption(
        Business $business,
        string $previous,
        string $feedback,
        string $focus,
        string $title,
        array $hashtags,
        string $imagePrompt,
        string $model,
        string $traceId,
    ): array {
        $system = 'You are CampaignCreativeAgent rewriting a campaign TEASE caption after CaptionApprover feedback. '
            .'Return JSON only: {"title":"...","caption":"...","hashtags":["#a","#b","#c"],"image_prompt":"..."}. '
            .'Do not invent prices or offer mechanics. Keep shop language.';
        $user = "Focus/brief:\n{$focus}\n\nPrevious caption:\n{$previous}\n\nApprover feedback:\n{$feedback}\n\n"
            ."Previous title: {$title}\nPrevious hashtags: ".implode(' ', $hashtags)."\nImage prompt: {$imagePrompt}";

        try {
            $loop = $this->loop->run(
                $business,
                [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ],
                $this->policy->for(McpContext::SURFACE_CAMPAIGN, $business),
                [],
                ['model' => $model, 'temperature' => 0.25, 'max_tokens' => 700, 'timeout' => 60],
                null,
                3,
            );
        } catch (Throwable $e) {
            CampaignTrace::warning('campaigns.draft.tease_rewrite_failed', [
                'trace_id' => $traceId,
                'business_id' => $business->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'title' => $title,
                'caption' => $previous,
                'hashtags' => $hashtags,
                'image_prompt' => $imagePrompt,
                'usage' => ['prompt_tokens' => 0, 'completion_tokens' => 0],
            ];
        }

        $parsed = $this->parseDraft((string) $loop->text);
        if ($parsed['caption'] === '') {
            $parsed['caption'] = $previous;
        }

        return [
            'title' => $parsed['title'] !== '' ? $parsed['title'] : $title,
            'caption' => $parsed['caption'],
            'hashtags' => $parsed['hashtags'] !== [] ? $parsed['hashtags'] : $hashtags,
            'image_prompt' => $parsed['image_prompt'] !== '' ? $parsed['image_prompt'] : $imagePrompt,
            'usage' => $loop->usage,
        ];
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     * @return array<string, mixed>
     */
    private function mergeUsageArrays(array $a, array $b): array
    {
        foreach (['prompt_tokens', 'completion_tokens', 'fal_calls', 'calls_with_cost'] as $key) {
            $a[$key] = (int) ($a[$key] ?? 0) + (int) ($b[$key] ?? 0);
        }
        $a['cost_usd'] = (float) ($a['cost_usd'] ?? 0) + (float) ($b['cost_usd'] ?? 0);

        return $a;
    }

    /**
     * @param  list<string>  $sources
     */
    private function groundCaption(string $caption, array $sources): string
    {
        $guard = app(ReplyGroundingGuard::class);
        if ($guard->inventedNumbers($caption, $sources) === []) {
            return $caption;
        }

        $lines = preg_split('/(?<=[.!?؟\n])\s*/u', $caption) ?: [$caption];
        $kept = array_filter($lines, fn (string $line) => trim($line) !== '' && $guard->inventedNumbers($line, $sources) === []);

        return trim(implode(' ', $kept)) ?: trim((string) preg_replace('/\d[\d\s.,]*/u', '', $caption));
    }

    /**
     * Hint for image_prompt / draft: use connected page logo when available.
     */
    private function channelLogoHint(Business $business, string $platform): string
    {
        $account = SocialAccount::query()
            ->where('business_id', $business->id)
            ->where('provider', 'socialapi')
            ->where('platform', $platform)
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', '!=', 'disconnected');
            })
            ->with('logoAsset')
            ->orderByDesc('id')
            ->first();

        if (! $account) {
            $account = SocialAccount::query()
                ->where('business_id', $business->id)
                ->where('provider', 'socialapi')
                ->where('platform', '!=', 'simulator')
                ->where(function ($q) {
                    $q->whereNull('status')->orWhere('status', '!=', 'disconnected');
                })
                ->with('logoAsset')
                ->orderByDesc('id')
                ->first();
        }

        $url = $account?->resolvedLogoUrl();
        if (! is_string($url) || $url === '') {
            return '';
        }

        $source = $account->hasCustomLogo() ? 'shop-uploaded page logo' : 'SocialAPI page profile picture';

        return 'PAGE_LOGO ('.$source.'): '.$url
            ."\nIn image_prompt, when a brand mark helps, discreetly include this page's logo/colors — do not invent a different logo. Prefer small corner watermark-style placement, never covering the product.";
    }

    /**
     * Generate a platform-sized creative using the shop image model. $async always takes the
     * Fal queue path (the caller polls the image job) so HTTP requests never wait on the render.
     *
     * @return array{asset: ?AgentAsset, image_url: ?string, image_size: string, image_job_id: ?int, image_error: ?string, model_key: string}
     */
    public function generateImage(
        Business $business,
        User $user,
        string $imagePrompt,
        string $platform,
        string $slotKind,
        ?string $ownerBrief = null,
        bool $async = false,
    ): array {
        $business->loadMissing('agentSettings');
        $size = $this->imageSizeFor($platform, $slotKind);
        // Always the shop's Agents setting (flux | nano_banana_2 | gpt_image_2) — never a random other model.
        $model = $this->imageModels->resolve($business->agentSettings?->image_model);
        $modelKey = (string) $model['id'];
        CampaignTrace::info('campaigns.image.model', [
            'business_id' => $business->id,
            'setting' => $business->agentSettings?->image_model,
            'resolved' => $modelKey,
            'label' => $model['label'] ?? $modelKey,
        ]);
        $priceDa = (float) $model['price_da'];
        try {
            $this->wallets->authorizeBusinessAi($business, $priceDa);
        } catch (InsufficientWalletException $e) {
            return [
                'asset' => null,
                'image_url' => null,
                'image_size' => $size,
                'image_job_id' => null,
                'image_error' => 'Not enough wallet balance to generate an image (need '.$priceDa.' DA).',
                'model_key' => $modelKey,
            ];
        }

        $fullPrompt = ImageOnImageLanguage::enrich(
            $business,
            $imagePrompt,
            $ownerBrief !== null && $ownerBrief !== '' ? $ownerBrief : null,
        );
        $logoHint = $this->channelLogoHint($business, $platform);
        if ($logoHint !== '' && stripos($fullPrompt, 'PAGE_LOGO') === false) {
            $fullPrompt = $logoHint.' '.$fullPrompt;
        }

        if ($async) {
            return $this->queueImage($business, $user, $fullPrompt, $modelKey, $size, $priceDa);
        }

        try {
            $generated = $this->images->generate($fullPrompt, $modelKey, $size, null, 90);
            $asset = $this->storeGeneratedAsset($business, (string) $generated['url'], (string) ($generated['content_type'] ?? 'image/jpeg'));
            $job = AgentImageJob::query()->create([
                'business_id' => $business->id,
                'user_id' => $user->id,
                'fal_request_id' => 'campaign-sync-'.Str::lower(Str::random(12)),
                'model_key' => $modelKey,
                'endpoint' => (string) ($model['endpoint'] ?? ''),
                'image_size' => $size,
                'prompt' => $fullPrompt,
                'status' => AgentImageJob::STATUS_COMPLETED,
                'asset_id' => $asset->id,
                'price_da' => (int) max(1, (int) round($priceDa)),
            ]);
            $charge = $this->billing->chargeImageSuccess($business, $job, $user);

            return [
                'asset' => $asset,
                'image_url' => $asset->absoluteUrl(),
                'image_size' => $size,
                'image_job_id' => $job->id,
                'image_error' => null,
                'model_key' => $modelKey,
                'cost_da' => (float) ($charge->cost_da ?? 0),
            ];
        } catch (Throwable $e) {
            return $this->queueImage($business, $user, $fullPrompt, $modelKey, $size, $priceDa, $e->getMessage());
        }
    }

    /**
     * @return array{asset: null, image_url: null, image_size: string, image_job_id: ?int, image_error: ?string, model_key: string, cost_da: float}
     */
    private function queueImage(Business $business, User $user, string $fullPrompt, string $modelKey, string $size, float $priceDa, ?string $syncError = null): array
    {
        try {
            $queued = $this->images->submit($fullPrompt, $modelKey, $size, null);
            $job = AgentImageJob::query()->create([
                'business_id' => $business->id,
                'user_id' => $user->id,
                'fal_request_id' => $queued['request_id'],
                'model_key' => $modelKey,
                'endpoint' => $queued['endpoint'],
                'image_size' => $size,
                'prompt' => $fullPrompt,
                'status' => AgentImageJob::STATUS_QUEUED,
                'status_url' => $queued['status_url'],
                'response_url' => $queued['response_url'],
                'price_da' => (int) max(1, (int) round($priceDa)),
            ]);

            return [
                'asset' => null,
                'image_url' => null,
                'image_size' => $size,
                'image_job_id' => $job->id,
                'image_error' => null,
                'model_key' => $modelKey,
                'cost_da' => 0.0,
            ];
        } catch (Throwable $submitError) {
            return [
                'asset' => null,
                'image_url' => null,
                'image_size' => $size,
                'image_job_id' => null,
                'image_error' => $submitError->getMessage() ?: ($syncError ?: 'Image generation failed.'),
                'model_key' => $modelKey,
                'cost_da' => 0.0,
            ];
        }
    }

    /**
     * @param  list<int>  $channelIds
     */
    public function listPostsContext(Business $business, array $channelIds): string
    {
        $ids = SocialAccount::query()
            ->where('business_id', $business->id)
            ->whereIn('id', $channelIds)
            ->pluck('socialapi_account_id')
            ->filter()
            ->map(fn ($id) => (string) $id)
            ->values()
            ->all();

        if ($ids === []) {
            throw new RuntimeException('Selected channels are missing SocialAPI account ids.');
        }

        return $this->mcp->listRecentPosts($ids);
    }

    private function storeGeneratedAsset(Business $business, string $url, string $contentType): AgentAsset
    {
        $response = Http::timeout(60)->get($url);
        if ($response->failed() || ! is_string($response->body()) || $response->body() === '') {
            throw new RuntimeException('Could not download generated image.');
        }

        $bytes = $response->body();
        $mime = strtolower(trim($contentType));
        if ($mime === '' || ! str_starts_with($mime, 'image/')) {
            $mime = 'image/jpeg';
        }
        $ext = match (true) {
            str_contains($mime, 'png') => 'png',
            str_contains($mime, 'webp') => 'webp',
            default => 'jpg',
        };

        $name = 'campaign-'.now()->format('Ymd-His').'-'.Str::lower(Str::random(6)).'.'.$ext;
        $path = 'agent-assets/'.$business->id.'/'.$name;
        Storage::disk('public')->put($path, $bytes);

        return AgentAsset::query()->create([
            'business_id' => $business->id,
            'agent_id' => $business->agent?->id,
            'disk' => 'public',
            'path' => $path,
            'original_name' => $name,
            'mime' => $mime,
            'size' => strlen($bytes),
        ]);
    }

    /**
     * @return array{title: string, caption: string, hashtags: list<string>, image_prompt: string}
     */
    private function parseDraft(string $raw): array
    {
        $json = $raw;
        if (preg_match('/\{.*\}/s', $raw, $match)) {
            $json = $match[0];
        }
        $data = json_decode($json, true);
        if (! is_array($data)) {
            return ['title' => '', 'caption' => trim($raw), 'hashtags' => [], 'image_prompt' => ''];
        }
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
            'title' => mb_substr(trim((string) ($data['title'] ?? '')), 0, 180),
            'caption' => trim((string) ($data['caption'] ?? '')),
            'hashtags' => array_values(array_slice($tags, 0, 5)),
            'image_prompt' => trim((string) ($data['image_prompt'] ?? '')),
        ];
    }

    private function fallbackTitle(string $caption, string $label): string
    {
        $first = trim((string) preg_split('/\R/u', $caption)[0]);
        $first = trim((string) preg_replace('/^["\'«»]+|["\'«»]+$/u', '', $first));
        if ($first === '') {
            return $label === 'French' ? 'Idée du post' : 'فكرة المنشور';
        }

        return mb_substr($first, 0, 80);
    }
}
