<?php

namespace App\Services\Campaigns;

use App\Events\CampaignSlotUpdated;
use App\Jobs\ProcessCampaignSlot;
use App\Models\AiCampaignSlot;
use App\Models\AiCampaignSlotTarget;
use App\Models\Business;
use App\Models\TelegramOutboundMessage;
use App\Services\Telegram\TelegramAlertService;
use App\Services\Telegram\TelegramLinkService;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class CampaignSlotApprovalService
{
    public function __construct(
        private AiCampaignService $campaigns,
        private TelegramAlertService $alerts,
        private TelegramLinkService $links,
        private CampaignMcpGateway $mcp,
    ) {}

    public function notifyAwaitingApproval(Business $business, AiCampaignSlot $slot): void
    {
        $settings = $this->links->settingsFor($business);
        $kind = TelegramOutboundMessage::KIND_CAMPAIGN_SLOT_APPROVAL;
        if (! $settings->allows($kind)) {
            Log::info('campaigns.slot_approval_telegram_skipped', [
                'slot_id' => $slot->id,
                'business_id' => $business->id,
                'reason' => ! $settings->isLinked()
                    ? 'telegram_not_linked'
                    : (! $settings->enabled ? 'telegram_disabled' : 'notify_post_events_off'),
            ]);

            return;
        }

        $text = $this->approvalReadyText($business, $slot);
        $keyboard = $this->slotKeyboard((int) $slot->id, (int) ($slot->ai_campaign_id ?: $slot->campaign?->id));
        $photo = $this->slotPhotoPayload($slot);

        // Prefer editing the same Telegram message (regen / retry) over sending a new one.
        $ok = false;
        try {
            $ok = $this->alerts->sendNow(
                $business,
                $settings,
                $kind,
                $text,
                $slot,
                $keyboard,
                $photo,
            );
        } catch (Throwable $e) {
            Log::warning('campaigns.slot_approval_telegram_failed', [
                'slot_id' => $slot->id,
                'business_id' => $business->id,
                'error' => $e->getMessage(),
            ]);
        }

        if (! $ok) {
            $this->alerts->notify(
                $business,
                $kind,
                $text,
                $slot,
                $keyboard,
                $photo,
            );
        }
    }

    public function approvalReadyText(Business $business, AiCampaignSlot $slot): string
    {
        $slot->loadMissing(['campaign', 'asset']);
        $shop = $business->name ?: 'Shop';
        $caption = trim((string) $slot->caption);
        $preview = mb_substr($caption, 0, 500);
        $when = optional($slot->scheduled_at)?->timezone($business->timezone ?: 'Africa/Algiers')?->format('D j M H:i');
        $title = trim((string) ($slot->title ?? ''));
        $hasImage = $slot->asset && $slot->asset->isImage();

        $text = "📝 Campaign post ready for approval\n"
            ."Shop: {$shop}\n"
            .'Day '.$slot->day_index.' · '.ucfirst((string) $slot->slot_kind)
            .($when ? " · {$when}" : '')
            .($title !== '' ? "\nIdea: {$title}" : '')
            .($hasImage ? "\n🖼 Post image attached above" : '')
            ."\n\n{$preview}"
            ."\n\n✏️ Edit opens Wasl with this post — Save updates this Telegram card too."
            ."\n⏱ If you Accept after the schedule time, the post is cancelled as delayed.";

        // Photo captions are capped at 1024 by Telegram.
        return mb_strlen($text) > 1024 ? mb_substr($text, 0, 1021).'…' : $text;
    }

    /**
     * @return array{bytes: string, filename: string, asset_id: int}|null
     */
    public function slotPhotoPayload(AiCampaignSlot $slot): ?array
    {
        $slot->loadMissing('asset');
        $asset = $slot->asset;
        if (! $asset || ! $asset->isImage()) {
            return null;
        }
        $bytes = $asset->rawBytes();
        if ($bytes === null || $bytes === '') {
            return null;
        }
        $name = (string) ($asset->original_name ?: ('slot-'.$slot->id.'.jpg'));
        if (! str_contains($name, '.')) {
            $name .= '.jpg';
        }

        return [
            'bytes' => $bytes,
            'filename' => $name,
            'asset_id' => (int) $asset->id,
        ];
    }

    /**
     * @return list<list<array{text: string, callback_data?: string, url?: string}>>
     */
    public function slotKeyboard(int $slotId, ?int $campaignId = null): array
    {
        $rows = [[
            ['text' => '✅ Accept', 'callback_data' => 'tg:slot:ok:'.$slotId],
            ['text' => '❌ Cancel', 'callback_data' => 'tg:slot:no:'.$slotId],
            ['text' => '🔄 Regenerate', 'callback_data' => 'tg:slot:re:'.$slotId],
        ]];

        $editUrl = $this->secureEditDeepLink($slotId);
        if ($editUrl !== null) {
            $rows[] = [[
                'text' => '✏️ Edit',
                'url' => $editUrl,
            ]];
        }

        return $rows;
    }

    /**
     * HTTPS temporary signed URL → Wasl edit modal for this slot.
     */
    public function secureEditDeepLink(int $slotId): ?string
    {
        $appUrl = rtrim((string) config('app.url'), '/');
        if (! str_starts_with($appUrl, 'https://')) {
            return null;
        }

        try {
            return \Illuminate\Support\Facades\URL::temporarySignedRoute(
                'campaigns.slot.edit-link',
                now()->addDays(7),
                ['slot' => $slotId],
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /** Push the current awaiting draft back onto the Telegram approval card (text + buttons). */
    public function refreshTelegramApproval(AiCampaignSlot $slot): void
    {
        $slot = $slot->fresh(['campaign.business', 'asset']);
        $business = $slot?->campaign?->business;
        if (! $slot || ! $business || $slot->status !== AiCampaignSlot::STATUS_AWAITING_APPROVAL) {
            return;
        }
        if (! $this->telegramLinkedForPosts($business)) {
            return;
        }

        $this->alerts->updateFor(
            $slot,
            TelegramOutboundMessage::KIND_CAMPAIGN_SLOT_APPROVAL,
            $this->approvalReadyText($business, $slot),
            $this->slotKeyboard((int) $slot->id, (int) $slot->ai_campaign_id),
            true,
            $this->slotPhotoPayload($slot),
        );
    }

    /** Clear buttons immediately so the owner cannot double-tap. */
    public function lockTelegramActions(AiCampaignSlot $slot, string $statusText): void
    {
        $this->alerts->updateFor(
            $slot,
            TelegramOutboundMessage::KIND_CAMPAIGN_SLOT_APPROVAL,
            $statusText,
            [],
            true,
        );
    }

    /**
     * Remember which Telegram message the owner tapped for this slot.
     */
    public function bindTelegramMessage(Business $business, AiCampaignSlot $slot, string $chatId, string $messageId): void
    {
        if ($chatId === '' || $messageId === '') {
            return;
        }

        TelegramOutboundMessage::query()->updateOrCreate(
            [
                'subject_type' => AiCampaignSlot::class,
                'subject_id' => $slot->getKey(),
                'kind' => TelegramOutboundMessage::KIND_CAMPAIGN_SLOT_APPROVAL,
            ],
            [
                'business_id' => $business->id,
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'metadata' => ['keyboard' => true, 'bound_from_callback' => true],
            ],
        );
    }

    public function accept(AiCampaignSlot $slot): AiCampaignSlot
    {
        $slot = $slot->fresh(['targets.socialAccount', 'campaign.business', 'asset']);
        if (! $slot || $slot->status !== AiCampaignSlot::STATUS_AWAITING_APPROVAL) {
            throw ValidationException::withMessages(['slot' => 'This slot is not awaiting approval.']);
        }

        $campaign = $slot->campaign;
        $business = $campaign?->business;
        if (! $campaign || ! $business || ! $campaign->isActive()) {
            throw ValidationException::withMessages(['slot' => 'Campaign is not active.']);
        }

        // Past schedule time → do not publish late; cancel with an explicit delay reason.
        if ($this->isPastSchedule($slot)) {
            return $this->cancelDueToDelay($slot, $business, $campaign);
        }

        $this->lockTelegramActions(
            $slot,
            "✅ Accepting…\nShop: ".($business->name ?: 'Shop')."\nSlot #{$slot->id}\n\nScheduling on SocialAPI — buttons locked.",
        );

        $targets = $slot->targets()
            ->with(['socialAccount', 'asset'])
            ->whereIn('status', [AiCampaignSlotTarget::STATUS_READY, AiCampaignSlotTarget::STATUS_PENDING])
            ->whereNotNull('caption')
            ->get();

        if ($targets->isEmpty()) {
            throw ValidationException::withMessages(['slot' => 'No ready creatives to schedule.']);
        }

        $slot->update(['status' => AiCampaignSlot::STATUS_PUBLISHING]);
        $this->broadcast($business->id, $slot);

        $mediaCache = [];
        foreach ($targets->groupBy('platform') as $platform => $group) {
            /** @var \Illuminate\Support\Collection<int, AiCampaignSlotTarget> $group */
            try {
                $first = $group->first();
                $caption = (string) ($first->caption ?: $slot->caption);
                $assetId = $first->agent_asset_id ?: $slot->agent_asset_id;
                $mediaIds = [];
                if ($assetId) {
                    $asset = $first->asset ?: $slot->asset;
                    if ($asset) {
                        $mediaCache[$asset->id] ??= $this->mcp->uploadAsset($asset);
                        $mediaIds = [$mediaCache[$asset->id]];
                    }
                }
                $accountIds = $group->map(fn (AiCampaignSlotTarget $t) => (string) $t->socialAccount?->socialapi_account_id)
                    ->filter()->values()->all();
                if ($accountIds === []) {
                    throw new RuntimeException('Channel is missing its SocialAPI account id.');
                }

                $postIds = $this->mcp->schedulePost(
                    $accountIds,
                    $caption,
                    $this->campaigns->publishAtForSlot($slot)->toIso8601String(),
                    $mediaIds,
                    $slot->slot_kind === AiCampaignSlot::KIND_STORY,
                    ($slot->client_request_key ?: 'wasl-slot-'.$slot->id).'-'.$platform.'-ok',
                );

                foreach ($group as $target) {
                    $remote = (string) $target->socialAccount?->socialapi_account_id;
                    $postId = $postIds[$remote] ?? null;
                    $target->update($postId
                        ? [
                            'status' => AiCampaignSlotTarget::STATUS_SCHEDULED,
                            'socialapi_post_id' => $postId,
                            'error' => null,
                        ]
                        : [
                            'status' => AiCampaignSlotTarget::STATUS_FAILED,
                            'error' => 'SocialAPI did not return a post for this channel.',
                        ]);
                }
            } catch (Throwable $e) {
                Log::warning('campaigns.slot_accept_failed', ['slot_id' => $slot->id, 'error' => $e->getMessage()]);
                foreach ($group as $target) {
                    $target->update(['status' => AiCampaignSlotTarget::STATUS_FAILED, 'error' => mb_substr($e->getMessage(), 0, 1000)]);
                }
            }
        }

        $this->campaigns->finalizeSlotAfterApproval($slot);
        $this->campaigns->refreshStatus($campaign->fresh());
        $slot = $slot->fresh(['targets.socialAccount', 'asset']);

        $when = optional($slot->scheduled_at)?->timezone($business->timezone ?: 'Africa/Algiers')?->format('D j M H:i');
        $preview = mb_substr(trim((string) $slot->caption), 0, 400);
        $this->alerts->updateFor(
            $slot,
            TelegramOutboundMessage::KIND_CAMPAIGN_SLOT_APPROVAL,
            '✅ Scheduled'.($when ? " for {$when}" : '')."\nSlot #{$slot->id}\n\n{$preview}",
            [],
            true,
        );

        $this->broadcast($business->id, $slot);
        $this->campaigns->dispatchNextForCampaign($campaign->fresh());

        return $slot;
    }

    /**
     * Owner edited caption/image in Wasl — save draft and refresh Telegram in parallel (still awaiting).
     *
     * @param  array{caption?: string, title?: string|null, agent_asset_id?: int|null}  $edits
     */
    public function editContent(AiCampaignSlot $slot, array $edits): AiCampaignSlot
    {
        $slot = $this->applyEdits($slot, $edits);
        $this->refreshTelegramApproval($slot);
        $businessId = (int) ($slot->campaign?->business_id ?? 0);
        if ($businessId > 0) {
            $this->broadcast($businessId, $slot);
        }

        return $slot->fresh(['targets.socialAccount', 'asset']);
    }

    /**
     * Owner edited caption/image in Wasl, then submitted = Accept.
     *
     * @param  array{caption?: string, title?: string|null, agent_asset_id?: int|null}  $edits
     */
    public function editAndAccept(AiCampaignSlot $slot, array $edits): AiCampaignSlot
    {
        $slot = $this->applyEdits($slot, $edits);

        return $this->accept($slot->fresh(['targets.socialAccount', 'campaign.business', 'asset']));
    }

    /**
     * @param  array{caption?: string, title?: string|null, agent_asset_id?: int|null}  $edits
     */
    private function applyEdits(AiCampaignSlot $slot, array $edits): AiCampaignSlot
    {
        $slot = $slot->fresh(['targets', 'campaign.business', 'asset']);
        if (! $slot || $slot->status !== AiCampaignSlot::STATUS_AWAITING_APPROVAL) {
            throw ValidationException::withMessages(['slot' => 'This slot is not awaiting approval.']);
        }

        $caption = array_key_exists('caption', $edits) ? trim((string) $edits['caption']) : trim((string) $slot->caption);
        if ($caption === '') {
            throw ValidationException::withMessages(['caption' => 'Caption cannot be empty.']);
        }
        if (mb_strlen($caption) > 2200) {
            throw ValidationException::withMessages(['caption' => 'Caption is too long (max 2200 characters).']);
        }

        $title = array_key_exists('title', $edits)
            ? (trim((string) ($edits['title'] ?? '')) ?: null)
            : $slot->title;
        $assetId = array_key_exists('agent_asset_id', $edits)
            ? ($edits['agent_asset_id'] !== null ? (int) $edits['agent_asset_id'] : null)
            : $slot->agent_asset_id;

        $slot->update([
            'caption' => $caption,
            'title' => $title,
            'agent_asset_id' => $assetId,
        ]);
        $slot->targets()
            ->whereIn('status', [AiCampaignSlotTarget::STATUS_READY, AiCampaignSlotTarget::STATUS_PENDING])
            ->update([
                'caption' => $caption,
                'agent_asset_id' => $assetId,
            ]);

        return $slot->fresh(['targets.socialAccount', 'campaign.business', 'asset']);
    }

    public function isPastSchedule(AiCampaignSlot $slot): bool
    {
        if (! $slot->scheduled_at) {
            return false;
        }

        return $slot->scheduled_at->lte(now());
    }

    private function cancelDueToDelay(AiCampaignSlot $slot, Business $business, \App\Models\AiCampaign $campaign): AiCampaignSlot
    {
        $when = optional($slot->scheduled_at)?->timezone($business->timezone ?: 'Africa/Algiers')?->format('D j M Y H:i');
        $reason = 'Cancelled on approve: schedule time'
            .($when ? " ({$when})" : '')
            .' already passed — delayed, so it was not published late. Approve before the schedule time next time, or regenerate for a future slot.';

        $this->lockTelegramActions(
            $slot,
            "⏱ Cancelled — schedule passed\nSlot #{$slot->id}\n\n{$reason}",
        );

        $slot->targets()
            ->whereIn('status', [
                AiCampaignSlotTarget::STATUS_PENDING,
                AiCampaignSlotTarget::STATUS_READY,
            ])
            ->update(['status' => AiCampaignSlotTarget::STATUS_CANCELLED, 'error' => $reason]);

        $slot->update([
            'status' => AiCampaignSlot::STATUS_CANCELLED,
            'error' => $reason,
        ]);

        $this->campaigns->refreshStatus($campaign->fresh());
        $this->broadcast((int) $business->id, $slot->fresh(['targets.socialAccount', 'asset']));
        $this->campaigns->dispatchNextForCampaign($campaign->fresh());

        return $slot->fresh(['targets.socialAccount', 'asset']);
    }

    public function cancel(AiCampaignSlot $slot): AiCampaignSlot
    {
        $slot = $slot->fresh(['targets', 'campaign']);
        if (! $slot || ! in_array($slot->status, [
            AiCampaignSlot::STATUS_AWAITING_APPROVAL,
            AiCampaignSlot::STATUS_PENDING,
            AiCampaignSlot::STATUS_REGEN_REQUESTED,
        ], true)) {
            throw ValidationException::withMessages(['slot' => 'This slot cannot be cancelled.']);
        }

        $this->lockTelegramActions(
            $slot,
            "❌ Cancelling…\nSlot #{$slot->id}\n\nButtons locked.",
        );

        $slot->targets()
            ->whereIn('status', [
                AiCampaignSlotTarget::STATUS_PENDING,
                AiCampaignSlotTarget::STATUS_READY,
            ])
            ->update(['status' => AiCampaignSlotTarget::STATUS_CANCELLED, 'error' => 'Cancelled by owner.']);

        $slot->update([
            'status' => AiCampaignSlot::STATUS_CANCELLED,
            'error' => 'Cancelled by owner.',
        ]);

        if ($campaign = $slot->campaign) {
            $this->campaigns->refreshStatus($campaign->fresh());
            $businessId = (int) $campaign->business_id;
            $this->alerts->updateFor(
                $slot,
                TelegramOutboundMessage::KIND_CAMPAIGN_SLOT_APPROVAL,
                "❌ Cancelled\nSlot #{$slot->id}\n\nThis post will not be published.",
                [],
                true,
            );
            $this->broadcast($businessId, $slot->fresh(['targets.socialAccount', 'asset']));
            $this->campaigns->dispatchNextForCampaign($campaign->fresh());
        }

        return $slot->fresh(['targets.socialAccount', 'asset']);
    }

    public function regenerate(AiCampaignSlot $slot): AiCampaignSlot
    {
        $slot = $slot->fresh(['targets', 'campaign.business']);
        if (! $slot || $slot->status !== AiCampaignSlot::STATUS_AWAITING_APPROVAL) {
            throw ValidationException::withMessages(['slot' => 'Only awaiting-approval slots can be regenerated.']);
        }

        $campaign = $slot->campaign;
        if (! $campaign || ! $campaign->isActive()) {
            throw ValidationException::withMessages(['slot' => 'Campaign is not active.']);
        }

        $shop = $campaign->business?->name ?: 'Shop';
        // Drop buttons + show generating on the SAME message immediately.
        $this->lockTelegramActions(
            $slot,
            "⏳ Generating a stronger version…\n"
            ."Shop: {$shop}\n"
            .'Day '.$slot->day_index.' · '.ucfirst((string) $slot->slot_kind)."\n"
            ."Slot #{$slot->id}\n\n"
            ."Improving this caption with your shop voice and campaign notes. Buttons return when ready.",
        );

        $seedCaption = trim((string) $slot->caption);
        $seedTitle = trim((string) ($slot->title ?? ''));
        // Stash seed on campaign plan_meta so ProcessCampaignSlot can enhance (not cold re-draft).
        if ($seedCaption !== '') {
            $planMeta = is_array($campaign->plan_meta) ? $campaign->plan_meta : [];
            $planMeta['regen_seeds'] = is_array($planMeta['regen_seeds'] ?? null) ? $planMeta['regen_seeds'] : [];
            $planMeta['regen_seeds'][(string) $slot->id] = [
                'caption' => $seedCaption,
                'title' => $seedTitle,
                'at' => now()->toIso8601String(),
            ];
            $campaign->update(['plan_meta' => $planMeta]);
        }

        $slot->targets()
            ->whereIn('status', [AiCampaignSlotTarget::STATUS_READY, AiCampaignSlotTarget::STATUS_FAILED])
            ->update([
                'status' => AiCampaignSlotTarget::STATUS_PENDING,
                'caption' => null,
                'socialapi_post_id' => null,
                'agent_asset_id' => null,
                'error' => null,
            ]);

        $slot->update([
            'status' => AiCampaignSlot::STATUS_REGEN_REQUESTED,
            // Keep caption/title as fallback seed until enhancer overwrites them.
            'socialapi_post_id' => null,
            'error' => null,
            'attempts' => 0,
            'dispatched_at' => null,
            'client_request_key' => 'wasl-slot-'.$slot->id.'-re-'.time(),
        ]);

        ProcessCampaignSlot::dispatch($slot->id)->onQueue(AiCampaignService::queue());
        $this->broadcast((int) $campaign->business_id, $slot->fresh(['targets.socialAccount', 'asset']));

        return $slot->fresh(['targets.socialAccount', 'asset']);
    }

    public function telegramLinkedForPosts(Business $business): bool
    {
        $settings = $this->links->settingsFor($business);

        return $settings->isLinked()
            && $settings->enabled
            && $settings->allows(TelegramOutboundMessage::KIND_CAMPAIGN_SLOT_APPROVAL);
    }

    private function broadcast(int $businessId, AiCampaignSlot $slot): void
    {
        try {
            event(new CampaignSlotUpdated($businessId, $slot));
        } catch (Throwable) {
        }
    }
}
