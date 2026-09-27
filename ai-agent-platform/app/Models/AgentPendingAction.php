<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentPendingAction extends Model
{
    use BelongsToBusiness;

    public const STATUS_PENDING = 'pending';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_FAILED = 'failed';

    public const TYPE_CREATE_POST = 'create_post';

    public const TYPE_MCP = 'mcp';

    public const TYPE_AI_CAMPAIGN = 'ai_campaign';

    protected $fillable = [
        'business_id',
        'user_id',
        'type',
        'payload',
        'status',
        'summary',
        'result',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'result' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }
}
