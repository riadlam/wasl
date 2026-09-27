<?php

namespace App\Events;

use App\Models\AiCampaignSlot;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CampaignSlotUpdated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public int $businessId,
        public AiCampaignSlot $slot,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('business.'.$this->businessId)];
    }

    public function broadcastAs(): string
    {
        return 'campaign.slot.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'campaign_id' => (int) $this->slot->ai_campaign_id,
            'slot' => $this->slot->toApiArray(),
        ];
    }
}
