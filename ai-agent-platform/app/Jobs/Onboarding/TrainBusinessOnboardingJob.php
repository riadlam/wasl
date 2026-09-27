<?php

namespace App\Jobs\Onboarding;

use App\Models\Business;
use App\Services\Onboarding\OnboardingTrainingService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class TrainBusinessOnboardingJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 5;

    public int $uniqueFor = 3600;

    /** @var list<int> */
    public array $backoff = [30, 60, 120, 300];

    public function __construct(public int $businessId)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'train-onboarding-'.$this->businessId;
    }

    public function handle(OnboardingTrainingService $training): void
    {
        $business = Business::query()->find($this->businessId);
        if (! $business) {
            return;
        }

        if ($business->onboarding_status === Business::ONBOARDING_PHASEONE
            || $business->onboarding_status === Business::ONBOARDING_AI_PROFILE
            || $business->onboarding_status === Business::ONBOARDING_DONE) {
            return;
        }

        if ($business->onboarding_status !== Business::ONBOARDING_WAITING_AI_TRAINING) {
            // Ensure we are in waiting state if somehow still onboarding with a channel.
            if ($business->onboarding_status === Business::ONBOARDING) {
                $business->update(['onboarding_status' => Business::ONBOARDING_WAITING_AI_TRAINING]);
                $business->refresh();
            } else {
                return;
            }
        }

        try {
            $training->train($business);
            Log::info('onboarding.training_complete', ['business_id' => $this->businessId]);
        } catch (Throwable $e) {
            $meta = $business->fresh()->onboarding_meta ?? [];
            $business->update([
                'onboarding_status' => Business::ONBOARDING_WAITING_AI_TRAINING,
                'onboarding_meta' => array_merge($meta, [
                    'error' => $e->getMessage(),
                    'failed_at' => now()->toIso8601String(),
                ]),
            ]);
            Log::error('onboarding.training_failed', [
                'business_id' => $this->businessId,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
