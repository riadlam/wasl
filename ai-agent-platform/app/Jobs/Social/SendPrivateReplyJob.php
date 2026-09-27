<?php

namespace App\Jobs\Social;

use App\Models\Message;
use App\Models\Workflow;
use App\Services\ConversationService;
use App\Services\SocialApi\SocialApiInboxService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendPrivateReplyJob implements ShouldQueue
{
    use Queueable;

    public const DEFAULT_TEXT = 'Thanks for your comment — we sent you a private message. How can we help?';

    public function __construct(
        public int $inboundMessageId,
        public ?int $workflowId = null,
    ) {
        $this->onQueue('social');
    }

    public function handle(ConversationService $conversations, SocialApiInboxService $inbox): void
    {
        $inbound = Message::query()->with('conversation.socialAccount')->find($this->inboundMessageId);
        if (! $inbound || $inbound->type !== 'comment') {
            return;
        }

        $conversation = $inbound->conversation;
        $accountId = $conversation?->socialAccount?->socialapi_account_id;
        $meta = is_array($inbound->metadata) ? $inbound->metadata : [];
        $postId = $conversations->extractPostId($meta);
        $commentId = $conversations->extractCommentId($meta);
        if (! $accountId || ! $postId || ! $commentId) {
            return;
        }

        $text = self::DEFAULT_TEXT;
        if ($conversation?->social_account_id) {
            $workflow = $this->resolveWorkflow(
                (int) $conversation->business_id,
                (int) $conversation->social_account_id,
                $postId,
            );
            $step = $workflow?->privateDmStep() ?? [];
            if ($workflow && $workflow->stepEnabled($step) && ($step['mode'] ?? '') === 'fixed') {
                $custom = trim((string) ($step['text'] ?? ''));
                if ($custom !== '') {
                    $text = $custom;
                }
            }
        }

        $inbox->privateReply(
            $postId,
            $accountId,
            $commentId,
            $text,
        );
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
