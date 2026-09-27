<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

class AgentSetting extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'language',
        'tone',
        'response_length',
        'auto_reply_comments',
        'auto_reply_dms',
        'auto_reply_reviews',
        'allow_order_creation',
        'allow_refunds',
        'require_human_approval',
        'max_actions_per_hour',
        'image_model',
        'llm_model',
        'ai_auto_publish_posts',
        'ai_auto_reply_comments',
        'ai_auto_send_dms',
        'ai_auto_reply_reviews',
        'ai_auto_moderate',
    ];

    protected function casts(): array
    {
        return [
            'auto_reply_comments' => 'boolean',
            'auto_reply_dms' => 'boolean',
            'auto_reply_reviews' => 'boolean',
            'allow_order_creation' => 'boolean',
            'allow_refunds' => 'boolean',
            'require_human_approval' => 'boolean',
            'max_actions_per_hour' => 'integer',
            'ai_auto_publish_posts' => 'boolean',
            'ai_auto_reply_comments' => 'boolean',
            'ai_auto_send_dms' => 'boolean',
            'ai_auto_reply_reviews' => 'boolean',
            'ai_auto_moderate' => 'boolean',
        ];
    }
}
