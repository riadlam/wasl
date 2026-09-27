<?php

namespace App\Services;

use App\Jobs\AI\ProcessIncomingMessageJob;
use App\Jobs\Social\SendFixedCommentReplyJob;
use App\Jobs\Social\SendPrivateReplyJob;
use App\Models\Business;
use App\Models\Message;
use App\Models\Workflow;
use Illuminate\Support\Facades\Bus;

class PostCommentWorkflowRunner
{
    public function __construct(private WorkflowService $workflows) {}

    /**
     * Decide which jobs to queue for a comment on a post.
     *
     * @return array{agent_comment: bool, fixed_comment: bool, private_dm: bool, workflow: ?Workflow}
     */
    public function plan(Business $business, int $socialAccountId, ?string $platformPostId): array
    {
        $empty = [
            'agent_comment' => false,
            'fixed_comment' => false,
            'private_dm' => false,
            'workflow' => null,
        ];

        if (! $platformPostId) {
            return $empty;
        }

        $workflow = $this->workflows->findActiveEngagementForPost(
            (int) $business->id,
            $socialAccountId,
            $platformPostId,
        );

        if (! $workflow) {
            return $empty;
        }

        $reply = $workflow->publicReplyStep() ?? [];
        $dm = $workflow->privateDmStep() ?? [];
        $replyOn = $workflow->stepEnabled($reply);
        $dmOn = $workflow->stepEnabled($dm);
        $fixed = $replyOn && ($reply['mode'] ?? 'agent') === 'fixed';

        return [
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

        $agentComment = $plan['agent_comment']
            && $agentOn
            && (bool) $business->agent?->auto_reply_comments;

        // Agent comment replies still require auto_reply_comments; fixed replies do not.
        $fixedComment = $plan['fixed_comment'] && $runAgent && $conversationAiEnabled;

        if ($dmRun || $agentComment || ($message->type === 'comment' && $classify)) {
            ProcessIncomingMessageJob::dispatchFor($message);
        }

        if ($fixedComment) {
            Bus::dispatch(new SendFixedCommentReplyJob($message->id, $plan['workflow']?->id));
        }

        if (
            $plan['private_dm']
            && $message->type === 'comment'
            && $runAgent
            && $conversationAiEnabled
            && $platformPostId
        ) {
            Bus::dispatch(new SendPrivateReplyJob($message->id, $plan['workflow']?->id));
        }
    }
}
