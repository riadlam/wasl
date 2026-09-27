<?php

namespace App\Console\Commands;

use App\Services\Campaigns\AiCampaignService;
use Illuminate\Console\Command;

class DispatchDueCampaignSlots extends Command
{
    protected $signature = 'campaigns:dispatch-due';

    protected $description = 'Queue AI campaign slots that publish within the lookahead window and resume wallet-paused campaigns.';

    public function handle(AiCampaignService $campaigns): int
    {
        $count = $campaigns->dispatchDue();
        $this->info("Queued {$count} campaign slot(s).");

        return self::SUCCESS;
    }
}
