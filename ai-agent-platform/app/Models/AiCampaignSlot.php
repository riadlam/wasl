<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiCampaignSlot extends Model
{
    public const KIND_POST = 'post';

    public const KIND_STORY = 'story';

    public const STATUS_PENDING = 'pending';

    public const STATUS_GENERATING = 'generating';

    public const STATUS_PUBLISHING = 'publishing';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_AWAITING_APPROVAL = 'awaiting_approval';

    public const STATUS_REGEN_REQUESTED = 'regen_requested';

    public const OPEN_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_GENERATING,
        self::STATUS_PUBLISHING,
        self::STATUS_REGEN_REQUESTED,
    ];

    protected $fillable = [
        'ai_campaign_id',
        'day_index',
        'slot_kind',
        'scheduled_at',
        'status',
        'caption',
        'title',
        'socialapi_post_id',
        'error',
        'agent_asset_id',
        'cost_da',
        'attempts',
        'dispatched_at',
        'client_request_key',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'day_index' => 'integer',
            'attempts' => 'integer',
            'cost_da' => 'float',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(AiCampaign::class, 'ai_campaign_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(AgentAsset::class, 'agent_asset_id');
    }

    public function targets(): HasMany
    {
        return $this->hasMany(AiCampaignSlotTarget::class)->orderBy('id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        // Get content_pillar from campaign plan_meta if available
        $contentPillar = null;
        if ($this->relationLoaded('campaign') && $this->campaign) {
            $planMeta = is_array($this->campaign->plan_meta) ? $this->campaign->plan_meta : [];
            $slotPlans = is_array($planMeta['slot_plans'] ?? null) ? $planMeta['slot_plans'] : [];
            $slotPlan = $slotPlans[(string) $this->id] ?? $slotPlans[$this->id] ?? [];
            $contentPillar = trim((string) ($slotPlan['content_pillar'] ?? '')) ?: null;
        }

        return [
            'id' => $this->id,
            'day_index' => $this->day_index,
            'slot_kind' => $this->slot_kind,
            'scheduled_at' => optional($this->scheduled_at)?->toIso8601String(),
            'status' => $this->status,
            'title' => $this->title,
            'caption' => $this->caption,
            'content_pillar' => $contentPillar,
            'error' => $this->error !== null && $this->error !== ''
                ? \App\Support\MerchantSafeMessage::of((string) $this->error, 'This slot failed. Try again or regenerate.')
                : null,
            'agent_asset_id' => $this->agent_asset_id,
            'image_url' => $this->relationLoaded('asset') ? $this->asset?->absoluteUrl() : null,
            'cost_da' => round((float) $this->cost_da, 2),
            'attempts' => (int) $this->attempts,
            'targets' => $this->relationLoaded('targets')
                ? $this->targets->map(fn (AiCampaignSlotTarget $t) => $t->toApiArray())->all()
                : [],
        ];
    }
}
