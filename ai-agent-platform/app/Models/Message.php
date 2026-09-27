<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'conversation_id',
        'customer_id',
        'socialapi_message_id',
        'direction',
        'type',
        'sender_type',
        'sender_id',
        'text',
        'media_url',
        'media_type',
        'reply_to_message_id',
        'ai_generated',
        'ai_model',
        'status',
        'metadata',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'ai_generated' => 'boolean',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
