<?php

namespace App\Services;

use App\Jobs\AI\ProcessIncomingMessageJob;
use App\Jobs\Social\SendFixedCommentReplyJob;
use App\Jobs\Social\SendPrivateReplyJob;
use App\Models\Business;
use App\Models\Message;
use App\Models\Workflow;
use Illuminate\Support\Facades\Bus;

/**
 * Comment engagement dispatcher.
 *
 * Default path (no workflow): shop settings `auto_reply_comments` drive AI public
 * reply + AI private DM follow-up on every comment.
 *
 * Optional post_comment workflow: per-post override (fixed public text, custom DM,
 * or disable a step). Lead-classify workflows stay separate.
 */
class PostCommentWorkflowRunner
{
    public function __construct(private WorkflowService $workflows) {}

    /**
     * @return array{agent_comment: bool, fixed_comment: bool, private_dm: bool, workflow: ?Workflow}
     */
    public function plan(Business $business, int $socialAccountId, ?string $platformPostId): array
    {
        // Shop-default: reply from settings — no workflow required.
        $defaults = [
            'agent_comment' => true,
            'fixed_comment' => false,
            'private_dm' => true,
            'workflow' => null,
        ];

        if (! $platformPostId) {
            return $defaults;
        }

        $workflow = $this->workflows->findActiveEngagementForPost(
            (int) $business->id,
            $socialAccountId,
            $platformPostId,
        );

        if (! $workflow) {
            return $defaults;
        }

        $reply = $workflow->publicReplyStep() ?? [];
        $dm = $workflow->privateDmStep() ?? [];
        $replyOn = $workflow->stepEnabled($reply);
        $dmOn = $workflow->stepEnabled($dm);
        $fixed = $replyOn && ($reply['mode'] ?? 'agent') === 'fixed';

        return [
            // Agent public reply only when workflow leaves public reply on + agent mode.
            'agent_comment' => $replyOn && ! $fixed,
            'fixed_comment' => $fixed,
            'private_dm' => $dmOn,
            'workflow' => $workflow,
        ];
    }

    public function dispatchForInbound(
        Message $message,
        Business $business,
        int $socialAccountId,
        ?string $platformPostId,
        bool $runAgent,
        bool $conversationAiEnabled,
        bool $agentOn,
        bool $classify,
        bool $dmRun,
    ): void {
        $plan = $this->plan($business, $socialAccountId, $platformPostId);
        $commentsEnabled = (bool) $business->agent?->auto_reply_comments;

        $agentComment = $plan['agent_comment']
            && $agentOn
            && $commentsEnabled;

        // Fixed workflow replies do not require auto_reply_comments.
        $fixedComment = $plan['fixed_comment'] && $runAgent && $conversationAiEnabled;

        if ($dmRun || $agentComment || ($message->type === 'comment' && $classify)) {
            ProcessIncomingMessageJob::dispatchFor($message);
        }

        if ($fixedComment) {
            Bus::dispatch(new SendFixedCommentReplyJob($message->id, $plan['workflow']?->id));
        }

        // AI / fixed private DM follow-up (settings default, or workflow override).
        if (
            $plan['private_dm']
            && $message->type === 'comment'
            && $runAgent
            && $conversationAiEnabled
            && $agentOn
            && $commentsEnabled
            && $platformPostId
        ) {
            Bus::dispatch(new SendPrivateReplyJob($message->id, $plan['workflow']?->id));
        }
    }
}
