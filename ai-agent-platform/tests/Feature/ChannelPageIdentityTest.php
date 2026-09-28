<?php

namespace Tests\Feature;

use App\Services\SocialApi\SocialApiAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChannelPageIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_upsert_stores_facebook_page_not_personal_user(): void
    {
        ['business' => $business] = $this->makeShop();
        $business->update(['socialapi_brand_id' => 'brand_shop']);

        Http::fake([
            'https://api.social-api.ai/v1/accounts/*/pages' => Http::response([
                'data' => [
                    [
                        'name' => 'Luxury Transport Page',
                        'username' => 'luxury.page',
                        'platform_page_id' => 'page_999',
                        'is_default' => true,
                        'profile_picture_url' => 'https://cdn.example/page-avatar.jpg',
                    ],
                ],
            ], 200),
        ]);

        $account = app(SocialApiAccountService::class)->upsertFromRemote($business, [
            'id' => 'acc_fb_1',
            'brand_id' => 'brand_shop',
            'platform' => 'facebook',
            'platform_user_id' => 'user_personal_1',
            'name' => 'Personal Login Name',
            'username' => 'personal.user',
            'profile_picture_url' => 'https://cdn.example/user-avatar.jpg',
            'page' => [
                'name' => 'Luxury Transport Page',
                'username' => 'luxury.page',
                'platform_page_id' => 'page_999',
                'profile_picture_url' => 'https://cdn.example/page-avatar.jpg',
            ],
        ]);

        $this->assertSame('Luxury Transport Page', $account->name);
        $this->assertSame('luxury.page', $account->username);
        $this->assertSame('page_999', $account->platform_account_id);
        $this->assertSame('https://cdn.example/page-avatar.jpg', $account->avatar_url);
        $this->assertNotSame('user_personal_1', $account->platform_account_id);
    }

    public function test_resolve_channel_identity_prefers_nested_page(): void
    {
        $identity = app(SocialApiAccountService::class)->resolveChannelIdentity([
            'platform' => 'facebook',
            'platform_user_id' => 'user_1',
            'name' => 'User',
            'username' => 'user',
            'profile_picture_url' => 'https://cdn.example/user.jpg',
            'pages' => [
                [
                    'name' => 'Shop Page',
                    'username' => 'shoppage',
                    'platform_page_id' => 'pg_1',
                    'is_default' => true,
                    'profile_picture_url' => 'https://cdn.example/page.jpg',
                ],
            ],
        ], 'facebook');

        $this->assertSame('Shop Page', $identity['name']);
        $this->assertSame('shoppage', $identity['username']);
        $this->assertSame('pg_1', $identity['platform_account_id']);
        $this->assertSame('https://cdn.example/page.jpg', $identity['avatar_url']);
    }
}
