<?php

namespace App\Services;

use App\Jobs\AI\ProcessIncomingMessageJob;
use App\Jobs\Social\SendFixedDmReplyJob;
use App\Models\Business;
use App\Models\Message;
use App\Models\Workflow;
use Illuminate\Support\Facades\Bus;

class DmKeywordWorkflowRunner
{
    public function __construct(private WorkflowService $workflows) {}

    /**
     * @return array{matched: bool, fixed: bool, agent: bool, workflow: ?Workflow}
     */
    public function plan(Business $business, string $platform, string $text): array
    {
        $empty = [
            'matched' => false,
            'fixed' => false,
            'agent' => false,
            'workflow' => null,
        ];

        $workflow = $this->workflows->findActiveDmKeywordWorkflow($business, $platform, $text);
        if (! $workflow) {
            return $empty;
        }

        $step = $workflow->dmReplyStep() ?? [];
        $mode = ($step['mode'] ?? 'fixed') === 'agent' ? 'agent' : 'fixed';

        return [
            'matched' => true,
            'fixed' => $mode === 'fixed',
            'agent' => $mode === 'agent',
            'workflow' => $workflow,
        ];
    }

    /**
     * @return bool True when a keyword automation handled (or will handle) this DM
     */
    public function dispatchForInbound(
        Message $message,
        Business $business,
        string $platform,
        string $text,
        bool $agentOn,
        bool $classify,
    ): bool {
        $plan = $this->plan($business, $platform, $text);

        if (! $plan['matched']) {
            return false;
        }

        if ($plan['fixed'] && $plan['workflow']) {
            Bus::dispatch(new SendFixedDmReplyJob($message->id, $plan['workflow']->id));

            if ($classify) {
                ProcessIncomingMessageJob::dispatchFor($message);
            }

            return true;
        }

        if ($plan['agent'] && $agentOn) {
            ProcessIncomingMessageJob::dispatchFor($message);

            return true;
        }

        if ($classify) {
            ProcessIncomingMessageJob::dispatchFor($message);
        }

        return true;
    }
}
