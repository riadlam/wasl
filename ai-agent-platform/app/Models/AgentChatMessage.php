<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentChatMessage extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'agent_chat_id',
        'user_id',
        'role',
        'content',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }

    public function chat(): BelongsTo
    {
        return $this->belongsTo(AgentChat::class, 'agent_chat_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
