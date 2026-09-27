<?php

namespace App\Jobs\Social;

use App\Models\Message;
use App\Models\Workflow;
use App\Services\ConversationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;

class SendFixedCommentReplyJob implements ShouldQueue
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
        $inbound = Message::query()->with('conversation.socialAccount')->find($this->inboundMessageId);
        if (! $inbound || $inbound->type !== 'comment') {
            return;
        }

        $conversation = $inbound->conversation;
        if (! $conversation || ! $conversation->social_account_id) {
            return;
        }

        $meta = is_array($inbound->metadata) ? $inbound->metadata : [];
        $postId = $conversations->extractPostId($meta);
        if (! $postId) {
            return;
        }

        $workflow = $this->resolveWorkflow($conversation->business_id, $conversation->social_account_id, $postId);
        if (! $workflow) {
            return;
        }

        $step = $workflow->publicReplyStep() ?? [];
        if (! $workflow->stepEnabled($step) || ($step['mode'] ?? '') !== 'fixed') {
            return;
        }

        $text = $workflow->resolvedFixedCommentText($step);
        if ($text === '') {
            return;
        }

        $message = $conversations->storeMessage($conversation, [
            'customer_id' => $conversation->customer_id,
            'direction' => 'outbound',
            'sender_type' => 'agent',
            'type' => 'comment',
            'text' => $text,
            'ai_generated' => false,
            'metadata' => array_filter([
                'kind' => 'comment_reply',
                'post_id' => $postId,
                'comment_id' => $conversations->extractCommentId($meta),
                'fixed_reply' => true,
                'workflow_id' => $workflow->id,
                'fixed_comment_image_path' => $step['image_path'] ?? null,
                'inbound' => $meta ?: null,
            ]),
        ]);

        Bus::dispatch(new SendSocialMessageJob($message->id));
    }

    private function resolveWorkflow(int $businessId, int $socialAccountId, string $postId): ?Workflow
    {
        if ($this->workflowId) {
            $workflow = Workflow::query()
                ->forBusiness($businessId)
                ->where('id', $this->workflowId)
                ->first();
            if ($workflow?->isActive() && $workflow->isEngagement()) {
                return $workflow;
            }
        }

        return app(\App\Services\WorkflowService::class)
            ->findActiveEngagementForPost($businessId, $socialAccountId, $postId);
    }
}
