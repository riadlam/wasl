<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

class BusinessTelegramSetting extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'telegram_chat_id',
        'telegram_username',
        'linked_at',
        'enabled',
        'notify_new_orders',
        'notify_ai_needs_human',
        'notify_post_events',
    ];

    protected function casts(): array
    {
        return [
            'linked_at' => 'datetime',
            'enabled' => 'boolean',
            'notify_new_orders' => 'boolean',
            'notify_ai_needs_human' => 'boolean',
            'notify_post_events' => 'boolean',
        ];
    }

    public function isLinked(): bool
    {
        return filled($this->telegram_chat_id);
    }

    public function allows(string $kind): bool
    {
        if (! $this->enabled || ! $this->isLinked()) {
            return false;
        }

        return match ($kind) {
            TelegramOutboundMessage::KIND_ORDER_CREATED => (bool) $this->notify_new_orders,
            TelegramOutboundMessage::KIND_AI_NEEDS_HUMAN => (bool) $this->notify_ai_needs_human,
            TelegramOutboundMessage::KIND_PENDING_POST,
            TelegramOutboundMessage::KIND_POST_SCHEDULED,
            TelegramOutboundMessage::KIND_CAMPAIGN_SLOT_APPROVAL => (bool) $this->notify_post_events,
            default => true,
        };
    }
}
