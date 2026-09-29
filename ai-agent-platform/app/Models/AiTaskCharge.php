<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiTaskCharge extends Model
{
    public const TYPE_AGENT_CHAT = 'agent_chat';

    public const TYPE_AGENT_IMAGE = 'agent_image_gen';

    public const TYPE_AGENT_DM_REPLY = 'agent_dm_reply';

    public const TYPE_AGENT_COMMENT_REPLY = 'agent_comment_reply';

    public const TYPE_AGENT_COMMENT_PRIVATE_DM = 'agent_comment_priv_dm';

    public const TYPE_AGENT_RAG = 'agent_rag';

    public const TYPE_AGENT_TRAINING = 'agent_training';

    public const TYPE_AGENT_CAMPAIGN_PLAN = 'agent_campaign_plan';

    public const TYPE_AGENT_UTILITY = 'agent_utility';

    public const STATUS_CHARGED = 'charged';

    /**
     * @return list<string>
     */
    public static function taskTypes(): array
    {
        return [
            self::TYPE_AGENT_CHAT,
            self::TYPE_AGENT_IMAGE,
            self::TYPE_AGENT_DM_REPLY,
            self::TYPE_AGENT_COMMENT_REPLY,
            self::TYPE_AGENT_COMMENT_PRIVATE_DM,
            self::TYPE_AGENT_RAG,
            self::TYPE_AGENT_TRAINING,
            self::TYPE_AGENT_CAMPAIGN_PLAN,
            self::TYPE_AGENT_UTILITY,
        ];
    }

    protected $fillable = [
        'business_id',
        'user_id',
        'actor_user_id',
        'task_type',
        'model_key',
        'provider_model',
        'cost_usd',
        'cost_da',
        'usd_to_da',
        'status',
        'reference_type',
        'reference_id',
        'wallet_ledger_id',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'cost_usd' => 'float',
            'cost_da' => 'float',
            'usd_to_da' => 'integer',
            'meta' => 'array',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function ledger(): BelongsTo
    {
        return $this->belongsTo(WalletLedger::class, 'wallet_ledger_id');
    }
}
