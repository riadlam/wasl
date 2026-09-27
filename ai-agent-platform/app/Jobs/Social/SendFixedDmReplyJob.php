<?php

namespace App\Jobs\Social;

use App\Models\Message;
use App\Models\Workflow;
use App\Services\ConversationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;

class SendFixedDmReplyJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $inboundMessageId,
        public ?int $workflowId = null,
    ) {
        $this->onQueue('social');
    }

    public function handle(ConversationService $conversations): void
    {
        $inbound = Message::query()->with('conversation')->find($this->inboundMessageId);
        if (! $inbound || $inbound->direction !== 'inbound') {
            return;
        }

        $conversation = $inbound->conversation;
        if (! $conversation) {
            return;
        }

        $workflow = $this->resolveWorkflow((int) $conversation->business_id);
        if (! $workflow || ! $workflow->isActive() || ! $workflow->isDmKeyword()) {
            return;
        }

        $step = $workflow->dmReplyStep() ?? [];
        if (($step['mode'] ?? '') !== 'fixed') {
            return;
        }

        $text = $workflow->resolvedFixedDmText($step);
        if ($text === '') {
            return;
        }

        $path = $step['image_path'] ?? null;
        $mediaUrl = is_string($path) && $path !== ''
            ? \Illuminate\Support\Facades\Storage::disk('public')->url($path)
            : null;

        $message = $conversations->storeMessage($conversation, [
            'customer_id' => $conversation->customer_id,
            'direction' => 'outbound',
            'sender_type' => 'agent',
            'type' => 'text',
            'text' => $text,
            'media_url' => $mediaUrl,
            'ai_generated' => false,
            'metadata' => array_filter([
                'kind' => 'dm_keyword_reply',
                'workflow_id' => $workflow->id,
                'fixed_reply' => true,
                'inbound_id' => $inbound->id,
            ]),
        ]);

        Bus::dispatch(new SendSocialMessageJob($message->id));
    }

    private function resolveWorkflow(int $businessId): ?Workflow
    {
        if ($this->workflowId) {
            return Workflow::query()
                ->forBusiness($businessId)
                ->where('id', $this->workflowId)
                ->first();
        }

        return null;
    }
}
