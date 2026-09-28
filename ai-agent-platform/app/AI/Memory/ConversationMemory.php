<?php

namespace App\AI\Memory;

use App\Models\Conversation;
use App\Models\Message;
use Carbon\CarbonInterface;

class ConversationMemory
{
    /**
     * @return list<array{role: string, content: string, at?: string}>
     */
    public function messages(Conversation $conversation, int $limit = 24): array
    {
        $now = now();

        return $conversation->messages()
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values()
            ->map(function (Message $message) use ($now) {
                $role = $message->direction === 'inbound' ? 'user' : 'assistant';
                $at = $message->created_at;
                $iso = $at?->toIso8601String();
                $text = (string) $message->text;
                $prefix = $this->timePrefix($at, $now);

                return array_filter([
                    'role' => $role,
                    'content' => $prefix !== '' ? $prefix.$text : $text,
                    'at' => $iso,
                ], fn ($v) => $v !== null && $v !== '');
            })
            ->all();
    }

    private function timePrefix(?CarbonInterface $at, CarbonInterface $now): string
    {
        if (! $at) {
            return '';
        }

        $age = $this->ageLabel($at, $now);
        $stamp = $at->timezone(config('app.timezone', 'UTC'))->format('Y-m-d H:i');

        return "[{$stamp} · {$age}] ";
    }

    private function ageLabel(CarbonInterface $at, CarbonInterface $now): string
    {
        $seconds = max(0, $now->getTimestamp() - $at->getTimestamp());
        if ($seconds < 90) {
            return 'just now';
        }
        if ($seconds < 3600) {
            $m = (int) floor($seconds / 60);

            return $m.'m ago';
        }
        if ($seconds < 86400) {
            $h = (int) floor($seconds / 3600);

            return $h.'h ago';
        }
        $d = (int) floor($seconds / 86400);
        if ($d < 14) {
            return $d.'d ago';
        }

        return $at->timezone(config('app.timezone', 'UTC'))->format('Y-m-d');
    }
}
