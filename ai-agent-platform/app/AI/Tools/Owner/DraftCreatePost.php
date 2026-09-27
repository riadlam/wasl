<?php

namespace App\AI\Tools\Owner;

use App\AI\AffirmedChatImage;
use App\AI\Tools\AgentTool;
use App\Models\AgentAsset;
use App\Models\AgentPendingAction;
use App\Models\Business;
use App\Models\SocialAccount;
use App\Services\SocialApi\SocialApiPostsService;
use App\Support\SocialPlatformLimits;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Throwable;

class DraftCreatePost implements AgentTool
{
    public function __construct(private SocialApiPostsService $posts) {}

    public function name(): string
    {
        return 'draft_create_post';
    }

    public function description(): string
    {
        return 'Draft a create/publish-now post for confirmation. Does NOT publish. Call only after the owner confirmed the caption and chose image or text-only. Requires account_ids and text. Set publish_now=true to publish after confirm. Optional asset_ids: pass the exact id of the image the owner said yes to (the private history line for THAT message). Do not pass a later regen. If asset_ids is omitted, the server uses the image they affirmed, or no image for a text-only post.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'account_ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                    'description' => 'Local social account ids from list_channels',
                ],
                'text' => [
                    'type' => 'string',
                    'description' => 'Post caption / text',
                ],
                'media_ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Optional SocialAPI media ids already uploaded',
                ],
                'asset_ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                    'description' => 'Exact agent asset ids the owner said yes to for THIS post. Not a later regeneration. Omit for text-only.',
                ],
                'scheduled_at' => [
                    'type' => 'string',
                    'description' => 'Optional ISO8601 schedule time. Ignored if publish_now is true.',
                ],
                'publish_now' => [
                    'type' => 'boolean',
                    'description' => 'If true, after confirm the post is created and published immediately.',
                ],
            ],
            'required' => ['account_ids', 'text'],
        ];
    }

    public function handle(Business $business, array $arguments, array $context = []): array
    {
        $accountIds = array_values(array_unique(array_filter(array_map(
            'intval',
            is_array($arguments['account_ids'] ?? null) ? $arguments['account_ids'] : [],
        ))));
        $text = trim((string) ($arguments['text'] ?? ''));
        $publishNow = (bool) ($arguments['publish_now'] ?? false);
        $mediaIds = array_values(array_filter(array_map(
            fn ($id) => is_string($id) ? trim($id) : '',
            is_array($arguments['media_ids'] ?? null) ? $arguments['media_ids'] : [],
        )));
        $assetIds = array_values(array_unique(array_filter(array_map(
            'intval',
            is_array($arguments['asset_ids'] ?? null) ? $arguments['asset_ids'] : [],
        ))));

        if ($assetIds === [] && is_array($context['attached_asset_ids'] ?? null)) {
            $assetIds = array_values(array_unique(array_filter(array_map('intval', $context['attached_asset_ids']))));
        }

        if ($assetIds === []) {
            $affirmed = app(AffirmedChatImage::class)->resolve(
                $business,
                trim((string) ($context['owner_brief'] ?? '')),
            );
            if (! empty($affirmed['error'])) {
                return ['error' => $affirmed['error']];
            }
            if (! empty($affirmed['apply'])) {
                $assetIds = $affirmed['asset_ids'];
            }
        }

        if ($accountIds === []) {
            return ['error' => 'account_ids is required — pick at least one connected channel.'];
        }
        if ($text === '') {
            return ['error' => 'text (caption) is required.'];
        }

        $accounts = SocialAccount::query()
            ->where('business_id', $business->id)
            ->where('provider', 'socialapi')
            ->where('platform', '!=', 'simulator')
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', '!=', 'disconnected');
            })
            ->whereIn('id', $accountIds)
            ->get();

        if ($accounts->count() !== count($accountIds)) {
            return ['error' => 'One or more account_ids are invalid or disconnected for this shop.'];
        }

        foreach ($accounts as $account) {
            if (! is_string($account->socialapi_account_id) || $account->socialapi_account_id === '') {
                return ['error' => "Account {$account->id} is missing SocialAPI id."];
            }
        }

        if ($assetIds !== []) {
            $resolved = $this->resolveAssetMediaIds($business, $assetIds);
            if (isset($resolved['error'])) {
                return $resolved;
            }
            $mediaIds = array_values(array_unique(array_merge($mediaIds, $resolved['media_ids'])));
        }

        $platforms = $accounts->pluck('platform')->filter()->values()->all();
        $maxText = SocialPlatformLimits::maxText($platforms);
        $maxMedia = SocialPlatformLimits::maxMedia($platforms);

        if (mb_strlen($text) > $maxText) {
            return ['error' => "Caption too long for selected platforms (max {$maxText} characters)."];
        }
        if (count($mediaIds) > $maxMedia) {
            return [
                'error' => $maxMedia === 0
                    ? 'Selected platforms do not support image attachments.'
                    : "Selected platforms allow at most {$maxMedia} image(s).",
            ];
        }

        $scheduledAt = null;
        if (! $publishNow && ! empty($arguments['scheduled_at'])) {
            try {
                $scheduledAt = Carbon::parse((string) $arguments['scheduled_at'])->utc()->toIso8601String();
            } catch (\Throwable) {
                return ['error' => 'scheduled_at must be a valid date/time.'];
            }
        }

        if (! $publishNow && $scheduledAt === null) {
            return ['error' => 'Provide scheduled_at or set publish_now=true.'];
        }

        AgentPendingAction::query()
            ->where('business_id', $business->id)
            ->where('status', AgentPendingAction::STATUS_PENDING)
            ->update(['status' => AgentPendingAction::STATUS_CANCELLED]);

        $labels = $accounts->map(fn (SocialAccount $a) => trim(($a->platform ?: '').' '.($a->name ?: $a->username ?: '#'.$a->id)))->implode(', ');
        $when = $publishNow ? 'publish now' : ('schedule for '.$scheduledAt);
        $mediaNote = $mediaIds === [] ? 'no media' : count($mediaIds).' media item(s)';
        $summary = "Create post on {$labels}: \"{$this->short($text)}\" — {$when}, {$mediaNote}.";

        $action = AgentPendingAction::query()->create([
            'business_id' => $business->id,
            'user_id' => $context['user_id'] ?? null,
            'type' => AgentPendingAction::TYPE_CREATE_POST,
            'payload' => [
                'account_ids' => $accountIds,
                'text' => $text,
                'media_ids' => $mediaIds,
                'asset_ids' => $assetIds,
                'scheduled_at' => $scheduledAt,
                'publish_now' => $publishNow,
            ],
            'status' => AgentPendingAction::STATUS_PENDING,
            'summary' => $summary,
        ]);

        try {
            app(\App\Services\Telegram\TelegramMerchantNotifier::class)->pendingActionCreated($action);
        } catch (\Throwable) {
        }

        return [
            'ok' => true,
            'action_id' => $action->id,
            'summary' => $summary,
            'media_ids' => $mediaIds,
            'ask_user' => 'Ask the user to confirm before calling confirm_pending_action.',
        ];
    }

    /**
     * @param  list<int>  $assetIds
     * @return array{media_ids: list<string>}|array{error: string}
     */
    private function resolveAssetMediaIds(Business $business, array $assetIds): array
    {
        $assets = AgentAsset::query()
            ->forBusiness($business->id)
            ->whereIn('id', $assetIds)
            ->get();

        if ($assets->count() !== count($assetIds)) {
            return ['error' => 'One or more asset_ids are invalid for this shop.'];
        }

        $mediaIds = [];
        foreach ($assets as $asset) {
            if (! $asset->isImage()) {
                return ['error' => "Asset {$asset->id} is not an image and cannot be attached to a post."];
            }
            try {
                $contents = Storage::disk($asset->disk ?: 'public')->get($asset->path);
                if (! is_string($contents) || $contents === '') {
                    return ['error' => "Could not read asset {$asset->id} from storage."];
                }
                $uploaded = $this->posts->uploadMedia(
                    $contents,
                    $asset->original_name ?: ('asset-'.$asset->id.'.jpg'),
                    $asset->mime,
                );
                $mediaIds[] = $uploaded['media_id'];
            } catch (Throwable $e) {
                return ['error' => 'SocialAPI media upload failed: '.$e->getMessage()];
            }
        }

        return ['media_ids' => $mediaIds];
    }

    private function short(string $text): string
    {
        $one = preg_replace('/\s+/', ' ', $text) ?: $text;

        return mb_strlen($one) > 80 ? mb_substr($one, 0, 77).'…' : $one;
    }
}
