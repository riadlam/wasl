<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

class Agent extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'name',
        'system_prompt',
        'language',
        'tone',
        'ai_enabled',
        'auto_reply_comments',
        'auto_reply_dms',
        'auto_reply_whatsapp',
        'auto_publish',
        'human_approval_required',
    ];

    protected function casts(): array
    {
        return [
            'ai_enabled' => 'boolean',
            'auto_reply_comments' => 'boolean',
            'auto_reply_dms' => 'boolean',
            'auto_reply_whatsapp' => 'boolean',
            'auto_publish' => 'boolean',
            'human_approval_required' => 'boolean',
        ];
    }
}
