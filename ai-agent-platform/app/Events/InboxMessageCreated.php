<?php

namespace App\Events;

use App\Models\Message;
use App\Services\ConversationService;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class InboxMessageCreated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(public Message $message) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('business.'.$this->message->business_id)];
    }

    public function broadcastAs(): string
    {
        return 'inbox.message.created';
    }

    public function broadcastWith(): array
    {
        $payload = app(ConversationService::class)->messageToInboxArray($this->message);

        return [
            'message' => array_merge($payload, [
                'conversation_id' => $this->message->conversation_id,
            ]),
        ];
    }
}
