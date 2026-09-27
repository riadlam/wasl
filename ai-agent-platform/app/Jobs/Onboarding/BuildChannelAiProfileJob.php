<?php

namespace App\Jobs\Onboarding;

use App\Models\Business;
use App\Services\Onboarding\ChannelAiProfileService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class BuildChannelAiProfileJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 3;

    /** Must exceed fal timeout so the DB queue does not release the job mid-request. */
    public int $timeout = 420;

    public int $uniqueFor = 3600;

    /** @var list<int> */
    public array $backoff = [20, 45, 90];

    public function __construct(public int $businessId)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'build-ai-profile-'.$this->businessId;
    }

    public function handle(ChannelAiProfileService $profiles): void
    {
        $business = Business::query()->find($this->businessId);
        if (! $business) {
            return;
        }

        if ($business->onboarding_status === Business::ONBOARDING_DONE) {
            return;
        }

        // Accept phaseone (just finished corpus) or ai_profile (retry).
        if (! in_array($business->onboarding_status, [
            Business::ONBOARDING_PHASEONE,
            Business::ONBOARDING_AI_PROFILE,
        ], true)) {
            return;
        }

        try {
            $profiles->buildForBusiness($business);
            Log::info('onboarding.profile_complete', ['business_id' => $this->businessId]);
        } catch (Throwable $e) {
            $meta = $business->fresh()->onboarding_meta ?? [];
            $business->update([
                'onboarding_status' => Business::ONBOARDING_AI_PROFILE,
                'onboarding_meta' => array_merge($meta, [
                    'error' => $e->getMessage(),
                    'profile_failed_at' => now()->toIso8601String(),
                ]),
            ]);
            Log::error('onboarding.profile_failed', [
                'business_id' => $this->businessId,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
