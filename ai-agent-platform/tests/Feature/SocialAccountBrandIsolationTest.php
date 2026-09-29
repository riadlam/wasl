<?php

namespace Tests\Feature;

use App\Models\SocialAccount;
use App\Services\SocialApi\SocialApiAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class SocialAccountBrandIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_route_is_removed(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();

        $response = $this->asShopUser($owner, $business)
            ->postJson('/api/social-accounts/sync');

        // URI may match DELETE /social-accounts/{id} → 405, or no route → 404.
        $this->assertContains($response->status(), [404, 405]);
    }

    public function test_connect_creates_brand_and_passes_brand_id(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $this->assertNull($business->socialapi_brand_id);

        config(['services.socialapi.key' => 'sapi_key_test']);

        Http::fake([
            'https://api.social-api.ai/v1/brands' => Http::response([
                'id' => 'brand_shop_1',
                'name' => 'Maison Test',
            ], 201),
            'https://api.social-api.ai/v1/accounts/connect' => Http::response([
                'auth_url' => 'https://meta.example/oauth',
                'state' => 'opaque',
            ], 202),
        ]);

        $this->asShopUser($owner, $business)
            ->postJson('/api/social-accounts/connect', ['platform' => 'instagram'])
            ->assertOk()
            ->assertJsonPath('auth_url', 'https://meta.example/oauth');

        $this->assertSame('brand_shop_1', $business->fresh()->socialapi_brand_id);

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && str_ends_with($request->url(), '/brands')
                && ($request['name'] ?? null) === 'Maison Test';
        });

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && str_ends_with($request->url(), '/accounts/connect')
                && ($request['brand_id'] ?? null) === 'brand_shop_1'
                && ($request['platform'] ?? null) === 'instagram';
        });
    }

    public function test_connect_reuses_existing_shop_brand(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $business->update(['socialapi_brand_id' => 'brand_existing']);

        config(['services.socialapi.key' => 'sapi_key_test']);

        Http::fake([
            'https://api.social-api.ai/v1/brands' => Http::response([
                'data' => [['id' => 'brand_existing', 'name' => 'Maison Test']],
                'count' => 1,
            ], 200),
            'https://api.social-api.ai/v1/accounts/connect' => Http::response([
                'auth_url' => 'https://meta.example/oauth',
            ], 202),
        ]);

        $this->asShopUser($owner, $business)
            ->postJson('/api/social-accounts/connect', ['platform' => 'facebook'])
            ->assertOk();

        Http::assertNotSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/brands'));
        Http::assertSent(fn ($request) => ($request['brand_id'] ?? null) === 'brand_existing');
    }

    public function test_connect_recreates_brand_when_remote_brand_missing(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $business->update(['socialapi_brand_id' => 'a412f159-d649-4b1f-98f1-19797f92590f']);

        SocialAccount::query()->create([
            'business_id' => $business->id,
            'provider' => 'socialapi',
            'socialapi_account_id' => 'acc_old',
            'socialapi_brand_id' => 'a412f159-d649-4b1f-98f1-19797f92590f',
            'platform' => 'facebook',
            'name' => 'Old Page',
            'status' => 'disconnected',
            'disconnected_at' => now(),
        ]);

        config(['services.socialapi.key' => 'sapi_key_test']);

        Http::fake([
            'https://api.social-api.ai/v1/brands' => Http::sequence()
                ->push(['data' => [], 'count' => 0], 200)
                ->push(['id' => 'brand_fresh', 'name' => 'Maison Test'], 201),
            'https://api.social-api.ai/v1/accounts/connect' => Http::response([
                'auth_url' => 'https://meta.example/oauth',
            ], 202),
        ]);

        $this->asShopUser($owner, $business)
            ->postJson('/api/social-accounts/connect', ['platform' => 'instagram'])
            ->assertOk()
            ->assertJsonPath('auth_url', 'https://meta.example/oauth');

        $this->assertSame('brand_fresh', $business->fresh()->socialapi_brand_id);
        $this->assertSame(
            'brand_fresh',
            SocialAccount::query()->where('socialapi_account_id', 'acc_old')->value('socialapi_brand_id'),
        );

        Http::assertSent(fn ($request) => ($request['brand_id'] ?? null) === 'brand_fresh'
            && str_ends_with($request->url(), '/accounts/connect'));
    }

    public function test_connect_retries_when_connect_reports_brand_not_found(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $business->update(['socialapi_brand_id' => 'brand_stale']);

        config(['services.socialapi.key' => 'sapi_key_test']);

        Http::fake([
            'https://api.social-api.ai/v1/brands' => Http::sequence()
                ->push(['data' => [['id' => 'brand_stale', 'name' => 'Ghost']], 'count' => 1], 200)
                ->push(['id' => 'brand_new', 'name' => 'Maison Test'], 201),
            'https://api.social-api.ai/v1/accounts/connect' => Http::sequence()
                ->push([
                    'error' => [
                        'code' => 'resource.not_found',
                        'message' => 'Brand not found brand_stale brand',
                    ],
                ], 404)
                ->push(['auth_url' => 'https://meta.example/oauth'], 202),
        ]);

        $this->asShopUser($owner, $business)
            ->postJson('/api/social-accounts/connect', ['platform' => 'facebook'])
            ->assertOk()
            ->assertJsonPath('auth_url', 'https://meta.example/oauth');

        $this->assertSame('brand_new', $business->fresh()->socialapi_brand_id);
    }

    public function test_upsert_rejects_foreign_brand(): void
    {
        ['business' => $business] = $this->makeShop();
        $business->update(['socialapi_brand_id' => 'brand_mine']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('another brand');

        app(SocialApiAccountService::class)->upsertFromRemote($business, [
            'id' => 'acc_foreign',
            'brand_id' => 'brand_other',
            'platform' => 'instagram',
            'display_name' => 'Leak',
        ]);
    }

    public function test_upsert_prefers_facebook_page_name_over_owner_name(): void
    {
        ['business' => $business] = $this->makeShop();
        $business->update(['socialapi_brand_id' => 'brand_mine']);

        config(['services.socialapi.key' => 'sapi_key_test']);
        Http::fake([
            'https://api.social-api.ai/v1/accounts/acc_fb/pages' => Http::response([
                'data' => [
                    [
                        'name' => 'Dias Zone',
                        'is_default' => true,
                        'platform_page_id' => '899',
                    ],
                ],
            ]),
        ]);

        $account = app(SocialApiAccountService::class)->upsertFromRemote($business, [
            'id' => 'acc_fb',
            'brand_id' => 'brand_mine',
            'platform' => 'facebook',
            'name' => 'Mohamed Riad Laamari',
            'username' => 'Mohamed Riad Laamari',
            'display_name' => 'Mohamed Riad Laamari',
            'page_name' => 'Dias Zone',
        ]);

        $this->assertSame('Dias Zone', $account->name);
    }

    public function test_resolve_platform_from_facebook_page_link_when_missing(): void
    {
        ['business' => $business] = $this->makeShop();
        $business->update(['socialapi_brand_id' => 'brand_mine']);

        $account = app(SocialApiAccountService::class)->upsertFromRemote($business, [
            'id' => 'acc_infer',
            'brand_id' => 'brand_mine',
            'display_name' => 'Dias Zone',
            'page' => [
                'name' => 'Dias Zone',
                'link' => 'https://www.facebook.com/899626169897322',
            ],
        ]);

        $this->assertSame('facebook', $account->platform);
    }

    public function test_mark_connected_updates_unknown_platform_from_metadata(): void
    {
        ['business' => $business] = $this->makeShop();
        $business->update(['socialapi_brand_id' => 'brand_mine']);

        SocialAccount::query()->create([
            'business_id' => $business->id,
            'provider' => 'socialapi',
            'socialapi_account_id' => 'acc_unknown',
            'socialapi_brand_id' => 'brand_mine',
            'platform' => 'unknown',
            'name' => 'Temp',
            'status' => 'connected',
            'connected_at' => now(),
            'metadata' => [],
        ]);

        $saved = app(SocialApiAccountService::class)->markConnected([
            'account_id' => 'acc_unknown',
            'brand_id' => 'brand_mine',
            'platform' => 'facebook',
            'display_name' => 'Dias Zone',
        ]);

        $this->assertNotNull($saved);
        $this->assertSame('facebook', $saved->platform);
    }

    public function test_upsert_accepts_matching_brand(): void
    {
        ['business' => $business] = $this->makeShop();
        $business->update(['socialapi_brand_id' => 'brand_mine']);

        $account = app(SocialApiAccountService::class)->upsertFromRemote($business, [
            'id' => 'acc_ok',
            'brand_id' => 'brand_mine',
            'platform' => 'instagram',
            'display_name' => 'Shop IG',
        ]);

        $this->assertSame($business->id, $account->business_id);
        $this->assertSame('brand_mine', $account->socialapi_brand_id);
        $this->assertDatabaseHas('social_accounts', [
            'business_id' => $business->id,
            'socialapi_account_id' => 'acc_ok',
        ]);
    }

    public function test_mark_connected_ignores_unknown_brand(): void
    {
        ['business' => $business] = $this->makeShop();
        $business->update(['socialapi_brand_id' => 'brand_mine']);

        $saved = app(SocialApiAccountService::class)->markConnected([
            'account_id' => 'acc_stranger',
            'brand_id' => 'brand_unknown',
            'platform' => 'instagram',
            'display_name' => 'Stranger',
        ]);

        $this->assertNull($saved);
        $this->assertSame(0, SocialAccount::query()->where('socialapi_account_id', 'acc_stranger')->count());
    }

    public function test_channels_index_only_returns_current_shop_accounts(): void
    {
        ['owner' => $owner, 'business' => $a] = $this->makeShop();
        $b = app(\App\Services\ShopProvisioner::class)->createShop(
            \App\Models\User::factory()->create(['email' => 'other@example.test']),
            'Other Shop',
        );

        SocialAccount::query()->create([
            'business_id' => $a->id,
            'provider' => 'socialapi',
            'socialapi_account_id' => 'acc_a',
            'socialapi_brand_id' => 'brand_a',
            'platform' => 'instagram',
            'name' => 'Shop A',
            'status' => 'connected',
            'connected_at' => now(),
        ]);
        SocialAccount::query()->create([
            'business_id' => $b->id,
            'provider' => 'socialapi',
            'socialapi_account_id' => 'acc_b',
            'socialapi_brand_id' => 'brand_b',
            'platform' => 'instagram',
            'name' => 'Shop B',
            'status' => 'connected',
            'connected_at' => now(),
        ]);

        $payload = $this->asShopUser($owner, $a)
            ->getJson('/api/social-accounts')
            ->assertOk()
            ->json('accounts');

        $names = collect($payload)->pluck('name')->filter()->values()->all();
        $this->assertContains('Shop A', $names);
        $this->assertNotContains('Shop B', $names);

        $shopA = collect($payload)->firstWhere('name', 'Shop A');
        $this->assertNotNull($shopA);
        $this->assertArrayNotHasKey('socialapi_account_id', $shopA);
        $this->assertArrayNotHasKey('provider', $shopA);
        $this->assertTrue(($shopA['connected'] ?? false) === true);
    }

    public function test_whatsapp_connect_returns_embedded_signup_metadata(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $business->update(['socialapi_brand_id' => 'brand_wa']);

        config(['services.socialapi.key' => 'sapi_key_test']);

        Http::fake([
            'https://api.social-api.ai/v1/brands' => Http::response([
                'data' => [['id' => 'brand_wa', 'name' => 'Maison Test']],
                'count' => 1,
            ], 200),
            'https://api.social-api.ai/v1/accounts/connect' => Http::response([
                'auth_url' => '',
                'state' => 'wa_csrf_state',
                'message' => 'Open this link to connect your whatsapp account.',
                'metadata' => [
                    'app_id' => '830175536100467',
                    'config_id' => '948983521469059',
                    'solution_id' => '',
                    'secret' => 'should-not-leak',
                ],
            ], 202),
        ]);

        $this->asShopUser($owner, $business)
            ->postJson('/api/social-accounts/connect', ['platform' => 'whatsapp'])
            ->assertOk()
            ->assertJsonPath('auth_url', null)
            ->assertJsonPath('state', 'wa_csrf_state')
            ->assertJsonPath('mode', 'embedded_coexistence')
            ->assertJsonPath('metadata.app_id', '830175536100467')
            ->assertJsonPath('metadata.config_id', '948983521469059')
            ->assertJsonMissingPath('metadata.secret');
    }

    public function test_whatsapp_import_pulls_brand_account(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $business->update([
            'socialapi_brand_id' => 'brand_wa',
            'onboarding_status' => \App\Models\Business::ONBOARDING_DONE,
        ]);

        config(['services.socialapi.key' => 'sapi_key_test']);
        \Illuminate\Support\Facades\Bus::fake();

        Http::fake([
            'https://api.social-api.ai/v1/brands' => Http::response([
                'data' => [['id' => 'brand_wa', 'name' => 'Maison Test']],
                'count' => 1,
            ], 200),
            'https://api.social-api.ai/v1/accounts' => Http::response([
                'data' => [[
                    'id' => 'acc_wa_import',
                    'brand_id' => 'brand_wa',
                    'platform' => 'whatsapp',
                    'display_name' => 'Wasl WA',
                    'username' => '+213555000000',
                ]],
            ], 200),
        ]);
        Http::preventStrayRequests();

        $this->asShopUser($owner, $business)
            ->postJson('/api/social-accounts/import', ['platform' => 'whatsapp'])
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('accounts.0.platform', 'whatsapp');

        $this->assertDatabaseHas('social_accounts', [
            'business_id' => $business->id,
            'socialapi_account_id' => 'acc_wa_import',
            'platform' => 'whatsapp',
            'status' => 'connected',
        ]);
    }

    public function test_whatsapp_complete_exchanges_code_and_saves_account(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $business->update([
            'socialapi_brand_id' => 'brand_wa',
            'onboarding_status' => \App\Models\Business::ONBOARDING_DONE,
        ]);

        config(['services.socialapi.key' => 'sapi_key_test']);
        \Illuminate\Support\Facades\Bus::fake();

        Http::fake([
            'https://api.social-api.ai/v1/oauth/exchange' => Http::response([
                'account_id' => 'acc_wa_1',
                'platform' => 'whatsapp',
                'display_name' => 'Wasl WA',
                'username' => '+213555000000',
            ], 201),
        ]);
        Http::preventStrayRequests();

        $this->asShopUser($owner, $business)
            ->postJson('/api/social-accounts/whatsapp/complete', [
                'code' => 'AQD_test_code',
                'state' => 'wa_csrf_state',
                'waba_id' => 'waba_123',
                'phone_number_id' => 'phone_456',
                'coexistence' => true,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('account.platform', 'whatsapp')
            ->assertJsonPath('account.name', 'Wasl WA');

        $this->assertDatabaseHas('social_accounts', [
            'business_id' => $business->id,
            'socialapi_account_id' => 'acc_wa_1',
            'platform' => 'whatsapp',
            'status' => 'connected',
        ]);

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && str_ends_with($request->url(), '/oauth/exchange')
                && ($request['platform'] ?? null) === 'whatsapp'
                && ($request['code'] ?? null) === 'AQD_test_code'
                && ($request['metadata']['state'] ?? null) === 'wa_csrf_state'
                && ($request['metadata']['waba_id'] ?? null) === 'waba_123'
                && ($request['metadata']['coexistence'] ?? null) === true
                && ! array_key_exists('phone_number_id', $request['metadata'] ?? []);
        });
    }
}
