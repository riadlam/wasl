<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AgentChat extends Model
{
    use BelongsToBusiness;
    use SoftDeletes;

    protected $fillable = [
        'business_id',
        'user_id',
        'title',
        'last_message_at',
    ];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AgentChatMessage::class, 'agent_chat_id');
    }

    public function touchActivity(?string $title = null): void
    {
        if ($title !== null && $title !== '' && ($this->title === null || $this->title === '' || $this->title === 'New chat')) {
            $this->title = mb_substr($title, 0, 60);
        }
        $this->last_message_at = now();
        $this->save();
    }

    /**
     * @return array{id: int, title: string, last_message_at: ?string, created_at: ?string}
     */
    public function toListArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title ?: 'New chat',
            'last_message_at' => optional($this->last_message_at ?? $this->updated_at)?->toIso8601String(),
            'created_at' => optional($this->created_at)?->toIso8601String(),
        ];
    }
}
