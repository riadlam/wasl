<?php

namespace App\Jobs;

use App\Services\Campaigns\AiCampaignService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessCampaignSlot implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public const TRIES = 3;

    public int $tries = self::TRIES;

    public int $timeout = 600;

    public int $uniqueFor = 1800;

    public function __construct(public int $slotId) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function uniqueId(): string
    {
        return 'campaign-slot-'.$this->slotId;
    }

    public function handle(AiCampaignService $campaigns): void
    {
        $campaigns->processSlot($this->slotId);
    }

    public function failed(?Throwable $e): void
    {
        app(AiCampaignService::class)->failSlot($this->slotId, $e?->getMessage() ?: 'The slot job failed.');
    }
}
