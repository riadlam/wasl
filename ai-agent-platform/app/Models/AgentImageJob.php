<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentImageJob extends Model
{
    use BelongsToBusiness;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'business_id',
        'agent_chat_message_id',
        'user_id',
        'fal_request_id',
        'model_key',
        'endpoint',
        'image_size',
        'prompt',
        'status',
        'status_url',
        'response_url',
        'asset_id',
        'price_da',
        'cost_usd',
        'cost_da',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'price_da' => 'integer',
            'cost_usd' => 'float',
            'cost_da' => 'integer',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(AgentChatMessage::class, 'agent_chat_message_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(AgentAsset::class, 'asset_id');
    }

    /**
     * @return array{id: int, status: string, image_url: ?string, image_size: ?string, error: ?string, asset_id: ?int}
     */
    public function toPublicArray(): array
    {
        $this->loadMissing('asset');

        return [
            'id' => $this->id,
            'status' => $this->status,
            'image_url' => $this->asset?->absoluteUrl(),
            'image_size' => $this->image_size,
            'error' => $this->error,
            'asset_id' => $this->asset_id,
        ];
    }
}
