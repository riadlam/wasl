<?php

namespace App\Jobs;

use App\Models\AiCampaign;
use App\Services\Campaigns\AiCampaignService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Right after launch: queue any slot already inside the lookahead window instead of waiting for the scheduler.
 */
class ProcessAiCampaign implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $campaignId) {}

    public function handle(AiCampaignService $campaigns): void
    {
        $campaign = AiCampaign::query()->find($this->campaignId);
        if (! $campaign) {
            return;
        }

        $campaigns->dispatchDue($campaign);
    }
}
