<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgentRun extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'conversation_id',
        'message_id',
        'status',
        'model',
        'provider',
        'input_tokens',
        'output_tokens',
        'started_at',
        'completed_at',
        'error',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'metadata' => 'array',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function toolCalls(): HasMany
    {
        return $this->hasMany(AgentToolCall::class);
    }
}
