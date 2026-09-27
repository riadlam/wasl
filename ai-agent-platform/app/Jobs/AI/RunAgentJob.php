<?php

namespace App\Jobs\AI;

use App\AI\Agents\BusinessAgent;
use App\Models\Message;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunAgentJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $messageId)
    {
        $this->onQueue('ai');
    }

    public function handle(BusinessAgent $agent): void
    {
        $message = Message::query()->find($this->messageId);
        if (! $message) {
            return;
        }

        $agent->run($message);
    }
}
