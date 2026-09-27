<?php

namespace App\AI\Memory;

use App\Models\Conversation;
use App\Models\Message;

class ConversationMemory
{
    /**
     * @return list<array{role: string, content: string}>
     */
    public function messages(Conversation $conversation, int $limit = 24): array
    {
        return $conversation->messages()
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values()
            ->map(function (Message $message) {
                $role = $message->direction === 'inbound' ? 'user' : 'assistant';

                return [
                    'role' => $role,
                    'content' => (string) $message->text,
                ];
            })
            ->all();
    }
}
