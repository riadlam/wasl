<?php

namespace App\Services;

use App\Jobs\Onboarding\BuildChannelAiProfileJob;
use App\Jobs\Onboarding\TrainBusinessOnboardingJob;
use App\Models\AiProfilePerChannel;
use App\Models\Business;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Log;

class OnboardingService
{
    /**
     * Call after any SocialAPI account becomes connected.
     * Starts AI training only when leaving pure onboarding (first real channel).
     */
    public function onChannelConnected(Business $business, ?SocialAccount $account = null): void
    {
        $business->refresh();

        if (in_array($business->onboarding_status, [
            Business::ONBOARDING_PHASEONE,
            Business::ONBOARDING_AI_PROFILE,
            Business::ONBOARDING_DONE,
        ], true)) {
            return;
        }

        if (! $this->hasLiveChannel($business)) {
            return;
        }

        if ($business->onboarding_status === Business::ONBOARDING) {
            $business->update([
                'onboarding_status' => Business::ONBOARDING_WAITING_AI_TRAINING,
                'onboarding_meta' => array_merge($business->onboarding_meta ?? [], [
                    'started_at' => now()->toIso8601String(),
                    'trigger_account_id' => $account?->id,
                    'posts' => 0,
                    'comments' => 0,
                    'chats' => 0,
                    'error' => null,
                ]),
            ]);

            TrainBusinessOnboardingJob::dispatch($business->id);
            Log::info('onboarding.training_dispatched', ['business_id' => $business->id]);

            return;
        }

        // Already waiting — ensure a job is queued (idempotent re-dispatch is OK; job guards itself).
        if ($business->onboarding_status === Business::ONBOARDING_WAITING_AI_TRAINING) {
            TrainBusinessOnboardingJob::dispatch($business->id);
        }
    }

    /**
     * Last live channel gone: keep training progress (do not reset to onboarding).
     * Resetting to onboarding caused reconnect to re-import DMs and duplicate the inbox.
     */
    public function onChannelDisconnected(Business $business): void
    {
        $business->refresh();

        if ($this->hasLiveChannel($business)) {
            return;
        }

        $business->update([
            'onboarding_meta' => array_merge($business->onboarding_meta ?? [], [
                'last_channel_disconnected_at' => now()->toIso8601String(),
            ]),
        ]);

        Log::info('onboarding.channel_disconnected_keep_status', [
            'business_id' => $business->id,
            'status' => $business->onboarding_status,
        ]);
    }

    private function hasLiveChannel(Business $business): bool
    {
        return SocialAccount::query()
            ->where('business_id', $business->id)
            ->where('platform', '!=', 'simulator')
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', '!=', 'disconnected');
            })
            ->exists();
    }

    /**
     * After adding Pages to a shop that already finished onboarding, queue profile
     * rebuild so each new page gets its own ai_profiles_perchannel JSON object.
     *
     * @param  list<SocialAccount>  $accounts
     */
    public function ensureProfilesForAccounts(Business $business, array $accounts): void
    {
        $business->refresh();
        if ($business->onboarding_status !== Business::ONBOARDING_DONE) {
            return;
        }

        $missing = false;
        foreach ($accounts as $account) {
            if (! $account instanceof SocialAccount) {
                continue;
            }
            $exists = AiProfilePerChannel::query()
                ->where('business_id', $business->id)
                ->where('social_account_id', $account->id)
                ->where('status', AiProfilePerChannel::STATUS_READY)
                ->exists();
            if (! $exists) {
                $missing = true;
                break;
            }
        }

        if (! $missing) {
            return;
        }

        // Re-enter profile phase so BuildChannelAiProfileJob will run for all channels.
        $business->update([
            'onboarding_status' => Business::ONBOARDING_PHASEONE,
            'onboarding_meta' => array_merge($business->onboarding_meta ?? [], [
                'reprofile_at' => now()->toIso8601String(),
                'error' => null,
            ]),
        ]);

        BuildChannelAiProfileJob::dispatch($business->id);
        Log::info('onboarding.reprofile_dispatched', [
            'business_id' => $business->id,
            'accounts' => collect($accounts)->pluck('id')->all(),
        ]);
    }
}
