<?php

namespace App\Events;

use App\Models\Business;
use App\Models\BusinessTelegramSetting;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TelegramMerchantLinked implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Business $business,
        public BusinessTelegramSetting $settings,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('business.'.$this->business->id)];
    }

    public function broadcastAs(): string
    {
        return 'telegram.linked';
    }

    public function broadcastWith(): array
    {
        return [
            'linked' => true,
            'telegram_username' => $this->settings->telegram_username,
            'linked_at' => $this->settings->linked_at?->toIso8601String(),
            'enabled' => (bool) $this->settings->enabled,
            'notify_new_orders' => (bool) $this->settings->notify_new_orders,
            'notify_ai_needs_human' => (bool) $this->settings->notify_ai_needs_human,
            'notify_post_events' => (bool) $this->settings->notify_post_events,
            'business_name' => $this->business->name,
        ];
    }
}
