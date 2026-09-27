<?php

namespace App\Jobs\AI;

use App\Models\Message;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;

/**
 * Debounce inbound AI turns per conversation so rapid messages ("ok" then "thanks")
 * produce one agent reply instead of duplicates. Checkout / Approver logic is unchanged.
 */
class ProcessIncomingMessageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 8;

    public int $timeout = 240;

    public function __construct(public int $messageId)
    {
        $this->onQueue('ai');
    }

    /**
     * Stamp latest inbound + delay the agent turn (quiet window).
     */
    public static function dispatchFor(Message $message): void
    {
        $seconds = max(0, (int) config('ai_runtime.inbound_debounce_seconds', 3));
        $conversationId = (int) $message->conversation_id;

        if ($seconds > 0 && $conversationId > 0) {
            Cache::put(self::latestKey($conversationId), (int) $message->id, now()->addMinutes(15));
            Cache::put(self::atKey($conversationId), now()->getTimestamp(), now()->addMinutes(15));
            Bus::dispatch((new self((int) $message->id))->delay(now()->addSeconds($seconds)));

            return;
        }

        Bus::dispatch(new self((int) $message->id));
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        $conversationId = (int) (Message::query()->whereKey($this->messageId)->value('conversation_id') ?: $this->messageId);

        return [
            (new WithoutOverlapping('ai-inbound-conv-'.$conversationId))
                ->releaseAfter(15)
                ->expireAfter(300),
        ];
    }

    public function handle(): void
    {
        $message = Message::query()->find($this->messageId);
        if (! $message || $message->direction !== 'inbound') {
            return;
        }

        $seconds = max(0, (int) config('ai_runtime.inbound_debounce_seconds', 3));
        $conversationId = (int) $message->conversation_id;

        if ($seconds > 0 && $conversationId > 0) {
            $latestId = (int) Cache::get(self::latestKey($conversationId), $this->messageId);
            if ($latestId !== (int) $this->messageId) {
                // A newer inbound owns the quiet window — that job will answer once.
                return;
            }

            $lastAt = (int) Cache::get(self::atKey($conversationId), 0);
            if ($lastAt > 0) {
                $remaining = $seconds - (now()->getTimestamp() - $lastAt);
                if ($remaining > 0) {
                    $this->release($remaining);

                    return;
                }
            }

            // DB safety net if cache was lost but a newer inbound already exists.
            $hasNewerInbound = Message::query()
                ->where('conversation_id', $conversationId)
                ->where('direction', 'inbound')
                ->where('id', '>', $message->id)
                ->exists();
            if ($hasNewerInbound) {
                return;
            }
        }

        RunAgentJob::dispatchSync($message->id);
    }

    public static function latestKey(int $conversationId): string
    {
        return 'ai:inbound-debounce:latest:'.$conversationId;
    }

    public static function atKey(int $conversationId): string
    {
        return 'ai:inbound-debounce:at:'.$conversationId;
    }
}
