<?php

namespace App\Console\Commands;

use App\Jobs\Onboarding\BuildChannelAiProfileJob;
use App\Models\Business;
use Illuminate\Console\Command;

class RebuildBusinessIdentityCommand extends Command
{
    protected $signature = 'ai:rebuild-identity
                            {business? : Business id (omit for all with training snapshots)}
                            {--sync : Run ChannelAiProfileService inline}';

    protected $description = 'Rebuild channel identity into Supabase vectors via BusinessIdentityAgent';

    public function handle(): int
    {
        $ids = $this->argument('business')
            ? [(int) $this->argument('business')]
            : Business::query()->orderBy('id')->pluck('id')->all();

        foreach ($ids as $id) {
            $business = Business::query()->find($id);
            if (! $business) {
                continue;
            }

            if ($this->option('sync')) {
                // Force status so the job-equivalent path can run.
                if ($business->onboarding_status === Business::ONBOARDING_DONE) {
                    $business->update(['onboarding_status' => Business::ONBOARDING_PHASEONE]);
                }
                app(\App\Services\Onboarding\ChannelAiProfileService::class)->buildForBusiness($business->fresh());
                $this->info("Rebuilt identity for business {$id}");
            } else {
                if ($business->onboarding_status === Business::ONBOARDING_DONE) {
                    $business->update(['onboarding_status' => Business::ONBOARDING_PHASEONE]);
                }
                dispatch(new BuildChannelAiProfileJob((int) $id));
                $this->info("Queued identity rebuild for business {$id}");
            }
        }

        return self::SUCCESS;
    }
}
