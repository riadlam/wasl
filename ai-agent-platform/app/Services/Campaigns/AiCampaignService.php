<?php

namespace App\Services\Campaigns;

use App\AI\Runtime\AiRuntime;
use App\AI\Runtime\SkAgentClient;
use App\Exceptions\InsufficientWalletException;
use App\Jobs\ProcessAiCampaign;
use App\Jobs\ProcessCampaignSlot;
use App\Models\AgentAsset;
use App\Models\AiCampaign;
use App\Models\AiCampaignSlot;
use App\Models\AiCampaignSlotTarget;
use App\Models\Business;
use App\Models\User;
use App\Services\Wallet\AiTaskBillingService;
use App\Services\Wallet\WalletService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class AiCampaignService
{
    public function __construct(
        private CampaignMcpGateway $mcp,
        private CampaignCreativeService $creatives,
        private WalletService $wallets,
        private AiTaskBillingService $billing,
        private CampaignCostEstimator $estimator,
        private SkAgentClient $sk,
    ) {}

    public static function queue(): string
    {
        return (string) config('campaigns.queue', 'campaigns');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function estimate(Business $business, array $data): array
    {
        $estimate = $this->estimator->estimate($business, $data);
        $owner = $this->wallets->ownerForBusiness($business);
        $estimate['available_da'] = $this->wallets->balance($owner);
        $estimate['affordable'] = $estimate['available_da'] >= $estimate['estimated_da'];

        return $estimate;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function launch(Business $business, User $user, array $data): AiCampaign
    {
        $estimate = $this->estimate($business, $data);
        if (! $estimate['affordable']) {
            throw new InsufficientWalletException(
                $estimate['estimated_da'],
                $estimate['available_da'],
                'Not enough wallet balance for this campaign (estimated '.$estimate['estimated_da'].' DA).',
            );
        }

        $campaign = DB::transaction(function () use ($business, $user, $data, $estimate) {
            $tz = $business->timezone ?: 'Africa/Algiers';
            $startsOn = trim((string) ($data['starts_on'] ?? ''));
            if ($startsOn !== '') {
                $starts = Carbon::parse($startsOn, $tz)->startOfDay();
            } else {
                $starts = Carbon::now($tz)->addDay()->startOfDay();
            }
            // Never schedule in the past relative to shop timezone.
            $today = Carbon::now($tz)->startOfDay();
            if ($starts->lt($today)) {
                $starts = $today->copy();
            }
            $name = trim((string) ($data['name'] ?? ''));
            $planMeta = is_array($data['plan_meta'] ?? null) ? $data['plan_meta'] : [];
            $briefNotes = trim((string) ($data['brief_notes'] ?? ''));
            if ($briefNotes !== '') {
                $planMeta['brief_notes'] = $briefNotes;
                if (empty($planMeta['understanding'])) {
                    $planMeta['understanding'] = $briefNotes;
                }
            }
            if ($planMeta === []) {
                $planMeta = null;
            }

            $campaign = AiCampaign::query()->create([
                'business_id' => $business->id,
                'user_id' => $user->id,
                'name' => $name !== '' ? mb_substr($name, 0, 160) : null,
                'status' => AiCampaign::STATUS_SCHEDULED,
                'content_mode' => $data['content_mode'],
                'focus_prompt' => $data['content_mode'] === AiCampaign::MODE_AI_RECENT
                    ? trim((string) ($data['focus_prompt'] ?? ''))
                    : null,
                'starts_on' => $starts->toDateString(),
                'day_count' => (int) $data['day_count'],
                'timezone' => $tz,
                'estimated_da' => $estimate['estimated_da'],
                'plan_meta' => $planMeta,
            ]);

            foreach ($data['channel_ids'] as $id) {
                $campaign->channels()->create(['social_account_id' => (int) $id]);
            }

            $assetIds = array_values(array_map('intval', $data['asset_ids'] ?? []));
            foreach ($assetIds as $position => $assetId) {
                $campaign->assets()->create(['agent_asset_id' => $assetId, 'position' => $position]);
            }

            $this->expandSlots($campaign->load('channels.socialAccount'), $data['days'], $starts, $assetIds);

            return $campaign;
        });

        $this->assignSlotIdeas($business, $user, $campaign->fresh(['slots']));
        $this->ingestCampaignMemory($business, $campaign->fresh(), $data);

        ProcessAiCampaign::dispatch($campaign->id)->onQueue(self::queue());

        return $campaign->fresh(['channels', 'slots.targets.socialAccount', 'slots.asset']);
    }

    /**
     * Persist PostCrafter + briefing prefs when owner accepts the tease and launches.
     * Stored per-campaign in Supabase so slot drafting can reuse decisions one-by-one.
     *
     * @param  array<string, mixed>  $data
     */
    private function ingestCampaignMemory(Business $business, AiCampaign $campaign, array $data): void
    {
        try {
            $planMeta = is_array($campaign->plan_meta)
                ? $campaign->plan_meta
                : (is_array($data['plan_meta'] ?? null) ? $data['plan_meta'] : []);
            $tease = trim((string) ($data['accepted_tease'] ?? ($planMeta['tease_caption'] ?? '')));
            $brief = trim((string) ($data['brief_notes'] ?? ($planMeta['brief_notes'] ?? '')));
            $understanding = trim((string) ($planMeta['understanding'] ?? $brief));
            $processLines = $this->formatProcessDecisions($planMeta);

            $parts = array_filter([
                'Campaign knowledge (owner launched after brief). Use for every scheduled post in this campaign.',
                'Campaign id: '.$campaign->id,
                'Mode: '.(string) $campaign->content_mode,
                $campaign->focus_prompt ? 'Focus: '.$campaign->focus_prompt : null,
                $understanding !== '' ? "UNDERSTANDING:\n{$understanding}" : null,
                $brief !== '' && $brief !== $understanding ? "BRIEF NOTES:\n{$brief}" : null,
                ! empty($planMeta['plan_notes']) ? 'Plan: '.$planMeta['plan_notes'] : null,
                ! empty($planMeta['product_focus']) ? 'Product focus: '.$planMeta['product_focus'] : null,
                ! empty($planMeta['analysis']) ? 'Analysis: '.$planMeta['analysis'] : null,
                $processLines !== '' ? $processLines : null,
                $tease !== '' ? "Accepted tease:\n{$tease}" : null,
            ]);
            $content = implode("\n\n", $parts);
            if (mb_strlen($content) < 40) {
                return;
            }

            $sourceId = 'ai_campaign:'.$campaign->id;
            $meta = [
                'source' => 'ai_campaign',
                'campaign_id' => $campaign->id,
                'content_mode' => $campaign->content_mode,
                'memory_key' => $sourceId,
            ];

            // Durable shop memory + embeddings (business_memories + memories namespace).
            $primary = $this->sk->rememberMemory($business, $sourceId, $content, $meta);
            $latestKey = 'campaign_prefs:'.$business->id.':latest';
            $latest = $this->sk->rememberMemory(
                $business,
                $latestKey,
                $content,
                $meta + ['memory_key' => $latestKey],
            );

            if (empty($primary['ok']) || empty($latest['ok'])) {
                Log::warning('campaigns.memory_ingest_failed', [
                    'campaign_id' => $campaign->id,
                    'primary' => $primary['error'] ?? $primary,
                    'latest' => $latest['error'] ?? $latest,
                ]);
            } else {
                Log::info('campaigns.memory_ingest_ok', [
                    'campaign_id' => $campaign->id,
                    'keys' => [$sourceId, $latestKey],
                    'chars' => mb_strlen($content),
                ]);
            }
        } catch (Throwable $e) {
            Log::warning('campaigns.memory_ingest_failed', [
                'campaign_id' => $campaign->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $planMeta
     */
    private function formatProcessDecisions(array $planMeta): string
    {
        $choices = is_array($planMeta['card_choices'] ?? null) ? $planMeta['card_choices'] : [];
        $cards = is_array($planMeta['confirm_cards'] ?? null) ? $planMeta['confirm_cards'] : [];
        if ($cards === [] && $choices === []) {
            return '';
        }
        $byId = [];
        foreach ($cards as $card) {
            if (! is_array($card)) {
                continue;
            }
            $id = (string) ($card['id'] ?? '');
            if ($id !== '') {
                $byId[$id] = trim((string) ($card['text'] ?? ''));
            }
        }
        $yes = [];
        $no = [];
        foreach ($choices as $id => $choice) {
            $text = $byId[(string) $id] ?? (string) $id;
            if ($text === '') {
                continue;
            }
            if ($choice === 'confirm') {
                $yes[] = $text;
            } elseif ($choice === 'deny') {
                $no[] = $text;
            }
        }
        $lines = ['PROCESS DECISIONS (owner Confirm/Deny):'];
        if ($yes !== []) {
            $lines[] = 'YES:';
            foreach ($yes as $row) {
                $lines[] = '- '.$row;
            }
        }
        if ($no !== []) {
            $lines[] = 'NO:';
            foreach ($no as $row) {
                $lines[] = '- '.$row;
            }
        }

        return count($lines) > 1 ? implode("\n", $lines) : '';
    }

    /**
     * Build focus for one slot from campaign plan_meta + THIS slot's planned idea/offer.
     */
    private function slotFocusFromCampaign(AiCampaign $campaign, AiCampaignSlot $slot): string
    {
        $focus = (string) ($campaign->focus_prompt ?? '');
        $planMeta = is_array($campaign->plan_meta) ? $campaign->plan_meta : [];
        $slotPlan = $this->slotPlanFor($campaign, $slot);
        $multi = ! empty($planMeta['multi_product'])
            || (is_array($planMeta['image_analyses'] ?? null) && count($planMeta['image_analyses']) > 1);

        $bits = array_filter([
            ! empty($planMeta['understanding']) ? "UNDERSTANDING:\n".$planMeta['understanding'] : null,
            ! empty($planMeta['brief_notes']) && ($planMeta['brief_notes'] ?? '') !== ($planMeta['understanding'] ?? '')
                ? "BRIEF NOTES:\n".$planMeta['brief_notes']
                : null,
            ! empty($planMeta['plan_notes']) ? 'Campaign plan: '.$planMeta['plan_notes'] : null,
            $this->formatProcessDecisions($planMeta) ?: null,
        ]);

        if ($slotPlan !== []) {
            $bits[] = 'SLOT IDEA (owner-facing title for THIS slot only): '.($slotPlan['idea'] ?? $slot->title ?? '');
            if (! empty($slotPlan['offer'])) {
                $bits[] = 'THIS SLOT OFFER (feature only this): '.$slotPlan['offer'];
            }
            if (! empty($slotPlan['angle'])) {
                $bits[] = 'ANGLE: '.$slotPlan['angle'];
            }
            if (! empty($slotPlan['image_description'])) {
                $bits[] = "THIS IMAGE ANALYSIS:\n".$slotPlan['image_description'];
            }
        } elseif (! $multi && ! empty($planMeta['product_focus'])) {
            $bits[] = 'Product focus: '.$planMeta['product_focus'];
        }

        if ($multi && empty($slotPlan['offer']) && ! empty($planMeta['product_focus'])) {
            $bits[] = 'Campaign has multiple offers — do NOT default to lead focus "'.$planMeta['product_focus'].'" unless THIS slot\'s image matches it.';
        }

        $forbidden = $this->previousHooks($campaign, $slot);
        $bits[] = 'FORBIDDEN — do not reuse these hooks/ideas from other slots: '.$forbidden;
        $bits[] = 'Draft THIS slot only (id '.$slot->id.', '.$slot->slot_kind.', day '.$slot->day_index.'). Stay faithful to THIS slot idea/offer. Do not invent products.';

        if ($bits === []) {
            return $focus;
        }

        return trim($focus."\n\n".implode("\n\n", $bits));
    }

    /**
     * @return array{idea?: string, offer?: string, angle?: string, asset_id?: int|null, image_description?: string}
     */
    private function slotPlanFor(AiCampaign $campaign, AiCampaignSlot $slot): array
    {
        $planMeta = is_array($campaign->plan_meta) ? $campaign->plan_meta : [];
        $plans = is_array($planMeta['slot_plans'] ?? null) ? $planMeta['slot_plans'] : [];
        $row = is_array($plans[(string) $slot->id] ?? null) ? $plans[(string) $slot->id] : [];
        if ($row === [] && is_array($plans[$slot->id] ?? null)) {
            $row = $plans[$slot->id];
        }

        $assetId = (int) ($slot->agent_asset_id ?: ($row['asset_id'] ?? 0));
        $analysis = $this->imageAnalysisForAsset($planMeta, $assetId > 0 ? $assetId : null);
        $idea = trim((string) ($row['idea'] ?? $slot->title ?? ''));
        $offer = trim((string) ($row['offer'] ?? ''));
        if ($offer === '' && $analysis !== '') {
            $offer = mb_substr($analysis, 0, 160);
        }

        return array_filter([
            'idea' => $idea !== '' ? $idea : null,
            'offer' => $offer !== '' ? $offer : null,
            'angle' => trim((string) ($row['angle'] ?? '')) ?: null,
            'asset_id' => $assetId > 0 ? $assetId : null,
            'image_description' => $analysis !== '' ? $analysis : null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /**
     * @param  array<string, mixed>  $planMeta
     */
    private function imageAnalysisForAsset(array $planMeta, ?int $assetId): string
    {
        $analyses = is_array($planMeta['image_analyses'] ?? null) ? $planMeta['image_analyses'] : [];
        if ($analyses === []) {
            return trim((string) ($planMeta['analysis'] ?? ''));
        }
        if ($assetId) {
            $label = 'asset_'.$assetId;
            foreach ($analyses as $row) {
                if (! is_array($row)) {
                    continue;
                }
                if ((string) ($row['label'] ?? '') === $label) {
                    return trim((string) ($row['description'] ?? ''));
                }
            }
        }

        return trim((string) ($analyses[0]['description'] ?? $planMeta['analysis'] ?? ''));
    }

    /**
     * After expandSlots: assign a distinct planned idea to every slot so drafts cannot all collapse to one offer.
     */
    private function assignSlotIdeas(Business $business, User $user, AiCampaign $campaign): void
    {
        $campaign->loadMissing('slots');
        $slots = $campaign->slots()->orderBy('scheduled_at')->orderBy('id')->get();
        if ($slots->isEmpty()) {
            return;
        }

        $planMeta = is_array($campaign->plan_meta) ? $campaign->plan_meta : [];
        $analyses = is_array($planMeta['image_analyses'] ?? null) ? $planMeta['image_analyses'] : [];
        $productFocus = trim((string) ($planMeta['product_focus'] ?? ''));
        $understanding = trim((string) ($planMeta['understanding'] ?? $planMeta['brief_notes'] ?? ''));

        $payloadSlots = $slots->map(fn (AiCampaignSlot $s) => [
            'slot_id' => (int) $s->id,
            'kind' => (string) $s->slot_kind,
            'day_index' => (int) $s->day_index,
            'asset_id' => $s->agent_asset_id ? (int) $s->agent_asset_id : null,
        ])->values()->all();

        $planned = $this->deterministicSlotPlans($payloadSlots, $analyses, $productFocus);

        $business->loadMissing('agentSettings');
        if (AiRuntime::usesSk($business)) {
            $sk = $this->sk->planCampaignSlots(
                $business,
                $user,
                $payloadSlots,
                $analyses,
                $understanding,
                $productFocus,
                $business->agentSettings?->llm_model,
            );
            if (empty($sk['error']) && is_array($sk['slots'] ?? null) && $sk['slots'] !== []) {
                foreach ($sk['slots'] as $row) {
                    if (! is_array($row) || empty($row['slot_id'])) {
                        continue;
                    }
                    $sid = (string) (int) $row['slot_id'];
                    if (! isset($planned[$sid])) {
                        continue;
                    }
                    foreach (['idea', 'offer', 'angle'] as $key) {
                        $val = trim((string) ($row[$key] ?? ''));
                        if ($val !== '') {
                            $planned[$sid][$key] = $val;
                        }
                    }
                }
            } elseif (! empty($sk['error'])) {
                Log::warning('campaigns.plan_slots_sk_failed', [
                    'campaign_id' => $campaign->id,
                    'error' => $sk['error'],
                ]);
            }
        }

        $planMeta['slot_plans'] = $planned;
        $campaign->update(['plan_meta' => $planMeta]);

        foreach ($slots as $slot) {
            $row = $planned[(string) $slot->id] ?? null;
            if (! is_array($row)) {
                continue;
            }
            $idea = trim((string) ($row['idea'] ?? ''));
            if ($idea === '') {
                continue;
            }
            $slot->update(['title' => mb_substr($idea, 0, 160)]);
        }

        Log::info('campaigns.slot_plans_assigned', [
            'campaign_id' => $campaign->id,
            'count' => count($planned),
        ]);
    }

    /**
     * @param  list<array{slot_id: int, kind?: string, day_index?: int, asset_id?: int|null}>  $slots
     * @param  list<array<string, mixed>>  $analyses
     * @return array<string, array<string, mixed>>
     */
    private function deterministicSlotPlans(array $slots, array $analyses, string $productFocus): array
    {
        $byLabel = [];
        foreach ($analyses as $a) {
            if (! is_array($a)) {
                continue;
            }
            $label = trim((string) ($a['label'] ?? ''));
            if ($label !== '') {
                $byLabel[$label] = $a;
            }
        }
        $planned = [];
        $postOfferByDay = [];
        foreach ($slots as $i => $slot) {
            $sid = (string) (int) ($slot['slot_id'] ?? 0);
            $kind = (string) ($slot['kind'] ?? 'post');
            $day = (int) ($slot['day_index'] ?? 1);
            $assetId = isset($slot['asset_id']) ? (int) $slot['asset_id'] : null;
            $analysis = $assetId && isset($byLabel['asset_'.$assetId])
                ? $byLabel['asset_'.$assetId]
                : ($analyses[$i % max(1, count($analyses))] ?? []);
            $desc = is_array($analysis) ? trim((string) ($analysis['description'] ?? '')) : '';
            $offer = $desc !== ''
                ? mb_substr(explode('.', $desc)[0], 0, 120)
                : ($productFocus !== '' ? $productFocus : 'Offer '.($i + 1));

            if ($kind === AiCampaignSlot::KIND_STORY) {
                if ($assetId && isset($byLabel['asset_'.$assetId]) && $desc !== '') {
                    $base = $offer;
                } else {
                    $base = $postOfferByDay[$day] ?? $offer;
                }
                $idea = mb_substr('Story beat: '.$base, 0, 160);
                $angle = 'story urgency / tip for same offer';
                $offer = $base;
            } else {
                $idea = mb_substr($offer !== '' ? $offer : ('Day '.$day.' post'), 0, 160);
                $angle = 'feed hero for this image\'s offer';
                $postOfferByDay[$day] = $offer;
            }

            $planned[$sid] = [
                'idea' => $idea,
                'offer' => $offer,
                'angle' => $angle,
                'asset_id' => $assetId,
                'kind' => $kind,
                'day_index' => $day,
                'image_description' => $desc,
            ];
        }

        return $planned;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function example(Business $business, User $user, array $data): array
    {
        set_time_limit(180);
        $traceId = (string) \Illuminate\Support\Str::uuid();
        $business->loadMissing('agentSettings');
        $modelKey = $business->agentSettings?->llm_model;
        $this->wallets->authorizeBusinessAi($business, $this->billing->chatAuthorizeDa($modelKey));

        $mode = (string) $data['content_mode'];
        $channelIds = array_map('intval', $data['channel_ids'] ?? []);
        $platform = $this->creatives->resolvePlatform($business, $channelIds);
        $kind = $this->creatives->exampleSlotKind(is_array($data['days'] ?? null) ? $data['days'] : []);
        $focus = trim((string) ($data['focus_prompt'] ?? ''));
        $briefNotes = trim((string) ($data['brief_notes'] ?? ''));
        if ($briefNotes !== '') {
            $focus = trim($focus."\n\nOwner briefing (use this — it overrides vague parts of the focus):\n".$briefNotes);
        }

        CampaignTrace::info('campaigns.example.start', [
            'trace_id' => $traceId,
            'business_id' => $business->id,
            'user_id' => $user->id,
            'mode' => $mode,
            'platform' => $platform,
            'kind' => $kind,
            'model_key' => $modelKey,
            'channel_ids' => $channelIds,
            'has_brief_notes' => $briefNotes !== '',
            'focus_preview' => CampaignTrace::clip($focus, 600),
        ]);

        $context = '';
        $asset = null;
        $useSk = AiRuntime::usesSk($business);
        if ($mode === AiCampaign::MODE_AI_RECENT) {
            // SK tease already consults identity (+ can list_recent_posts). Skip a second
            // blocking SocialAPI list_posts here so we stay under the HTTP budget.
            if (! $useSk) {
                try {
                    $context = $this->creatives->listPostsContext($business, $channelIds);
                    CampaignTrace::info('campaigns.example.posts_context', [
                        'trace_id' => $traceId,
                        'business_id' => $business->id,
                        'context_chars' => mb_strlen($context),
                        'context_preview' => CampaignTrace::clip($context, 800),
                    ]);
                } catch (RuntimeException $e) {
                    CampaignTrace::warning('campaigns.example_posts_unavailable', [
                        'trace_id' => $traceId,
                        'business_id' => $business->id,
                        'error' => $e->getMessage(),
                        'skipped' => 'list_posts',
                    ]);
                    Log::warning('campaigns.example_posts_unavailable', ['error' => $e->getMessage()]);
                    $context = '';
                }
            }
        } else {
            $assetId = (int) ($data['asset_ids'][0] ?? 0);
            $asset = AgentAsset::query()->forBusiness($business->id)->whereKey($assetId)->first();
            if (! $asset) {
                CampaignTrace::error('campaigns.example.missing_asset', [
                    'trace_id' => $traceId,
                    'business_id' => $business->id,
                    'asset_id' => $assetId,
                ]);
                throw ValidationException::withMessages([
                    'asset_ids' => 'Upload at least one product image before generating an example.',
                ]);
            }
        }

        [$posts, $stories] = $this->totals(is_array($data['days'] ?? null) ? $data['days'] : []);
        $dayCount = max(1, (int) ($data['day_count'] ?? 1));

        try {
            if ($useSk) {
                $resolved = app(\App\AI\LlmModels\LlmModelCatalog::class)->resolve($modelKey);
                $imageUrls = [];
                if ($mode === AiCampaign::MODE_PRODUCT_IMAGES) {
                    $assetIds = array_values(array_map('intval', $data['asset_ids'] ?? []));
                    $assets = AgentAsset::query()->forBusiness($business->id)->whereIn('id', $assetIds)->get();
                    foreach ($assets as $row) {
                        $imageUrls[] = [
                            'url' => $row->absoluteUrl(),
                            'label' => 'asset_'.$row->id,
                        ];
                    }
                }
                $skDraft = $this->sk->postCrafterPlan(
                    $business,
                    $user,
                    $focus,
                    $mode,
                    $platform,
                    $kind,
                    $imageUrls,
                    $channelIds,
                    $briefNotes,
                    $resolved['model'] ?? null,
                );
                if ($skDraft['caption'] === '' || ! empty($skDraft['error'])) {
                    CampaignTrace::warning('campaigns.example.sk_postcrafter_fallback', [
                        'trace_id' => $traceId,
                        'business_id' => $business->id,
                        'error' => $skDraft['error'] ?? 'empty_caption',
                    ]);
                    $skDraft = $this->sk->campaignTease(
                        $business,
                        $user,
                        $focus,
                        $platform,
                        $kind,
                        $resolved['model'] ?? null,
                    );
                }
                if ($skDraft['caption'] === '' || ! empty($skDraft['error'])) {
                    CampaignTrace::warning('campaigns.example.sk_tease_fallback', [
                        'trace_id' => $traceId,
                        'business_id' => $business->id,
                        'error' => $skDraft['error'] ?? 'empty_caption',
                    ]);
                    $draft = $this->creatives->draft($business, $kind, $platform, $focus, $context, $asset, [
                        'day_count' => $dayCount,
                        'posts' => $posts,
                        'stories' => $stories,
                    ], null, $traceId);
                } else {
                    $planMeta = is_array($skDraft['plan_meta'] ?? null) ? $skDraft['plan_meta'] : [];
                    if ($planMeta === [] && isset($skDraft['analysis'])) {
                        $planMeta = [
                            'analysis' => $skDraft['analysis'] ?? '',
                            'plan_notes' => $skDraft['plan_notes'] ?? '',
                            'product_focus' => $skDraft['product_focus'] ?? '',
                            'tease_caption' => $skDraft['caption'] ?? '',
                            'content_mode' => $mode,
                        ];
                    } else {
                        $planMeta['tease_caption'] = $skDraft['caption'] ?? ($planMeta['tease_caption'] ?? '');
                        $planMeta['brief_notes'] = $briefNotes;
                    }
                    $draft = [
                        'title' => $skDraft['title'],
                        'caption' => $skDraft['caption'],
                        'hashtags' => $skDraft['hashtags'],
                        'image_prompt' => ($skDraft['image_prompt'] ?? '') !== ''
                            ? $skDraft['image_prompt']
                            : 'Clean Maghreb shop social creative matching the caption',
                        'usage' => $skDraft['usage'],
                        'agents' => $skDraft['agents'] ?: [
                            'planner' => 'PostCrafterAgent',
                            'identity' => 'BusinessIdentityAgent',
                            'approver' => 'PostCrafterApprover',
                        ],
                        'approver' => $skDraft['approver'],
                        'plan_meta' => $planMeta,
                    ];
                }
            } else {
                $draft = $this->creatives->draft($business, $kind, $platform, $focus, $context, $asset, [
                    'day_count' => $dayCount,
                    'posts' => $posts,
                    'stories' => $stories,
                ], null, $traceId);
            }
        } catch (Throwable $e) {
            CampaignTrace::error('campaigns.example.draft_failed', [
                'trace_id' => $traceId,
                'business_id' => $business->id,
            ], $e);
            throw $e;
        }
        $this->billing->chargeChatTurn($business, $user, $modelKey, null, $draft['usage']);

        // On-image language must follow the MARKETING caption — never raw owner focus/briefing
        // (that was painting "نديرو بوستات جداد…" / meta instructions onto the creative).
        $image = $this->creatives->generateImage(
            $business,
            $user,
            $draft['image_prompt'],
            $platform,
            $kind,
            trim((string) ($draft['caption'] ?? '')) ?: null,
            async: true,
        );

        $approver = is_array($draft['approver'] ?? null) ? $draft['approver'] : null;
        $agents = is_array($draft['agents'] ?? null) ? $draft['agents'] : [
            'planner' => 'PostCrafterAgent',
            'writer' => 'CampaignCreativeAgent',
            'approver' => 'PostCrafterApprover',
        ];
        if (! isset($agents['planner']) && ! isset($agents['brief'])) {
            $agents['planner'] = 'PostCrafterAgent';
        }

        CampaignTrace::info('campaigns.example.done', [
            'trace_id' => $traceId,
            'business_id' => $business->id,
            'title' => $draft['title'] ?? null,
            'caption' => CampaignTrace::clip((string) ($draft['caption'] ?? ''), 800),
            'hashtags' => $draft['hashtags'] ?? [],
            'image_job_id' => $image['image_job_id'] ?? null,
            'image_url_ready' => ! empty($image['image_url']),
            'image_error' => $image['image_error'] ?? null,
            'agents' => $agents,
            'approver' => $approver,
        ]);

        $span = $dayCount === 1 ? '1 day' : "{$dayCount} days";
        $bits = [];
        if ($posts > 0) {
            $bits[] = $posts.' post'.($posts === 1 ? '' : 's');
        }
        if ($stories > 0) {
            $bits[] = $stories.' stor'.($stories === 1 ? 'y' : 'ies');
        }
        $volume = $bits !== [] ? implode(' · ', $bits) : 'your schedule';

        $approverNote = '';
        if (is_array($approver)) {
            $approverNote = ! empty($approver['approved'])
                ? ' PostCrafterApprover cleared the caption.'
                : ' PostCrafterApprover flagged it for your eye — edit freely before launch.';
        }

        return [
            'title' => $draft['title'],
            'caption' => $draft['caption'],
            'hashtags' => $draft['hashtags'],
            'meta' => 'Tease of what you will get across '.$span.' ('.$volume.'). Planned by PostCrafter with vision + identity. Same voice and look for the rest of the campaign.'.$approverNote,
            'image_url' => $image['image_url'],
            'image_size' => $image['image_size'],
            'platform' => $platform,
            'image_job_id' => $image['image_url'] ? null : $image['image_job_id'],
            'image_error' => $image['image_error'],
            'image_model' => $image['model_key'] ?? null,
            'asset_id' => $image['asset']?->id,
            'trace_id' => $traceId,
            'agents' => $agents,
            'approver' => $approver,
            'plan_meta' => is_array($draft['plan_meta'] ?? null) ? $draft['plan_meta'] : null,
        ];
    }

    public function cancel(AiCampaign $campaign): AiCampaign
    {
        $campaign->update(['status' => AiCampaign::STATUS_CANCELLED]);
        $campaign->load(['slots.targets']);
        $deleted = [];

        foreach ($campaign->slots as $slot) {
            if (in_array($slot->status, [
                ...AiCampaignSlot::OPEN_STATUSES,
                AiCampaignSlot::STATUS_AWAITING_APPROVAL,
            ], true)) {
                $slot->targets()->whereIn('status', [
                    AiCampaignSlotTarget::STATUS_PENDING,
                    AiCampaignSlotTarget::STATUS_READY,
                ])->update(['status' => AiCampaignSlotTarget::STATUS_CANCELLED]);
                $slot->update(['status' => AiCampaignSlot::STATUS_CANCELLED, 'error' => null]);

                continue;
            }

            if ($slot->status !== AiCampaignSlot::STATUS_SCHEDULED || ! $slot->scheduled_at?->isFuture()) {
                continue;
            }

            $errors = [];
            foreach ($slot->targets as $target) {
                if ($target->status !== AiCampaignSlotTarget::STATUS_SCHEDULED || ! $target->socialapi_post_id) {
                    continue;
                }
                $postId = (string) $target->socialapi_post_id;
                try {
                    if (! isset($deleted[$postId])) {
                        $this->mcp->deletePost($postId);
                        $deleted[$postId] = true;
                    }
                    $target->update(['status' => AiCampaignSlotTarget::STATUS_DELETED, 'error' => null]);
                } catch (Throwable $e) {
                    $errors[] = $e->getMessage();
                    $target->update(['error' => 'Could not delete the scheduled post on SocialAPI: '.$e->getMessage()]);
                }
            }

            $slot->update($errors === []
                ? ['status' => AiCampaignSlot::STATUS_CANCELLED, 'error' => null]
                : ['error' => 'Some scheduled posts could not be removed. Delete them from Scheduled posts.']);
        }

        return $campaign->fresh(['channels', 'slots.targets.socialAccount', 'slots.asset']);
    }

    /**
     * Queue the next due slot per campaign — one at a time (no parallel drafts).
     * Skips a campaign while any slot is generating / awaiting Telegram approval / publishing.
     */
    public function dispatchDue(?AiCampaign $only = null): int
    {
        $this->resumePaused($only);

        $draftAsap = (bool) config('campaigns.draft_asap', true);
        $lookahead = max(5, (int) config('campaigns.lookahead_minutes', 180));
        $stale = now()->subMinutes(max(5, (int) config('campaigns.redispatch_after_minutes', 30)));

        $slots = AiCampaignSlot::query()
            ->whereIn('status', [AiCampaignSlot::STATUS_PENDING, AiCampaignSlot::STATUS_REGEN_REQUESTED])
            ->when(
                ! $draftAsap,
                fn ($q) => $q->where('scheduled_at', '<=', now()->addMinutes($lookahead)),
            )
            ->where(fn ($q) => $q->whereNull('dispatched_at')->orWhere('dispatched_at', '<', $stale))
            ->whereHas('campaign', fn ($q) => $q->whereIn('status', AiCampaign::ACTIVE_STATUSES))
            ->when($only, fn ($q) => $q->where('ai_campaign_id', $only->id))
            ->orderBy('scheduled_at')
            ->orderBy('id')
            ->limit(200)
            ->get();

        $busyCampaignIds = AiCampaignSlot::query()
            ->where(function ($q) use ($stale) {
                $q->whereIn('status', [
                    AiCampaignSlot::STATUS_GENERATING,
                    AiCampaignSlot::STATUS_AWAITING_APPROVAL,
                    AiCampaignSlot::STATUS_PUBLISHING,
                ])->orWhere(function ($q2) use ($stale) {
                    // Queued but not yet claimed by the worker — still blocks the next draft.
                    $q2->whereIn('status', [
                        AiCampaignSlot::STATUS_PENDING,
                        AiCampaignSlot::STATUS_REGEN_REQUESTED,
                    ])
                        ->whereNotNull('dispatched_at')
                        ->where('dispatched_at', '>=', $stale);
                });
            })
            ->when($only, fn ($q) => $q->where('ai_campaign_id', $only->id))
            ->pluck('ai_campaign_id')
            ->unique()
            ->all();
        $busyLookup = array_fill_keys(array_map('intval', $busyCampaignIds), true);

        $dispatched = 0;
        $startedCampaign = [];
        foreach ($slots as $slot) {
            $campaignId = (int) $slot->ai_campaign_id;
            if (isset($busyLookup[$campaignId]) || isset($startedCampaign[$campaignId])) {
                continue;
            }
            if ($slot->status === AiCampaignSlot::STATUS_REGEN_REQUESTED) {
                $slot->update(['status' => AiCampaignSlot::STATUS_PENDING]);
            }
            $slot->update(['dispatched_at' => now()]);
            try {
                ProcessCampaignSlot::dispatch($slot->id)->onQueue(self::queue());
                $startedCampaign[$campaignId] = true;
                $dispatched++;
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $dispatched;
    }

    /**
     * After a slot leaves the gate (accept / cancel / fail), start the next pending draft.
     */
    public function dispatchNextForCampaign(AiCampaign $campaign): int
    {
        if (! $campaign->isActive()) {
            return 0;
        }

        return $this->dispatchDue($campaign->fresh());
    }

    public function processSlot(int $slotId): void
    {
        $slot = DB::transaction(function () use ($slotId) {
            $slot = AiCampaignSlot::query()->lockForUpdate()->find($slotId);
            if (! $slot) {
                return null;
            }
            $campaign = AiCampaign::query()->find($slot->ai_campaign_id);
            if (! $campaign || ! $campaign->isActive()) {
                if ($campaign?->status === AiCampaign::STATUS_CANCELLED && in_array($slot->status, AiCampaignSlot::OPEN_STATUSES, true)) {
                    $slot->update(['status' => AiCampaignSlot::STATUS_CANCELLED]);
                }

                return null;
            }
            if (! in_array($slot->status, AiCampaignSlot::OPEN_STATUSES, true)) {
                return null;
            }

            $slot->update([
                'status' => AiCampaignSlot::STATUS_GENERATING,
                'attempts' => (int) $slot->attempts + 1,
                'client_request_key' => $slot->client_request_key ?: 'wasl-slot-'.$slot->id,
            ]);

            return $slot;
        });

        if (! $slot) {
            return;
        }

        $campaign = $slot->campaign()->with(['channels.socialAccount', 'assets.asset', 'user'])->first();
        $business = $campaign?->business()->with('agentSettings')->first();
        $actor = $campaign?->user;
        if (! $campaign || ! $business || ! $actor) {
            $this->failSlot($slot->id, 'Campaign is missing its shop or owner.');

            return;
        }

        if (in_array($campaign->status, [AiCampaign::STATUS_QUEUED, AiCampaign::STATUS_SCHEDULED], true)) {
            $campaign->update(['status' => AiCampaign::STATUS_RUNNING]);
        }

        $this->ensureTargets($slot, $campaign);
        $targets = $slot->targets()->with('socialAccount')->where('status', AiCampaignSlotTarget::STATUS_PENDING)->get();
        $context = $this->recentPostsContext($campaign, $targets);
        [$posts, $stories] = $this->slotTotals($campaign);
        $totals = ['day_count' => (int) $campaign->day_count, 'posts' => $posts, 'stories' => $stories];
        $slotBrief = ['day_index' => max(0, (int) $slot->day_index - 1), 'previous_hooks' => $this->previousHooks($campaign, $slot)];
        $storyPlatforms = (array) config('socialapi_mcp.story_platforms', ['instagram', 'facebook']);
        $mediaCache = [];
        $cost = 0.0;
        $transient = null;

        foreach ($targets->groupBy('platform') as $platform => $group) {
            /** @var Collection<int, AiCampaignSlotTarget> $group */
            $platform = (string) $platform;
            $isStory = $slot->slot_kind === AiCampaignSlot::KIND_STORY;
            if ($isStory && ! in_array($platform, $storyPlatforms, true)) {
                $this->markTargets($group, AiCampaignSlotTarget::STATUS_SKIPPED, 'Stories are not available on '.$platform.'.');

                continue;
            }

            try {
                $needImage = $campaign->content_mode === AiCampaign::MODE_AI_RECENT;
                $this->wallets->authorizeBusinessAi(
                    $business,
                    $this->estimator->captionDa($business) + ($needImage ? $this->estimator->imageDa($business) : 0.0),
                );
            } catch (InsufficientWalletException $e) {
                $this->pauseForWallet($campaign, $slot, $cost);

                return;
            }

            try {
                $asset = $campaign->content_mode === AiCampaign::MODE_PRODUCT_IMAGES ? $this->productAsset($campaign, $slot) : null;
                $focus = $this->slotFocusFromCampaign($campaign, $slot);
                $slotPlan = $this->slotPlanFor($campaign, $slot);
                $forbiddenHooks = $this->previousHooks($campaign, $slot);
                $isRegen = str_contains((string) ($slot->client_request_key ?: ''), '-re-');
                $planMeta = is_array($campaign->plan_meta) ? $campaign->plan_meta : [];
                $seed = is_array($planMeta['regen_seeds'][(string) $slot->id] ?? null)
                    ? $planMeta['regen_seeds'][(string) $slot->id]
                    : null;
                $seedCaption = trim((string) ($seed['caption'] ?? $slot->caption ?? ''));
                $seedTitle = trim((string) ($seed['title'] ?? $slot->title ?? ($slotPlan['idea'] ?? '')));

                if ($isRegen && $seedCaption !== '') {
                    $enhanced = $this->sk->campaignEnhance(
                        $business,
                        $actor,
                        $seedCaption,
                        $seedTitle,
                        [],
                        $focus,
                        $platform,
                        $slot->slot_kind,
                        (string) $campaign->content_mode,
                        $business->agentSettings?->llm_model,
                        $forbiddenHooks,
                        (string) ($slotPlan['idea'] ?? $seedTitle),
                        (string) ($slotPlan['offer'] ?? ''),
                    );
                    if (trim((string) ($enhanced['caption'] ?? '')) !== '') {
                        $draft = [
                            'title' => (string) ($enhanced['title'] ?? $seedTitle),
                            'caption' => (string) $enhanced['caption'],
                            'hashtags' => is_array($enhanced['hashtags'] ?? null) ? $enhanced['hashtags'] : [],
                            'image_prompt' => (string) ($enhanced['image_prompt'] ?? ''),
                            'usage' => is_array($enhanced['usage'] ?? null) ? $enhanced['usage'] : [],
                        ];
                        Log::channel(config('campaigns.log_channel', 'stack'))->info('campaigns.enhance.done', [
                            'slot_id' => $slot->id,
                            'notes' => mb_substr((string) ($enhanced['improvement_notes'] ?? ''), 0, 300),
                            'agents' => $enhanced['agents'] ?? [],
                        ]);
                    } else {
                        Log::channel(config('campaigns.log_channel', 'stack'))->warning('campaigns.enhance.fallback_draft', [
                            'slot_id' => $slot->id,
                            'error' => $enhanced['error'] ?? 'empty_caption',
                        ]);
                        $draft = $this->draftSlotCreative(
                            $business,
                            $actor,
                            $campaign,
                            $slot,
                            $platform,
                            $focus,
                            $context,
                            $asset,
                            $totals,
                            $slotBrief,
                            $slotPlan,
                            $forbiddenHooks,
                        );
                    }
                } else {
                    $draft = $this->draftSlotCreative(
                        $business,
                        $actor,
                        $campaign,
                        $slot,
                        $platform,
                        $focus,
                        $context,
                        $asset,
                        $totals,
                        $slotBrief,
                        $slotPlan,
                        $forbiddenHooks,
                    );
                }
                $charge = $this->billing->chargeChatTurn($business, $actor, $business->agentSettings?->llm_model, null, $draft['usage']);
                $cost += (float) ($charge?->cost_da ?? 0);

                $caption = $draft['caption'];
                $tags = $isStory ? array_slice($draft['hashtags'], 0, 2) : $draft['hashtags'];
                if ($tags !== []) {
                    $caption .= "\n\n".implode(' ', $tags);
                }
                $slot->update([
                    'title' => $draft['title'] !== '' ? $draft['title'] : null,
                    'caption' => $caption,
                ]);

                if ($isRegen && isset($planMeta['regen_seeds'][(string) $slot->id])) {
                    unset($planMeta['regen_seeds'][(string) $slot->id]);
                    $campaign->update(['plan_meta' => $planMeta]);
                }

                if ($campaign->content_mode === AiCampaign::MODE_AI_RECENT) {
                    $image = $this->creatives->generateImage(
                        $business,
                        $actor,
                        $draft['image_prompt'],
                        $platform,
                        $slot->slot_kind,
                        trim((string) ($draft['caption'] ?? '')) ?: null,
                    );
                    $cost += (float) ($image['cost_da'] ?? 0);
                    if (! $image['asset']) {
                        throw new RuntimeException($image['image_error'] ?: 'Could not generate the campaign image.');
                    }
                    $asset = $image['asset'];
                }

                $mediaIds = [];
                if ($asset) {
                    $mediaCache[$asset->id] ??= $this->mcp->uploadAsset($asset);
                    $mediaIds = [$mediaCache[$asset->id]];
                }

                // Gate: creative ready — wait for Telegram/dashboard Accept before SocialAPI schedule.
                foreach ($group as $target) {
                    $target->update([
                        'status' => AiCampaignSlotTarget::STATUS_READY,
                        'caption' => $caption,
                        'agent_asset_id' => $asset?->id,
                        'error' => null,
                        'socialapi_post_id' => null,
                    ]);
                }
                $slot->update([
                    'agent_asset_id' => $asset?->id ?? $slot->agent_asset_id,
                    'caption' => $caption,
                    'title' => $draft['title'] !== '' ? $draft['title'] : $slot->title,
                ]);
                unset($mediaIds);
            } catch (Throwable $e) {
                Log::channel(config('campaigns.log_channel', 'stack'))->warning('campaigns.slot_target_failed', [
                    'slot_id' => $slot->id,
                    'platform' => $platform,
                    'error' => $e->getMessage(),
                ]);
                if ($this->isTransient($e)) {
                    $transient = $e;
                    $group->each(fn (AiCampaignSlotTarget $t) => $t->update(['error' => $e->getMessage()]));
                } else {
                    $this->markTargets($group, AiCampaignSlotTarget::STATUS_FAILED, $e->getMessage());
                }
            }
        }

        $this->recordCost($campaign, $slot, $cost);

        if ($transient && (int) $slot->fresh()->attempts < ProcessCampaignSlot::TRIES) {
            $slot->update(['status' => AiCampaignSlot::STATUS_PENDING, 'error' => $transient->getMessage()]);
            throw $transient;
        }
        if ($transient) {
            $slot->targets()->where('status', AiCampaignSlotTarget::STATUS_PENDING)
                ->update(['status' => AiCampaignSlotTarget::STATUS_FAILED, 'error' => $transient->getMessage()]);
        }

        $this->finalizeSlot($slot);
        $slot = $slot->fresh();
        if ($slot && $slot->status === AiCampaignSlot::STATUS_AWAITING_APPROVAL) {
            try {
                app(\App\Services\Campaigns\CampaignSlotApprovalService::class)
                    ->notifyAwaitingApproval($business, $slot);
            } catch (Throwable $e) {
                Log::warning('campaigns.slot_approval_notify_failed', [
                    'slot_id' => $slot->id,
                    'error' => $e->getMessage(),
                ]);
            }
        } elseif ($slot && $slot->status === AiCampaignSlot::STATUS_FAILED) {
            try {
                app(\App\Services\Campaigns\CampaignSlotApprovalService::class)->lockTelegramActions(
                    $slot,
                    "⚠️ Generation failed\nSlot #{$slot->id}\n\n"
                    .mb_substr((string) ($slot->error ?: 'Could not draft this post.'), 0, 400)
                    ."\n\nRetry from the campaign dashboard if needed.",
                );
            } catch (Throwable) {
            }
            // Slot failed — free the campaign gate so the next post can draft.
            $this->dispatchNextForCampaign($campaign);
        }
        $this->refreshStatus($campaign->fresh());
    }

    public function failSlot(int $slotId, string $message): void
    {
        $slot = AiCampaignSlot::query()->find($slotId);
        if (! $slot || ! in_array($slot->status, AiCampaignSlot::OPEN_STATUSES, true)) {
            return;
        }
        $slot->targets()->where('status', AiCampaignSlotTarget::STATUS_PENDING)
            ->update(['status' => AiCampaignSlotTarget::STATUS_FAILED, 'error' => $message]);
        $this->finalizeSlot($slot, $message);
        if ($campaign = $slot->campaign()->first()) {
            $this->refreshStatus($campaign);
            $this->dispatchNextForCampaign($campaign);
        }
    }

    public function retrySlot(AiCampaign $campaign, AiCampaignSlot $slot): AiCampaign
    {
        if ($campaign->status === AiCampaign::STATUS_CANCELLED) {
            throw ValidationException::withMessages(['slot' => 'This campaign was cancelled.']);
        }
        $failedTargets = $slot->targets()->where('status', AiCampaignSlotTarget::STATUS_FAILED)->count();
        if ($slot->status !== AiCampaignSlot::STATUS_FAILED && $failedTargets === 0) {
            throw ValidationException::withMessages(['slot' => 'Only failed slots can be retried.']);
        }

        $slot->targets()->where('status', AiCampaignSlotTarget::STATUS_FAILED)
            ->update(['status' => AiCampaignSlotTarget::STATUS_PENDING, 'error' => null]);
        $slot->update([
            'status' => AiCampaignSlot::STATUS_PENDING,
            'error' => null,
            'attempts' => 0,
            'dispatched_at' => null,
            'scheduled_at' => $slot->scheduled_at && $slot->scheduled_at->lt(now()->addMinutes(3))
                ? now()->addMinutes(10)
                : $slot->scheduled_at,
        ]);

        if (! $campaign->isActive()) {
            $campaign->update(['status' => AiCampaign::STATUS_RUNNING]);
        }
        $this->dispatchDue($campaign);

        return $campaign->fresh(['channels', 'slots.targets.socialAccount', 'slots.asset']);
    }

    public function refreshStatus(AiCampaign $campaign): void
    {
        if (in_array($campaign->status, [AiCampaign::STATUS_CANCELLED, AiCampaign::STATUS_PAUSED_WALLET], true)) {
            return;
        }

        $slots = $campaign->slots()->with('targets')->get();
        $open = $slots->whereIn('status', [
            ...AiCampaignSlot::OPEN_STATUSES,
            AiCampaignSlot::STATUS_AWAITING_APPROVAL,
        ])->count();
        $scheduled = $slots->where('status', AiCampaignSlot::STATUS_SCHEDULED)->count();
        $failed = $slots->where('status', AiCampaignSlot::STATUS_FAILED)->count();
        $partialTargets = $slots->contains(fn (AiCampaignSlot $s) => $s->targets->contains('status', AiCampaignSlotTarget::STATUS_FAILED));

        if ($open > 0) {
            $status = ($scheduled + $failed) > 0 || $slots->contains(fn ($s) => ! in_array($s->status, [
                AiCampaignSlot::STATUS_PENDING,
                AiCampaignSlot::STATUS_AWAITING_APPROVAL,
            ], true))
                ? AiCampaign::STATUS_RUNNING
                : AiCampaign::STATUS_SCHEDULED;
        } elseif ($scheduled === 0) {
            $status = AiCampaign::STATUS_FAILED;
        } elseif ($failed > 0 || $partialTargets) {
            $status = AiCampaign::STATUS_PARTIAL;
        } else {
            $status = AiCampaign::STATUS_COMPLETED;
        }

        if ($campaign->status !== $status) {
            $campaign->update(['status' => $status]);
        }
    }

    private function resumePaused(?AiCampaign $only): void
    {
        $paused = AiCampaign::query()
            ->where('status', AiCampaign::STATUS_PAUSED_WALLET)
            ->when($only, fn ($q) => $q->whereKey($only->id))
            ->limit(50)
            ->get();

        foreach ($paused as $campaign) {
            $business = $campaign->business()->with('agentSettings')->first();
            if (! $business) {
                continue;
            }
            $need = $this->estimator->captionDa($business)
                + ($campaign->content_mode === AiCampaign::MODE_AI_RECENT ? $this->estimator->imageDa($business) : 0.0);
            try {
                $this->wallets->authorizeBusinessAi($business, $need);
            } catch (InsufficientWalletException) {
                continue;
            }
            $campaign->update(['status' => AiCampaign::STATUS_RUNNING]);
        }
    }

    private function pauseForWallet(AiCampaign $campaign, AiCampaignSlot $slot, float $cost): void
    {
        $this->recordCost($campaign, $slot, $cost);
        $slot->update(['status' => AiCampaignSlot::STATUS_PENDING, 'dispatched_at' => null, 'error' => 'Paused: wallet balance too low.']);
        $campaign->update(['status' => AiCampaign::STATUS_PAUSED_WALLET]);
        $campaign->addWarning('wallet', 'Paused because the wallet balance is too low. Top up and it resumes on its own.');
    }

    private function recordCost(AiCampaign $campaign, AiCampaignSlot $slot, float $cost): void
    {
        if ($cost <= 0) {
            return;
        }
        $slot->update(['cost_da' => round((float) $slot->fresh()->cost_da + $cost, 2)]);
        AiCampaign::query()->whereKey($campaign->id)->increment('spent_da', round($cost, 2));
    }

    private function finalizeSlot(AiCampaignSlot $slot, ?string $fallbackError = null): void
    {
        $targets = $slot->targets()->get();
        $scheduled = $targets->where('status', AiCampaignSlotTarget::STATUS_SCHEDULED);
        $ready = $targets->where('status', AiCampaignSlotTarget::STATUS_READY);
        $errors = $targets->pluck('error')->filter()->unique()->values();

        if ($scheduled->isNotEmpty()) {
            $first = $scheduled->first();
            $slot->update([
                'status' => AiCampaignSlot::STATUS_SCHEDULED,
                'caption' => $first->caption,
                'socialapi_post_id' => $first->socialapi_post_id,
                'error' => $errors->isNotEmpty() ? $errors->implode(' · ') : null,
            ]);

            try {
                $campaign = $slot->campaign()->first();
                $business = $campaign?->business;
                if ($business) {
                    app(\App\Services\Telegram\TelegramMerchantNotifier::class)->campaignSlotScheduled($business, $slot->fresh());
                }
            } catch (\Throwable) {
            }

            $this->broadcastSlot($slot);

            return;
        }

        if ($ready->isNotEmpty() && $scheduled->isEmpty()) {
            $first = $ready->first();
            $slot->update([
                'status' => AiCampaignSlot::STATUS_AWAITING_APPROVAL,
                'caption' => $first->caption ?: $slot->caption,
                'agent_asset_id' => $first->agent_asset_id ?: $slot->agent_asset_id,
                'error' => $errors->isNotEmpty() ? $errors->implode(' · ') : null,
            ]);
            $this->broadcastSlot($slot);

            return;
        }

        $slot->update([
            'status' => AiCampaignSlot::STATUS_FAILED,
            'error' => $errors->isNotEmpty() ? $errors->implode(' · ') : ($fallbackError ?: 'Nothing was scheduled.'),
        ]);
        $this->broadcastSlot($slot);
    }

    public function finalizeSlotAfterApproval(AiCampaignSlot $slot, ?string $fallbackError = null): void
    {
        $this->finalizeSlot($slot, $fallbackError);
    }

    public function publishAtForSlot(AiCampaignSlot $slot): Carbon
    {
        return $this->publishAt($slot);
    }

    private function broadcastSlot(AiCampaignSlot $slot): void
    {
        try {
            $campaign = $slot->campaign()->first();
            if ($campaign) {
                event(new \App\Events\CampaignSlotUpdated((int) $campaign->business_id, $slot->fresh(['targets.socialAccount', 'asset'])));
            }
        } catch (\Throwable) {
        }
    }

    /**
     * @param  Collection<int, AiCampaignSlotTarget>  $group
     */
    private function markTargets(Collection $group, string $status, string $error): void
    {
        foreach ($group as $target) {
            $target->update(['status' => $status, 'error' => mb_substr($error, 0, 1000)]);
        }
    }

    private function isTransient(Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return $e instanceof \Illuminate\Http\Client\ConnectionException
            || str_contains($message, 'request failed')
            || str_contains($message, 'timed out')
            || str_contains($message, 'timeout')
            || str_contains($message, 'rate limit');
    }

    private function publishAt(AiCampaignSlot $slot): Carbon
    {
        $at = $slot->scheduled_at ? Carbon::parse($slot->scheduled_at) : now();
        // Never schedule a late post as "now+5" — accept() cancels past schedule times.
        // Only bump near-future times so SocialAPI has a small lead.
        if ($at->isFuture() && $at->lt(now()->addMinutes(3))) {
            return now()->addMinutes(5);
        }

        return $at;
    }

    /**
     * @param  Collection<int, AiCampaignSlotTarget>  $targets
     */
    private function recentPostsContext(AiCampaign $campaign, Collection $targets): string
    {
        if ($campaign->content_mode !== AiCampaign::MODE_AI_RECENT || $targets->isEmpty()) {
            return '';
        }
        $ids = $targets->map(fn (AiCampaignSlotTarget $t) => (string) $t->socialAccount?->socialapi_account_id)->filter()->unique()->values()->all();
        if ($ids === []) {
            return '';
        }

        try {
            return $this->mcp->listRecentPosts($ids);
        } catch (Throwable $e) {
            $campaign->addWarning('list_posts', 'Could not read recent posts, so captions used only your brief: '.$e->getMessage());

            return '';
        }
    }

    private function previousHooks(AiCampaign $campaign, AiCampaignSlot $slot): string
    {
        $hooks = $campaign->slots()
            ->where('id', '!=', $slot->id)
            ->whereIn('status', [
                AiCampaignSlot::STATUS_SCHEDULED,
                AiCampaignSlot::STATUS_AWAITING_APPROVAL,
                AiCampaignSlot::STATUS_CANCELLED,
                AiCampaignSlot::STATUS_PUBLISHING,
                AiCampaignSlot::STATUS_PENDING,
                AiCampaignSlot::STATUS_GENERATING,
                AiCampaignSlot::STATUS_FAILED,
                AiCampaignSlot::STATUS_REGEN_REQUESTED,
            ])
            ->orderByDesc('scheduled_at')
            ->limit(12)
            ->get(['title', 'caption', 'status'])
            ->map(function (AiCampaignSlot $row) {
                $title = trim((string) $row->title);
                if ($title !== '') {
                    return $title;
                }
                $first = trim((string) strtok((string) $row->caption, "\n"));

                return $first;
            })
            ->filter()
            ->unique()
            ->take(8)
            ->values()
            ->all();

        return $hooks === [] ? '(none yet)' : '"'.implode('" | "', $hooks).'"';
    }

    private function productAsset(AiCampaign $campaign, AiCampaignSlot $slot): ?AgentAsset
    {
        if ($slot->agent_asset_id) {
            $asset = AgentAsset::query()->forBusiness($campaign->business_id)->find($slot->agent_asset_id);
            if ($asset) {
                return $asset;
            }
            Log::warning('campaigns.product_asset_missing', [
                'slot_id' => $slot->id,
                'campaign_id' => $campaign->id,
                'agent_asset_id' => $slot->agent_asset_id,
            ]);
            throw new RuntimeException('This slot\'s product image is missing. Re-launch or fix the campaign assets.');
        }

        // Stories / legacy slots without an assigned asset: reuse prior post asset on the same schedule.
        $previous = $campaign->slots()
            ->where('slot_kind', AiCampaignSlot::KIND_POST)
            ->whereNotNull('agent_asset_id')
            ->where('scheduled_at', '<=', $slot->scheduled_at)
            ->orderByDesc('scheduled_at')
            ->first();
        $assetId = $previous?->agent_asset_id ?? $campaign->assets->first()?->agent_asset_id;
        if (! $assetId) {
            return null;
        }
        $slot->update(['agent_asset_id' => $assetId]);

        return AgentAsset::query()->forBusiness($campaign->business_id)->find($assetId);
    }

    /**
     * SK cold draft with RAG when enabled; otherwise PHP CampaignCreativeService.
     *
     * @param  array{day_count: int, posts: int, stories: int}  $totals
     * @param  array{day_index: int, previous_hooks: string}  $slotBrief
     * @param  array<string, mixed>  $slotPlan
     * @return array{title: string, caption: string, hashtags: list<string>, image_prompt: string, usage: array<string, mixed>}
     */
    private function draftSlotCreative(
        Business $business,
        User $actor,
        AiCampaign $campaign,
        AiCampaignSlot $slot,
        string $platform,
        string $focus,
        string $context,
        ?AgentAsset $asset,
        array $totals,
        array $slotBrief,
        array $slotPlan,
        string $forbiddenHooks,
    ): array {
        if (AiRuntime::usesSk($business)) {
            $imageDataUrl = null;
            $imageDescription = (string) ($slotPlan['image_description'] ?? '');
            if ($asset && $asset->isImage()) {
                $imageDataUrl = $asset->dataUrl();
            }
            $skDraft = $this->sk->draftCampaignSlot(
                $business,
                $actor,
                $focus,
                $platform,
                $slot->slot_kind,
                (string) $campaign->content_mode,
                (string) ($slotPlan['idea'] ?? $slot->title ?? ''),
                (string) ($slotPlan['offer'] ?? ''),
                (string) ($slotPlan['angle'] ?? ''),
                $forbiddenHooks,
                $imageDataUrl,
                $imageDescription,
                $business->agentSettings?->llm_model,
            );
            if (trim((string) ($skDraft['caption'] ?? '')) !== '') {
                Log::channel(config('campaigns.log_channel', 'stack'))->info('campaigns.draft_slot.sk', [
                    'slot_id' => $slot->id,
                    'agents' => $skDraft['agents'] ?? [],
                    'title' => mb_substr((string) ($skDraft['title'] ?? ''), 0, 120),
                ]);

                return [
                    'title' => (string) ($skDraft['title'] ?? ''),
                    'caption' => (string) $skDraft['caption'],
                    'hashtags' => is_array($skDraft['hashtags'] ?? null) ? $skDraft['hashtags'] : [],
                    'image_prompt' => (string) ($skDraft['image_prompt'] ?? ''),
                    'usage' => is_array($skDraft['usage'] ?? null) ? $skDraft['usage'] : [],
                ];
            }
            Log::channel(config('campaigns.log_channel', 'stack'))->warning('campaigns.draft_slot.sk_fallback', [
                'slot_id' => $slot->id,
                'error' => $skDraft['error'] ?? 'empty_caption',
            ]);
        }

        return $this->creatives->draft(
            $business,
            $slot->slot_kind,
            $platform,
            $focus,
            $context,
            $asset,
            $totals,
            $slotBrief,
            'slot-'.$slot->id,
        );
    }

    private function ensureTargets(AiCampaignSlot $slot, AiCampaign $campaign): void
    {
        if ($slot->targets()->exists()) {
            return;
        }
        foreach ($campaign->channels as $channel) {
            $slot->targets()->create([
                'social_account_id' => $channel->social_account_id,
                'platform' => $this->platformOf($channel->socialAccount?->platform),
                'status' => AiCampaignSlotTarget::STATUS_PENDING,
            ]);
        }
    }

    private function platformOf(?string $platform): string
    {
        $platform = strtolower(trim((string) $platform));

        return $platform !== '' ? $platform : 'facebook';
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function slotTotals(AiCampaign $campaign): array
    {
        $slots = $campaign->slots()->get(['slot_kind']);

        return [
            $slots->where('slot_kind', AiCampaignSlot::KIND_POST)->count(),
            $slots->where('slot_kind', AiCampaignSlot::KIND_STORY)->count(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $days
     * @return array{0: int, 1: int}
     */
    private function totals(array $days): array
    {
        $posts = 0;
        $stories = 0;
        foreach ($days as $day) {
            $posts += (int) ($day['posts'] ?? 0);
            $stories += (int) ($day['stories'] ?? 0);
        }

        return [$posts, $stories];
    }

    /**
     * @param  list<array<string, mixed>>  $days
     * @param  list<int>  $assetIds
     */
    private function expandSlots(AiCampaign $campaign, array $days, Carbon $starts, array $assetIds): void
    {
        $assetCursor = 0;
        $channels = $campaign->channels;
        $makeTargets = function (AiCampaignSlot $slot) use ($channels): void {
            foreach ($channels as $channel) {
                $slot->targets()->create([
                    'social_account_id' => $channel->social_account_id,
                    'platform' => $this->platformOf($channel->socialAccount?->platform),
                    'status' => AiCampaignSlotTarget::STATUS_PENDING,
                ]);
            }
        };

        foreach ($days as $day) {
            $index = (int) ($day['day_index'] ?? 0);
            $date = $starts->copy()->addDays(max(0, $index - 1));
            $times = array_values(array_filter(array_map(
                fn ($time) => trim((string) $time),
                is_array($day['times'] ?? null) ? $day['times'] : [],
            )));
            $posts = (int) ($day['posts'] ?? 0);
            $stories = (int) ($day['stories'] ?? 0);
            $last = null;
            $lastAssetId = null;

            for ($i = 0; $i < $posts; $i++) {
                $time = $times[$i] ?? '12:00';
                [$hour, $minute] = array_pad(explode(':', $time), 2, '0');
                $at = $date->copy()->setTime((int) $hour, (int) $minute);
                $last = $at;
                $assetId = null;
                if ($campaign->content_mode === AiCampaign::MODE_PRODUCT_IMAGES && $assetIds !== []) {
                    $assetId = $assetIds[$assetCursor % count($assetIds)];
                    $assetCursor++;
                    $lastAssetId = $assetId;
                }
                $makeTargets($campaign->slots()->create([
                    'day_index' => $index,
                    'slot_kind' => AiCampaignSlot::KIND_POST,
                    'scheduled_at' => $at->copy()->utc(),
                    'status' => AiCampaignSlot::STATUS_PENDING,
                    'agent_asset_id' => $assetId,
                ]));
            }

            $anchor = $last ?: $date->copy()->setTime(12, 0);
            for ($i = 0; $i < $stories; $i++) {
                $at = $anchor->copy()->addMinutes(30 * ($i + 1));
                $storyAsset = null;
                if ($campaign->content_mode === AiCampaign::MODE_PRODUCT_IMAGES && $assetIds !== []) {
                    $storyAsset = $lastAssetId ?? $assetIds[$assetCursor % count($assetIds)];
                }
                $makeTargets($campaign->slots()->create([
                    'day_index' => $index,
                    'slot_kind' => AiCampaignSlot::KIND_STORY,
                    'scheduled_at' => $at->copy()->utc(),
                    'status' => AiCampaignSlot::STATUS_PENDING,
                    'agent_asset_id' => $storyAsset,
                ]));
            }
        }
    }
}
