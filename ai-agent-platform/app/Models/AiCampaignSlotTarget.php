<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiCampaignSlotTarget extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_READY = 'ready';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_DELETED = 'deleted';

    protected $fillable = [
        'ai_campaign_slot_id',
        'social_account_id',
        'platform',
        'status',
        'socialapi_post_id',
        'caption',
        'agent_asset_id',
        'error',
    ];

    public function slot(): BelongsTo
    {
        return $this->belongsTo(AiCampaignSlot::class, 'ai_campaign_slot_id');
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(AgentAsset::class, 'agent_asset_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'social_account_id' => $this->social_account_id,
            'channel_name' => $this->relationLoaded('socialAccount')
                ? ($this->socialAccount?->name ?: $this->socialAccount?->username)
                : null,
            'platform' => $this->platform,
            'status' => $this->status,
            'socialapi_post_id' => $this->socialapi_post_id,
            'caption' => $this->caption,
            'image_url' => $this->relationLoaded('asset') ? $this->asset?->absoluteUrl() : null,
            'error' => $this->error,
        ];
    }
}
