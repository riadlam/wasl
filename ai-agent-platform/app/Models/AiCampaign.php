<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiCampaign extends Model
{
    use BelongsToBusiness;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_RUNNING = 'running';

    public const STATUS_PAUSED_WALLET = 'paused_wallet';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const ACTIVE_STATUSES = [self::STATUS_QUEUED, self::STATUS_SCHEDULED, self::STATUS_RUNNING];

    public const MODE_AI_RECENT = 'ai_recent';

    public const MODE_PRODUCT_IMAGES = 'product_images';

    protected $fillable = [
        'business_id',
        'user_id',
        'name',
        'status',
        'content_mode',
        'focus_prompt',
        'starts_on',
        'day_count',
        'timezone',
        'warnings',
        'plan_meta',
        'estimated_da',
        'spent_da',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'day_count' => 'integer',
            'warnings' => 'array',
            'plan_meta' => 'array',
            'estimated_da' => 'float',
            'spent_da' => 'float',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function channels(): HasMany
    {
        return $this->hasMany(AiCampaignChannel::class);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(AiCampaignAsset::class)->orderBy('position');
    }

    public function slots(): HasMany
    {
        return $this->hasMany(AiCampaignSlot::class)->orderBy('scheduled_at')->orderBy('id');
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    public function addWarning(string $code, string $message): void
    {
        $warnings = is_array($this->warnings) ? $this->warnings : [];
        foreach ($warnings as $row) {
            if (($row['code'] ?? null) === $code) {
                return;
            }
        }
        $warnings[] = ['code' => $code, 'message' => mb_substr($message, 0, 300), 'at' => now()->toIso8601String()];
        $this->update(['warnings' => $warnings]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(bool $withSlots = false): array
    {
        $payload = [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status,
            'content_mode' => $this->content_mode,
            'focus_prompt' => $this->focus_prompt,
            'starts_on' => optional($this->starts_on)?->toDateString(),
            'day_count' => $this->day_count,
            'timezone' => $this->timezone,
            'channel_ids' => $this->relationLoaded('channels')
                ? $this->channels->pluck('social_account_id')->map(fn ($id) => (int) $id)->all()
                : [],
            'warnings' => is_array($this->warnings) ? $this->warnings : [],
            'plan_meta' => is_array($this->plan_meta) ? $this->plan_meta : null,
            'estimated_da' => round((float) $this->estimated_da, 2),
            'spent_da' => round((float) $this->spent_da, 2),
            'created_at' => optional($this->created_at)?->toIso8601String(),
        ];

        if ($this->relationLoaded('slots')) {
            $counts = ['total' => $this->slots->count()];
            foreach ([
                AiCampaignSlot::STATUS_PENDING,
                AiCampaignSlot::STATUS_GENERATING,
                AiCampaignSlot::STATUS_PUBLISHING,
                AiCampaignSlot::STATUS_AWAITING_APPROVAL,
                AiCampaignSlot::STATUS_REGEN_REQUESTED,
                AiCampaignSlot::STATUS_SCHEDULED,
                AiCampaignSlot::STATUS_FAILED,
                AiCampaignSlot::STATUS_CANCELLED,
            ] as $status) {
                $counts[$status] = $this->slots->where('status', $status)->count();
            }
            $payload['progress'] = $counts;
            $payload['next_slot_at'] = optional(
                $this->slots->whereIn('status', [
                    ...AiCampaignSlot::OPEN_STATUSES,
                    AiCampaignSlot::STATUS_AWAITING_APPROVAL,
                ])->sortBy('scheduled_at')->first()?->scheduled_at
            )?->toIso8601String();
        }

        if ($withSlots && $this->relationLoaded('slots')) {
            $payload['slots'] = $this->slots->map(fn (AiCampaignSlot $slot) => $slot->toApiArray())->all();
        }

        return $payload;
    }
}
