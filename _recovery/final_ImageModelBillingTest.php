<?php

namespace Tests\Feature;

use App\AI\ImageModels\ImageModelCatalog;
use App\AI\Tools\Owner\GenerateImage;
use App\Models\AgentAsset;
use App\Models\User;
use App\Models\WalletLedger;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImageModelBillingTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_whitelist_rejects_unknown_model_on_patch(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();

        $this->asShopUser($owner, $business)
            ->patchJson('/api/agent/image-model', ['image_model' => 'not_a_real_model'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['image_model']);
    }

    public function test_preference_patch_accepts_whitelisted_model(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();

        $this->asShopUser($owner, $business)
            ->patchJson('/api/agent/image-model', ['image_model' => 'flux'])
            ->assertOk()
            ->assertJsonPath('image_model', 'flux');

        $this->assertSame('flux', $business->fresh()->agentSettings->image_model);
    }

    public function test_image_models_endpoint_hides_endpoints(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();

        $payload = $this->asShopUser($owner, $business)
            ->getJson('/api/agent/image-models')
            ->assertOk()
            ->json();

        $this->assertSame('gpt_image_2', $payload['default']);
        $this->assertNotEmpty($payload['models']);
        foreach ($payload['models'] as $row) {
            $this->assertArrayHasKey('id', $row);
            $this->assertArrayHasKey('label', $row);
            $this->assertArrayHasKey('price_da', $row);
            $this->assertArrayHasKey('recommended', $row);
            $this->assertArrayNotHasKey('endpoint', $row);
        }
        $this->assertTrue($payload['models'][0]['recommended']);
    }

    public function test_generate_image_charges_price_da_per_model_and_gpt_sends_medium(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $owner->update(['wallet_balance_ds' => 200]);
        $business->agentSettings->update(['image_model' => 'gpt_image_2']);

        Storage::fake('public');
        config(['services.fal.key' => 'fal_test_key']);

        Http::fake([
            'https://queue.fal.run/fal-ai/gpt-image-2*' => Http::response([
                'request_id' => 'req_gpt_1',
                'status_url' => 'https://queue.fal.run/fal-ai/gpt-image-2/requests/req_gpt_1/status',
                'response_url' => 'https://queue.fal.run/fal-ai/gpt-image-2/requests/req_gpt_1',
            ]),
            'https://cdn.example.com/gpt.jpg' => Http::response('jpeg', 200),
        ]);

        $result = app(GenerateImage::class)->handle($business, [
            'prompt' => 'Product on marble desk',
            'image_size' => 'square_hd',
        ], ['user_id' => $owner->id]);

        $this->assertTrue($result['pending'] ?? false);
        $this->assertSame(100.0, (float) $owner->fresh()->wallet_balance_ds);

        $this->postJson('/api/webhooks/fal/image', [
            'request_id' => 'req_gpt_1',
            'status' => 'OK',
            'payload' => [
                'images' => [[
                    'url' => 'https://cdn.example.com/gpt.jpg',
                    'content_type' => 'image/jpeg',
                ]],
            ],
        ])->assertOk();

        $this->assertSame(86.0, (float) $owner->fresh()->wallet_balance_ds); // 100 - 14
        $this->assertDatabaseHas('wallet_ledger', [
            'user_id' => $owner->id,
            'reason' => 'agent_image_gen',
            'amount_da' => 14,
        ]);

        Http::assertSent(function ($request) {
            if (! str_starts_with($request->url(), 'https://queue.fal.run/fal-ai/gpt-image-2')) {
                return false;
            }
            if (str_contains($request->url(), '/requests/')) {
                return false;
            }
            $body = $request->data();

            return ($body['quality'] ?? null) === 'medium'
                && ($body['image_size'] ?? null) === 'square_hd'
                && ! array_key_exists('seed', $body);
        });
    }

    public function test_generate_image_flux_charges_one_da(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $owner->update(['wallet_balance_ds' => 50]);
        $business->agentSettings->update(['image_model' => 'flux']);

        Storage::fake('public');
        config(['services.fal.key' => 'fal_test_key']);

        Http::fake([
            'https://queue.fal.run/fal-ai/flux/schnell*' => Http::response([
                'request_id' => 'req_flux_1',
                'status_url' => 'https://queue.fal.run/fal-ai/flux/schnell/requests/req_flux_1/status',
                'response_url' => 'https://queue.fal.run/fal-ai/flux/schnell/requests/req_flux_1',
            ]),
            'https://cdn.example.com/flux.jpg' => Http::response('jpeg', 200),
        ]);

        $result = app(GenerateImage::class)->handle($business, [
            'prompt' => 'Clean storefront photo',
        ], ['user_id' => $owner->id]);

        $this->assertTrue($result['pending'] ?? false);
        $this->assertSame(50.0, (float) $owner->fresh()->wallet_balance_ds);

        $this->postJson('/api/webhooks/fal/image', [
            'request_id' => 'req_flux_1',
            'status' => 'OK',
            'payload' => [
                'images' => [[
                    'url' => 'https://cdn.example.com/flux.jpg',
                    'content_type' => 'image/jpeg',
                ]],
            ],
        ])->assertOk();

        $this->assertSame(49.0, (float) $owner->fresh()->wallet_balance_ds);
        $this->assertSame(1.0, (float) WalletLedger::query()->where('reason', 'agent_image_gen')->value('amount_da'));
    }

    public function test_generate_image_nano_banana_charges_twenty_da(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $owner->update(['wallet_balance_ds' => 50]);
        $business->agentSettings->update(['image_model' => 'nano_banana_2']);

        Storage::fake('public');
        config(['services.fal.key' => 'fal_test_key']);

        Http::fake([
            'https://queue.fal.run/fal-ai/nano-banana-2*' => Http::response([
                'request_id' => 'req_nano_1',
                'status_url' => 'https://queue.fal.run/fal-ai/nano-banana-2/requests/req_nano_1/status',
                'response_url' => 'https://queue.fal.run/fal-ai/nano-banana-2/requests/req_nano_1',
            ]),
            'https://cdn.example.com/nano.jpg' => Http::response('jpeg', 200),
        ]);

        $result = app(GenerateImage::class)->handle($business, [
            'prompt' => 'Lifestyle cafe shot',
            'image_size' => 'portrait_16_9',
        ], ['user_id' => $owner->id]);

        $this->assertTrue($result['pending'] ?? false);

        $this->postJson('/api/webhooks/fal/image', [
            'request_id' => 'req_nano_1',
            'status' => 'OK',
            'payload' => [
                'images' => [[
                    'url' => 'https://cdn.example.com/nano.jpg',
                    'content_type' => 'image/jpeg',
                ]],
            ],
        ])->assertOk();

        $this->assertSame(30.0, (float) $owner->fresh()->wallet_balance_ds);

        Http::assertSent(function ($request) {
            if (! str_starts_with($request->url(), 'https://queue.fal.run/fal-ai/nano-banana-2')) {
                return false;
            }
            if (str_contains($request->url(), '/requests/')) {
                return false;
            }
            $body = $request->data();

            return ($body['aspect_ratio'] ?? null) === '9:16';
        });
    }

    public function test_gpt_sync_fal_run_without_images_does_not_succeed(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $owner->update(['wallet_balance_ds' => 200]);
        $business->agentSettings->update(['image_model' => 'gpt_image_2']);

        Storage::fake('public');
        config(['services.fal.key' => 'fal_test_key']);

        Http::fake([
            'https://queue.fal.run/fal-ai/gpt-image-2*' => Http::response([
                'request_id' => 'req_empty',
                'status_url' => 'https://queue.fal.run/fal-ai/gpt-image-2/requests/req_empty/status',
                'response_url' => 'https://queue.fal.run/fal-ai/gpt-image-2/requests/req_empty',
            ]),
        ]);

        $result = app(GenerateImage::class)->handle($business, [
            'prompt' => 'Product shot',
        ], ['user_id' => $owner->id]);

        $this->assertTrue($result['pending'] ?? false);

        $this->postJson('/api/webhooks/fal/image', [
            'request_id' => 'req_empty',
            'status' => 'ERROR',
            'error' => 'no image',
        ])->assertOk();

        $this->assertSame(100.0, (float) $owner->fresh()->wallet_balance_ds);
        $this->assertDatabaseMissing('wallet_ledger', ['reason' => 'agent_image_gen']);
        Http::assertNotSent(fn ($request) => str_starts_with($request->url(), 'https://fal.run/fal-ai/gpt-image-2'));
    }

    public function test_catalog_rejects_unknown_key(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(ImageModelCatalog::class)->get('unknown_model');
    }

    public function test_wallet_snapshot_is_dzd_and_chat_charges_25_da(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $owner->update(['wallet_balance_ds' => 200, 'wallet_currency' => 'DZD']);

        $this->asShopUser($owner, $business)
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('wallet.currency', 'DZD')
            ->assertJsonPath('wallet.balance', 100)
            ->assertJsonPath('wallet.balance_ds', 100)
            ->assertJsonPath('wallet.usd_to_da', 250);

        Http::fake([
            'https://fal.run/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Ok.',
                    ],
                ]],
                'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
            ]),
        ]);

        $this->asShopUser($owner, $business)
            ->postJson('/api/agent/chat', ['text' => 'Salam'])
            ->assertOk();

        $this->assertSame(75.0, (float) $owner->fresh()->wallet_balance_ds);
        $this->assertDatabaseHas('wallet_ledger', [
            'user_id' => $owner->id,
            'reason' => 'agent_chat',
            'amount_da' => 25,
        ]);
    }

    public function test_wallet_da_migration_multiplies_usd_balances(): void
    {
        // RefreshDatabase already ran migrations. Simulate pre-migration USD balance
        // by inserting a user as if still on the old unit, then re-applying the convert logic.
        $user = User::factory()->create([
            'wallet_balance_ds' => 4,
            'wallet_currency' => 'USD',
        ]);

        \Illuminate\Support\Facades\DB::table('users')
            ->where('id', $user->id)
            ->where('wallet_currency', 'USD')
            ->update([
                'wallet_balance_ds' => \Illuminate\Support\Facades\DB::raw('ROUND(wallet_balance_ds * 25, 2)'),
                'wallet_currency' => 'DZD',
            ]);

        $user->refresh();
        $this->assertSame(100.0, (float) $user->wallet_balance_ds);
        $this->assertSame('DZD', $user->wallet_currency);
    }

    public function test_usd_to_da_ceils(): void
    {
        $wallets = app(WalletService::class);
        $this->assertSame(0.0, $wallets->usdToDa(0));
        $this->assertSame(1.0, $wallets->usdToDa(0.001));
        $this->assertSame(14.0, $wallets->usdToDa(0.053));
        $this->assertSame(20.0, $wallets->usdToDa(0.08));
        $this->assertSame(38.0, $wallets->usdToDa(0.15));
    }

    public function test_generate_image_insufficient_does_not_create_asset(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $owner->update(['wallet_balance_ds' => 0]);
        $business->agentSettings->update(['image_model' => 'gpt_image_2']);

        Storage::fake('public');
        config(['services.fal.key' => 'fal_test_key']);
        Http::fake();

        $result = app(GenerateImage::class)->handle($business, [
            'prompt' => 'A clean product photo',
        ], ['user_id' => $owner->id]);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('wallet.insufficient', $result['code'] ?? null);
        $this->assertSame(0, AgentAsset::query()->where('business_id', $business->id)->count());
        Http::assertNothingSent();
    }

    public function test_fal_prompt_forces_darija_on_image_when_brief_asks_darja(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $owner->update(['wallet_balance_ds' => 200]);
        $business->agentSettings->update(['image_model' => 'flux', 'language' => 'darija']);

        Storage::fake('public');
        config(['services.fal.key' => 'fal_test_key']);

        Http::fake([
            'https://queue.fal.run/fal-ai/flux/schnell*' => Http::response([
                'request_id' => 'req_darja_lang',
                'status_url' => 'https://queue.fal.run/fal-ai/flux/schnell/requests/req_darja_lang/status',
                'response_url' => 'https://queue.fal.run/fal-ai/flux/schnell/requests/req_darja_lang',
            ]),
        ]);

        $result = app(GenerateImage::class)->handle($business, [
            'prompt' => 'A vibrant weekly pass poster with bold colors for Facebook',
        ], [
            'user_id' => $owner->id,
            'owner_brief' => 'akhdmli photo 3la 3 weekly pass bl darja bch nkhdmo bih facebook post',
        ]);

        $this->assertTrue($result['pending'] ?? false);

        Http::assertSent(function ($request) {
            if (! str_starts_with($request->url(), 'https://queue.fal.run/fal-ai/flux/schnell')) {
                return false;
            }
            if (str_contains($request->url(), '/requests/')) {
                return false;
            }
            $body = $request->data();
            $prompt = (string) ($body['prompt'] ?? '');

            return str_contains($prompt, 'ON-IMAGE LANGUAGE (MANDATORY)')
                && str_contains($prompt, 'Algerian Darija')
                && str_contains($prompt, 'Arabic script')
                && (str_contains(mb_strtolower($prompt), '3 weekly pass') || str_contains($prompt, 'تمريرة'));
        });
    }

    public function test_fal_prompt_forces_french_on_image_when_brief_asks_francais(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $owner->update(['wallet_balance_ds' => 200]);
        $business->agentSettings->update(['image_model' => 'flux', 'language' => 'french']);

        Storage::fake('public');
        config(['services.fal.key' => 'fal_test_key']);

        Http::fake([
            'https://queue.fal.run/fal-ai/flux/schnell*' => Http::response([
                'request_id' => 'req_fr_lang',
                'status_url' => 'https://queue.fal.run/fal-ai/flux/schnell/requests/req_fr_lang/status',
                'response_url' => 'https://queue.fal.run/fal-ai/flux/schnell/requests/req_fr_lang',
            ]),
        ]);

        $result = app(GenerateImage::class)->handle($business, [
            'prompt' => 'Modern cafe promo poster',
        ], [
            'user_id' => $owner->id,
            'owner_brief' => 'fais une affiche promo en français pour le café',
        ]);

        $this->assertTrue($result['pending'] ?? false);

        Http::assertSent(function ($request) {
            if (! str_starts_with($request->url(), 'https://queue.fal.run/fal-ai/flux/schnell')) {
                return false;
            }
            if (str_contains($request->url(), '/requests/')) {
                return false;
            }
            $prompt = (string) ($request->data()['prompt'] ?? '');

            return str_contains($prompt, 'ON-IMAGE LANGUAGE (MANDATORY)')
                && str_contains($prompt, 'French')
                && ! str_contains($prompt, 'Algerian Darija');
        });
    }
}
