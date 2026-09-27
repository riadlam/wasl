<?php

namespace Tests\Feature;

use App\AI\Prompts\BusinessAgentPrompt;
use App\Jobs\Onboarding\BuildChannelAiProfileJob;
use App\Models\AiProfilePerChannel;
use App\Models\Business;
use App\Models\BusinessTrainingSnapshot;
use App\Models\SocialAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OnboardingAiProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'ai_runtime.url' => 'http://runtime.test',
            'ai_runtime.service_key' => 'secret',
            'ai_runtime.timeout' => 5,
        ]);
    }

    public function test_profile_job_stores_status_row_and_reaches_done(): void
    {
        ['business' => $business] = $this->makeShop();
        $account = SocialAccount::query()->create([
            'business_id' => $business->id,
            'provider' => 'socialapi',
            'socialapi_account_id' => 'acc_ig_profile',
            'platform' => 'instagram',
            'name' => 'Profile IG',
            'username' => 'profile.ig',
            'status' => 'connected',
            'connected_at' => now(),
        ]);

        BusinessTrainingSnapshot::query()->create([
            'business_id' => $business->id,
            'social_account_id' => $account->id,
            'source' => 'page',
            'external_id' => 'acc_ig_profile',
            'platform' => 'instagram',
            'title' => 'Profile IG',
            'content' => 'Name: Profile IG',
            'payload' => ['name' => 'Profile IG', 'username' => 'profile.ig'],
        ]);
        BusinessTrainingSnapshot::query()->create([
            'business_id' => $business->id,
            'social_account_id' => $account->id,
            'source' => 'post',
            'external_id' => '1789001',
            'platform' => 'instagram',
            'title' => 'Sneakers drop',
            'content' => 'Air Max available in Oran',
            'payload' => ['post' => ['caption' => 'Air Max available in Oran'], 'metrics' => ['likes' => 10]],
        ]);

        $business->update([
            'onboarding_status' => Business::ONBOARDING_PHASEONE,
            'onboarding_meta' => [
                'account_ids' => [$account->id],
                'completed_at' => now()->toIso8601String(),
            ],
        ]);

        Http::fake([
            'runtime.test/v1/identity/build' => Http::response([
                'ok' => true,
                'chunks_upserted' => 6,
                'namespaces' => ['brand', 'tone', 'policies', 'faqs'],
                'summary' => 'Sneaker shop in Oran',
                'storage' => 'supabase',
            ], 200),
        ]);

        (new BuildChannelAiProfileJob($business->id))->handle(app(\App\Services\Onboarding\ChannelAiProfileService::class));

        $business->refresh();
        $this->assertSame(Business::ONBOARDING_DONE, $business->onboarding_status);

        $row = AiProfilePerChannel::query()
            ->where('business_id', $business->id)
            ->where('social_account_id', $account->id)
            ->first();

        $this->assertNotNull($row);
        $this->assertSame(AiProfilePerChannel::STATUS_READY, $row->status);
        $this->assertSame('supabase', $row->profile['storage'] ?? null);
        $this->assertSame(6, $row->profile['chunks_upserted'] ?? null);
        $this->assertSame('Sneaker shop in Oran', $row->profile['summary'] ?? null);
        $this->assertArrayNotHasKey('business', $row->profile);
        $this->assertArrayNotHasKey('hard_constraints', $row->profile);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/identity/build')
            && ($request->data()['social_account_id'] ?? null) === $account->id
            && str_contains(json_encode($request->data()['corpus'] ?? []), 'Air Max available in Oran'));
    }

    public function test_identity_build_failure_keeps_ai_profile_status(): void
    {
        ['business' => $business] = $this->makeShop();
        $account = SocialAccount::query()->create([
            'business_id' => $business->id,
            'provider' => 'socialapi',
            'socialapi_account_id' => 'acc_ig_bad',
            'platform' => 'instagram',
            'name' => 'Bad IG',
            'status' => 'connected',
            'connected_at' => now(),
        ]);

        BusinessTrainingSnapshot::query()->create([
            'business_id' => $business->id,
            'social_account_id' => $account->id,
            'source' => 'page',
            'external_id' => 'acc_ig_bad',
            'platform' => 'instagram',
            'title' => 'Bad IG',
            'content' => 'Name: Bad IG',
            'payload' => [],
        ]);

        $business->update([
            'onboarding_status' => Business::ONBOARDING_PHASEONE,
            'onboarding_meta' => ['account_ids' => [$account->id]],
        ]);

        Http::fake([
            'runtime.test/v1/identity/build' => Http::response([
                'ok' => false,
                'error' => 'embed_failed',
            ], 200),
        ]);

        try {
            (new BuildChannelAiProfileJob($business->id))->handle(app(\App\Services\Onboarding\ChannelAiProfileService::class));
            $this->fail('Expected exception for identity build failure');
        } catch (\Throwable) {
            // expected
        }

        $business->refresh();
        $this->assertSame(Business::ONBOARDING_AI_PROFILE, $business->onboarding_status);
        $this->assertNotEmpty($business->onboarding_meta['error'] ?? null);
        $this->assertDatabaseCount('ai_profiles_perchannel', 0);
    }

    public function test_agent_prompt_points_at_supabase_identity(): void
    {
        ['business' => $business] = $this->makeShop();
        $account = SocialAccount::query()->create([
            'business_id' => $business->id,
            'provider' => 'socialapi',
            'socialapi_account_id' => 'acc_ig_prompt',
            'platform' => 'instagram',
            'name' => 'Prompt IG',
            'status' => 'connected',
            'connected_at' => now(),
        ]);

        $profile = AiProfilePerChannel::query()->create([
            'business_id' => $business->id,
            'social_account_id' => $account->id,
            'platform' => 'instagram',
            'profile' => [
                'storage' => 'supabase',
                'summary' => 'Friendly sneaker shop',
                'namespaces' => ['brand', 'tone'],
                'chunks_upserted' => 4,
            ],
            'model' => 'business-identity-agent+fal-embeddings',
            'source_counts' => ['chunks_upserted' => 4],
            'status' => AiProfilePerChannel::STATUS_READY,
            'generated_at' => now(),
        ]);

        $text = app(BusinessAgentPrompt::class)->system(
            $business,
            $business->agent,
            $business->agentSettings,
            null,
            [],
            true,
            $account,
            $profile,
        );

        $this->assertStringContainsString('Supabase vectors', $text);
        $this->assertStringContainsString('Friendly sneaker shop', $text);
        $this->assertStringContainsString('knowledge_search', $text);
        $this->assertStringNotContainsString('never_invent_prices', $text);
    }

    public function test_corpus_is_sent_to_identity_agent_without_identity_json(): void
    {
        ['business' => $business] = $this->makeShop();
        $account = SocialAccount::query()->create([
            'business_id' => $business->id,
            'provider' => 'socialapi',
            'socialapi_account_id' => 'acc_ig_corpus',
            'platform' => 'instagram',
            'name' => 'Shoe Page',
            'username' => 'shoe.page',
            'status' => 'connected',
            'connected_at' => now(),
        ]);

        BusinessTrainingSnapshot::query()->create([
            'business_id' => $business->id,
            'social_account_id' => $account->id,
            'source' => 'post',
            'external_id' => 'post_1',
            'platform' => 'instagram',
            'title' => 'White sneakers',
            'content' => 'White sneakers 4500 DA available today',
            'payload' => ['post' => ['caption' => 'White sneakers 4500 DA available today']],
        ]);
        BusinessTrainingSnapshot::query()->create([
            'business_id' => $business->id,
            'social_account_id' => $account->id,
            'source' => 'dm',
            'external_id' => 'conv_1',
            'platform' => 'instagram',
            'title' => 'Buyer',
            'content' => 'Do you ship to Oran this week',
            'payload' => ['conversation' => ['participant_name' => 'Buyer']],
        ]);

        $business->update([
            'onboarding_status' => Business::ONBOARDING_PHASEONE,
            'onboarding_meta' => ['account_ids' => [$account->id]],
        ]);

        Http::fake([
            'runtime.test/v1/identity/build' => Http::response([
                'ok' => true,
                'chunks_upserted' => 3,
                'namespaces' => ['brand'],
                'summary' => 'Shoe Page',
                'storage' => 'supabase',
            ], 200),
        ]);

        (new BuildChannelAiProfileJob($business->id))->handle(app(\App\Services\Onboarding\ChannelAiProfileService::class));

        Http::assertSent(function ($request) {
            $corpus = $request->data()['corpus'] ?? [];

            return str_contains($request->url(), '/v1/identity/build')
                && str_contains(json_encode($corpus), 'White sneakers 4500 DA')
                && str_contains(json_encode($corpus), 'Do you ship to Oran this week');
        });

        $row = AiProfilePerChannel::query()->where('social_account_id', $account->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('supabase', $row->profile['storage']);
        $this->assertSame(3, $row->profile['chunks_upserted']);
        $this->assertArrayNotHasKey('version', $row->profile);
        $this->assertArrayNotHasKey('evidence', $row->profile);
    }

    public function test_phaseone_backfill_to_done_in_migration_style(): void
    {
        ['business' => $business] = $this->makeShop();
        $business->update(['onboarding_status' => Business::ONBOARDING_PHASEONE]);

        Business::query()
            ->where('onboarding_status', Business::ONBOARDING_PHASEONE)
            ->update(['onboarding_status' => Business::ONBOARDING_DONE]);

        $this->assertSame(Business::ONBOARDING_DONE, $business->fresh()->onboarding_status);
    }
}
