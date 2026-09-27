<?php

namespace Tests\Feature;

use App\Jobs\Onboarding\TrainBusinessOnboardingJob;
use App\Models\Business;
use App\Models\BusinessTrainingSnapshot;
use App\Models\SocialAccount;
use App\Services\OnboardingService;
use App\Services\ShopProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OnboardingTrainingTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_shop_starts_in_onboarding(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();

        $this->assertSame(Business::ONBOARDING, $business->fresh()->onboarding_status);

        $this->asShopUser($owner, $business)
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('business.onboarding_status', Business::ONBOARDING);
    }

    public function test_existing_shop_with_channel_migrates_to_phaseone(): void
    {
        ['business' => $business] = $this->makeShop();
        SocialAccount::query()->create([
            'business_id' => $business->id,
            'provider' => 'socialapi',
            'socialapi_account_id' => 'acc_fb_existing',
            'platform' => 'facebook',
            'name' => 'Existing Page',
            'status' => 'connected',
            'connected_at' => now(),
        ]);

        // Re-run the migration backfill logic (simulate migrating existing tenants).
        $idsWithChannel = SocialAccount::query()
            ->where('platform', '!=', 'simulator')
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', '!=', 'disconnected');
            })
            ->distinct()
            ->pluck('business_id');

        Business::query()->whereIn('id', $idsWithChannel)->update([
            'onboarding_status' => Business::ONBOARDING_PHASEONE,
        ]);

        $this->assertSame(Business::ONBOARDING_PHASEONE, $business->fresh()->onboarding_status);
    }

    public function test_first_channel_moves_to_waiting_and_dispatches_job(): void
    {
        Queue::fake();

        ['business' => $business] = $this->makeShop();
        $this->assertSame(Business::ONBOARDING, $business->onboarding_status);

        $account = SocialAccount::query()->create([
            'business_id' => $business->id,
            'provider' => 'socialapi',
            'socialapi_account_id' => 'acc_ig_train',
            'platform' => 'instagram',
            'name' => 'Train IG',
            'status' => 'connected',
            'connected_at' => now(),
        ]);

        app(OnboardingService::class)->onChannelConnected($business, $account);

        $business->refresh();
        $this->assertSame(Business::ONBOARDING_WAITING_AI_TRAINING, $business->onboarding_status);
        Queue::assertPushed(TrainBusinessOnboardingJob::class, fn ($job) => $job->businessId === $business->id);
    }

    public function test_second_channel_does_not_reset_phaseone(): void
    {
        Queue::fake();

        ['business' => $business] = $this->makeShop();
        $business->update(['onboarding_status' => Business::ONBOARDING_PHASEONE]);

        $account = SocialAccount::query()->create([
            'business_id' => $business->id,
            'provider' => 'socialapi',
            'socialapi_account_id' => 'acc_ig_2',
            'platform' => 'instagram',
            'name' => 'Second',
            'status' => 'connected',
            'connected_at' => now(),
        ]);

        app(OnboardingService::class)->onChannelConnected($business, $account);

        $this->assertSame(Business::ONBOARDING_PHASEONE, $business->fresh()->onboarding_status);
        Queue::assertNotPushed(TrainBusinessOnboardingJob::class);
    }

    public function test_training_job_persists_corpus_and_reaches_phaseone(): void
    {
        Queue::fake([\App\Jobs\Onboarding\BuildChannelAiProfileJob::class]);

        ['business' => $business] = $this->makeShop();
        $account = SocialAccount::query()->create([
            'business_id' => $business->id,
            'provider' => 'socialapi',
            'socialapi_account_id' => 'acc_ig_train',
            'platform' => 'instagram',
            'name' => 'Train IG',
            'username' => 'train.ig',
            'avatar_url' => 'https://example.com/a.jpg',
            'status' => 'connected',
            'connected_at' => now(),
        ]);

        $business->update([
            'onboarding_status' => Business::ONBOARDING_WAITING_AI_TRAINING,
            'onboarding_meta' => ['started_at' => now()->toIso8601String()],
        ]);

        Http::fake([
            'https://api.social-api.ai/v1/posts*' => Http::response([
                'data' => [[
                    'id' => 'post_1',
                    'platform_post_id' => '1789001',
                    'account_id' => 'acc_ig_train',
                    'platform' => 'instagram',
                    'caption' => 'New drop this week',
                    'like_count' => 12,
                    'comments_count' => 3,
                ]],
            ]),
            'https://api.social-api.ai/v1/posts/*/metrics' => Http::response([
                'likes' => 12,
                'comments' => 3,
                'shares' => 1,
                'saves' => 2,
            ]),
            'https://api.social-api.ai/v1/inbox/comments/*' => Http::response([
                'data' => [[
                    'id' => 'cmt_1',
                    'platform_id' => 'cmt_plat_1',
                    'text' => 'Price?',
                    'author_name' => 'Sara',
                    'platform' => 'instagram',
                ]],
            ]),
            // More specific message URLs must be registered before conversations*.
            'https://api.social-api.ai/v1/inbox/conversations/*/messages*' => Http::response([
                'data' => [[
                    'id' => 'msg_1',
                    'direction' => 'inbound',
                    'text' => 'Salam',
                    'created_at' => now()->toIso8601String(),
                ]],
                'pagination' => ['has_more' => false],
            ]),
            'https://api.social-api.ai/v1/inbox/conversations*' => Http::response([
                'data' => [[
                    'id' => 'conv_train_1',
                    'account_id' => 'acc_ig_train',
                    'platform' => 'instagram',
                    'participant_id' => 'u_1',
                    'participant_name' => 'Buyer',
                    'last_message' => 'Salam',
                    'last_message_at' => now()->toIso8601String(),
                ]],
                'pagination' => ['has_more' => false],
            ]),
        ]);

        (new TrainBusinessOnboardingJob($business->id))->handle(app(\App\Services\Onboarding\OnboardingTrainingService::class));

        $business->refresh();
        $this->assertSame(Business::ONBOARDING_PHASEONE, $business->onboarding_status);
        Queue::assertPushed(\App\Jobs\Onboarding\BuildChannelAiProfileJob::class, fn ($job) => $job->businessId === $business->id);
        $this->assertGreaterThanOrEqual(1, BusinessTrainingSnapshot::query()->where('business_id', $business->id)->where('source', 'page')->count());
        $this->assertGreaterThanOrEqual(1, BusinessTrainingSnapshot::query()->where('business_id', $business->id)->where('source', 'post')->count());
        $this->assertGreaterThanOrEqual(1, BusinessTrainingSnapshot::query()->where('business_id', $business->id)->where('source', 'comment')->count());
        $this->assertGreaterThanOrEqual(1, BusinessTrainingSnapshot::query()->where('business_id', $business->id)->where('source', 'dm')->count());
        $this->assertDatabaseHas('conversations', [
            'business_id' => $business->id,
            'socialapi_conversation_id' => 'conv_train_1',
        ]);
        $this->assertDatabaseHas('business_training_snapshots', [
            'business_id' => $business->id,
            'source' => 'comment',
            'external_id' => '1789001:cmt_1',
        ]);
    }

    public function test_retraining_same_corpus_does_not_duplicate_snapshots_or_messages(): void
    {
        Queue::fake([\App\Jobs\Onboarding\BuildChannelAiProfileJob::class]);

        ['business' => $business] = $this->makeShop();
        SocialAccount::query()->create([
            'business_id' => $business->id,
            'provider' => 'socialapi',
            'socialapi_account_id' => 'acc_ig_train',
            'platform' => 'instagram',
            'name' => 'Train IG',
            'username' => 'train.ig',
            'status' => 'connected',
            'connected_at' => now(),
        ]);

        $this->fakeTrainingHttp();

        $training = app(\App\Services\Onboarding\OnboardingTrainingService::class);

        $business->update([
            'onboarding_status' => Business::ONBOARDING_WAITING_AI_TRAINING,
            'onboarding_meta' => ['started_at' => now()->toIso8601String()],
        ]);
        $training->train($business->fresh());

        $countsAfterFirst = [
            'page' => BusinessTrainingSnapshot::query()->where('business_id', $business->id)->where('source', 'page')->count(),
            'post' => BusinessTrainingSnapshot::query()->where('business_id', $business->id)->where('source', 'post')->count(),
            'comment' => BusinessTrainingSnapshot::query()->where('business_id', $business->id)->where('source', 'comment')->count(),
            'dm' => BusinessTrainingSnapshot::query()->where('business_id', $business->id)->where('source', 'dm')->count(),
            'conversations' => \App\Models\Conversation::query()->where('business_id', $business->id)->count(),
            'messages' => \App\Models\Message::query()->where('business_id', $business->id)->count(),
        ];

        $this->assertSame(1, $countsAfterFirst['page']);
        $this->assertSame(1, $countsAfterFirst['post']);
        $this->assertSame(1, $countsAfterFirst['comment']);
        $this->assertSame(1, $countsAfterFirst['dm']);
        $this->assertSame(1, $countsAfterFirst['conversations']);
        $this->assertSame(1, $countsAfterFirst['messages']);

        // Simulate reconnect / re-dispatch while still waiting: train again with same remote ids.
        $business->update(['onboarding_status' => Business::ONBOARDING_WAITING_AI_TRAINING]);
        $training->train($business->fresh());

        $this->assertSame($countsAfterFirst['page'], BusinessTrainingSnapshot::query()->where('business_id', $business->id)->where('source', 'page')->count());
        $this->assertSame($countsAfterFirst['post'], BusinessTrainingSnapshot::query()->where('business_id', $business->id)->where('source', 'post')->count());
        $this->assertSame($countsAfterFirst['comment'], BusinessTrainingSnapshot::query()->where('business_id', $business->id)->where('source', 'comment')->count());
        $this->assertSame($countsAfterFirst['dm'], BusinessTrainingSnapshot::query()->where('business_id', $business->id)->where('source', 'dm')->count());
        $this->assertSame(1, \App\Models\Conversation::query()->where('business_id', $business->id)->count());
        $this->assertSame(1, \App\Models\Message::query()->where('business_id', $business->id)->count());
        $this->assertSame(1, \App\Models\Message::query()->where('socialapi_message_id', 'msg_1')->count());

        $heavyBefore = $this->heavyTrainingRequestCount();
        $training->train($business->fresh());
        $this->assertSame($heavyBefore, $this->heavyTrainingRequestCount());
    }

    public function test_last_channel_disconnect_resets_onboarding_to_connect(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $business->update([
            'onboarding_status' => Business::ONBOARDING_DONE,
            'onboarding_meta' => ['error' => 'stale', 'posts' => 3],
        ]);
        $account = SocialAccount::query()->create([
            'business_id' => $business->id,
            'provider' => 'socialapi',
            'socialapi_account_id' => 'acc_only',
            'platform' => 'instagram',
            'name' => 'Only',
            'status' => 'connected',
            'connected_at' => now(),
        ]);

        $this->fakeDisconnectHttp();
        app(\App\Services\SocialApi\SocialApiAccountService::class)->disconnect($account);

        $business->refresh();
        $this->assertSame('disconnected', $account->fresh()->status);
        $this->assertSame(Business::ONBOARDING, $business->onboarding_status);
        $this->assertNull($business->onboarding_meta['error'] ?? null);

        $this->asShopUser($owner, $business)
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('business.onboarding_status', Business::ONBOARDING);
    }

    public function test_disconnect_keeps_status_when_another_channel_stays_live(): void
    {
        ['business' => $business] = $this->makeShop();
        $business->update(['onboarding_status' => Business::ONBOARDING_DONE]);
        $drop = SocialAccount::query()->create([
            'business_id' => $business->id,
            'provider' => 'socialapi',
            'socialapi_account_id' => 'acc_drop',
            'platform' => 'instagram',
            'name' => 'Drop',
            'status' => 'connected',
            'connected_at' => now(),
        ]);
        SocialAccount::query()->create([
            'business_id' => $business->id,
            'provider' => 'socialapi',
            'socialapi_account_id' => 'acc_keep',
            'platform' => 'facebook',
            'name' => 'Keep',
            'status' => 'connected',
            'connected_at' => now(),
        ]);

        $this->fakeDisconnectHttp();
        app(\App\Services\SocialApi\SocialApiAccountService::class)->disconnect($drop);

        $this->assertSame(Business::ONBOARDING_DONE, $business->fresh()->onboarding_status);
    }

    public function test_mid_training_last_channel_disconnect_returns_to_onboarding(): void
    {
        ['business' => $business] = $this->makeShop();
        $business->update([
            'onboarding_status' => Business::ONBOARDING_WAITING_AI_TRAINING,
            'onboarding_meta' => ['error' => 'still running'],
        ]);
        $account = SocialAccount::query()->create([
            'business_id' => $business->id,
            'provider' => 'socialapi',
            'socialapi_account_id' => 'acc_mid',
            'platform' => 'facebook',
            'name' => 'Mid',
            'status' => 'connected',
            'connected_at' => now(),
        ]);

        $this->fakeDisconnectHttp();
        app(\App\Services\SocialApi\SocialApiAccountService::class)->markDisconnected([
            'account_id' => $account->socialapi_account_id,
        ]);

        $this->assertSame(Business::ONBOARDING, $business->fresh()->onboarding_status);
    }

    public function test_importing_same_remote_messages_twice_does_not_duplicate(): void
    {
        ['business' => $business] = $this->makeShop();
        $account = SocialAccount::query()->create([
            'business_id' => $business->id,
            'provider' => 'socialapi',
            'socialapi_account_id' => 'acc_ig_dedupe',
            'platform' => 'instagram',
            'name' => 'Dedupe IG',
            'status' => 'connected',
            'connected_at' => now(),
        ]);

        Http::fake([
            'https://api.social-api.ai/v1/inbox/conversations/*/messages*' => Http::response([
                'data' => [
                    [
                        'id' => 'msg_dup_1',
                        'direction' => 'inbound',
                        'text' => 'Hello',
                        'created_at' => now()->toIso8601String(),
                    ],
                    [
                        'id' => 'msg_dup_1',
                        'direction' => 'inbound',
                        'text' => 'Hello',
                        'created_at' => now()->toIso8601String(),
                    ],
                ],
                'pagination' => ['has_more' => false],
            ]),
        ]);

        $row = [
            'id' => 'conv_dedupe_1',
            'account_id' => 'acc_ig_dedupe',
            'platform' => 'instagram',
            'participant_id' => 'u_dup',
            'participant_name' => 'Buyer',
            'last_message' => 'Hello',
            'last_message_at' => now()->toIso8601String(),
        ];

        $conversations = app(\App\Services\ConversationService::class);
        $conversations->upsertRemoteConversationRow($business, $account, $row, false);
        $conversation = \App\Models\Conversation::query()
            ->where('business_id', $business->id)
            ->where('socialapi_conversation_id', 'conv_dedupe_1')
            ->firstOrFail();

        $conversations->syncHistory($conversation->fresh(['socialAccount']), null, 100, true);
        $conversations->syncHistory($conversation->fresh(['socialAccount']), null, 100, true);
        $conversations->upsertRemoteConversationRow($business, $account, $row, false);

        $this->assertSame(1, \App\Models\Conversation::query()->where('business_id', $business->id)->count());
        $this->assertSame(1, \App\Models\Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('socialapi_message_id', 'msg_dup_1')
            ->count());
    }

    /**
     * @return void
     */
    private function heavyTrainingRequestCount(): int
    {
        return collect(Http::recorded())->filter(function (array $pair) {
            $url = (string) $pair[0]->url();

            return str_contains($url, '/inbox/comments/')
                || str_contains($url, '/messages')
                || str_contains($url, '/metrics');
        })->count();
    }

    private function fakeDisconnectHttp(): void
    {
        config([
            'services.socialapi.key' => 'test-key',
            'services.socialapi.base_url' => 'https://api.social-api.ai/v1',
        ]);
        Http::fake([
            'https://api.social-api.ai/*' => Http::response([], 200),
        ]);
    }

    private function fakeTrainingHttp(): void
    {
        Http::fake([
            'https://api.social-api.ai/v1/posts*' => Http::response([
                'data' => [[
                    'id' => 'post_1',
                    'platform_post_id' => '1789001',
                    'account_id' => 'acc_ig_train',
                    'platform' => 'instagram',
                    'caption' => 'New drop this week',
                    'like_count' => 12,
                    'comments_count' => 3,
                ]],
            ]),
            'https://api.social-api.ai/v1/posts/*/metrics' => Http::response([
                'likes' => 12,
                'comments' => 3,
                'shares' => 1,
                'saves' => 2,
            ]),
            'https://api.social-api.ai/v1/inbox/comments/*' => Http::response([
                'data' => [[
                    'id' => 'cmt_1',
                    'platform_id' => 'cmt_plat_1',
                    'text' => 'Price?',
                    'author_name' => 'Sara',
                    'platform' => 'instagram',
                ]],
            ]),
            'https://api.social-api.ai/v1/inbox/conversations/*/messages*' => Http::response([
                'data' => [[
                    'id' => 'msg_1',
                    'direction' => 'inbound',
                    'text' => 'Salam',
                    'created_at' => now()->toIso8601String(),
                ]],
                'pagination' => ['has_more' => false],
            ]),
            'https://api.social-api.ai/v1/inbox/conversations*' => Http::response([
                'data' => [[
                    'id' => 'conv_train_1',
                    'account_id' => 'acc_ig_train',
                    'platform' => 'instagram',
                    'participant_id' => 'u_1',
                    'participant_name' => 'Buyer',
                    'last_message' => 'Salam',
                    'last_message_at' => now()->toIso8601String(),
                ]],
                'pagination' => ['has_more' => false],
            ]),
        ]);
    }

    public function test_provisioner_sets_onboarding_status(): void
    {
        $owner = \App\Models\User::factory()->create(['email' => 'prov@example.test']);
        $business = app(ShopProvisioner::class)->createShop($owner, 'Fresh Shop');

        $this->assertSame(Business::ONBOARDING, $business->onboarding_status);
    }
}
