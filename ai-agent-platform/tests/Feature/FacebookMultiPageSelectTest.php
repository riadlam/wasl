<?php

namespace Tests\Feature;

use App\Models\AiProfilePerChannel;
use App\Models\SocialAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FacebookMultiPageSelectTest extends TestCase
{
    use RefreshDatabase;

    public function test_selecting_one_facebook_page_creates_one_local_account(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $business->update([
            'socialapi_brand_id' => 'brand_shop',
            'onboarding_status' => \App\Models\Business::ONBOARDING_DONE,
        ]);

        config(['services.socialapi.key' => 'sapi_key_test']);
        Bus::fake();

        Http::fake([
            'https://api.social-api.ai/v1/brands' => Http::response([
                'data' => [['id' => 'brand_shop', 'name' => 'Shop']],
                'count' => 1,
            ], 200),
            'https://api.social-api.ai/v1/accounts/pending/*/select' => Http::response([
                'account_id' => 'acc_page_1',
                'platform' => 'facebook',
                'display_name' => 'Page One',
                'login_id' => 'cred_login_1',
            ], 201),
            'https://api.social-api.ai/v1/platforms/facebook/logins/*/pages' => Http::response([
                'login_id' => 'cred_login_1',
                'pages' => [
                    [
                        'platform_page_id' => '111',
                        'name' => 'Page One',
                        'assigned_brand_id' => 'brand_shop',
                        'assigned_account_id' => 'acc_page_1',
                        'assignable' => false,
                    ],
                    [
                        'platform_page_id' => '222',
                        'name' => 'Page Two',
                        'assigned_brand_id' => 'brand_shop',
                        'assigned_account_id' => 'acc_page_2',
                        'assignable' => false,
                    ],
                ],
            ]),
            'https://api.social-api.ai/v1/accounts/*/pages' => Http::response(['data' => []], 200),
            'https://api.social-api.ai/v1/accounts' => Http::response([
                'data' => [
                    [
                        'id' => 'acc_page_1',
                        'brand_id' => 'brand_shop',
                        'platform' => 'facebook',
                        'display_name' => 'Page One',
                        'username' => 'page1',
                    ],
                ],
            ], 200),
        ]);
        Http::preventStrayRequests();

        $this->asShopUser($owner, $business)
            ->postJson('/api/social-accounts/pending/select', [
                'connection_id' => 'pc_test',
                'login_id' => 'cred_login_1',
                'page_ids' => ['111'],
            ])
            ->assertOk()
            ->assertJsonPath('count', 1);

        $fb = SocialAccount::query()
            ->where('business_id', $business->id)
            ->where('provider', 'socialapi')
            ->where('platform', 'facebook')
            ->where('status', 'connected')
            ->get();

        $this->assertCount(1, $fb);
        $this->assertSame('acc_page_1', $fb->first()->socialapi_account_id);
        $this->assertSame('Page One', $fb->first()->name);
    }

    public function test_selecting_multiple_facebook_pages_is_rejected(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $business->update(['socialapi_brand_id' => 'brand_shop']);
        config(['services.socialapi.key' => 'sapi_key_test']);

        $this->asShopUser($owner, $business)
            ->postJson('/api/social-accounts/pending/select', [
                'connection_id' => 'pc_test',
                'page_ids' => ['111', '222', '333'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['page_ids']);

        $this->assertSame(0, SocialAccount::query()
            ->where('business_id', $business->id)
            ->where('platform', 'facebook')
            ->count());
    }

    public function test_cannot_connect_second_facebook_page_while_one_is_linked(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $business->update(['socialapi_brand_id' => 'brand_shop']);
        config(['services.socialapi.key' => 'sapi_key_test']);

        SocialAccount::query()->create([
            'business_id' => $business->id,
            'provider' => 'socialapi',
            'socialapi_account_id' => 'acc_existing',
            'socialapi_brand_id' => 'brand_shop',
            'platform' => 'facebook',
            'name' => 'Existing Page',
            'status' => 'connected',
            'connected_at' => now(),
        ]);

        $this->asShopUser($owner, $business)
            ->postJson('/api/social-accounts/connect', ['platform' => 'facebook'])
            ->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'This shop already has a Facebook Page connected. Disconnect it before linking another.',
            ]);

        $this->asShopUser($owner, $business)
            ->postJson('/api/social-accounts/pending/select', [
                'connection_id' => 'pc_test',
                'page_ids' => ['999'],
            ])
            ->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'This shop already has a Facebook Page connected. Disconnect it before linking another.',
            ]);
    }

    public function test_channel_ai_profile_is_one_json_object_per_page_account(): void
    {
        ['business' => $business] = $this->makeShop();
        $business->update(['socialapi_brand_id' => 'brand_shop']);

        $account = SocialAccount::query()->create([
            'business_id' => $business->id,
            'provider' => 'socialapi',
            'socialapi_account_id' => 'acc_a',
            'socialapi_brand_id' => 'brand_shop',
            'platform' => 'facebook',
            'name' => 'Alpha',
            'status' => 'connected',
            'connected_at' => now(),
        ]);

        AiProfilePerChannel::query()->create([
            'business_id' => $business->id,
            'social_account_id' => $account->id,
            'platform' => 'facebook',
            'profile' => [
                'storage' => 'supabase',
                'summary' => 'Alpha page',
                'namespaces' => ['brand'],
                'chunks_upserted' => 2,
            ],
            'status' => AiProfilePerChannel::STATUS_READY,
            'generated_at' => now(),
        ]);

        $profiles = AiProfilePerChannel::query()
            ->where('business_id', $business->id)
            ->ready()
            ->get();

        $this->assertCount(1, $profiles);
        $this->assertSame('supabase', $profiles->first()->profile['storage'] ?? null);
        $this->assertSame('Alpha', $account->fresh()->name);
    }
}
