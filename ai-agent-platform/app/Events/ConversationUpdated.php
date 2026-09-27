<?php

namespace App\Events;

use App\Models\Conversation;
use App\Services\ConversationService;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ConversationUpdated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(public Conversation $conversation) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('business.'.$this->conversation->business_id)];
    }

    public function broadcastAs(): string
    {
        return 'conversation.updated';
    }

    public function broadcastWith(): array
    {
        // Omit full message history — Reverb/Pusher reject oversized frames once a
        // thread has many SocialAPI messages. Live message deltas use InboxMessageCreated.
        $payload = app(ConversationService::class)->toInboxArray(
            $this->conversation->fresh(['customer.socialProfiles', 'socialAccount', 'messages'])
        );
        unset($payload['messages']);

        return [
            'conversation' => $payload,
        ];
    }
}
