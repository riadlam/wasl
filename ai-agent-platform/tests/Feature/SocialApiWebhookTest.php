<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\SocialAccount;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SocialApiWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_signature_is_rejected(): void
    {
        ['business' => $business] = $this->makeShop();
        $this->connectSocialAccount($business);

        $payload = $this->dmPayload('acc_ig_1', 'Hello');
        $raw = json_encode($payload);

        $this->call('POST', '/api/webhooks/socialapi', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SOCIALAPI_SIGNATURE' => 'sha256=deadbeef',
            'HTTP_X_SOCIALAPI_DELIVERY' => 'del_invalid',
            'HTTP_X_SOCIALAPI_EVENT' => 'dm.received',
        ], $raw)->assertUnauthorized();

        $this->assertDatabaseCount('webhook_events', 0);
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_valid_dm_received_stores_event_without_ai_reply(): void
    {
        ['business' => $business] = $this->makeShop();
        $this->connectSocialAccount($business);

        Http::fake([
            'https://fal.run/*' => Http::response([
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => 'وعليكم السلام'],
                ]],
            ]),
            'https://api.social-api.ai/*' => Http::response(['id' => 'sapi_msg_out_1']),
        ]);

        $payload = $this->dmPayload('acc_ig_1', 'سلام');
        $this->postSignedWebhook($payload, 'del_1', 'dm.received')->assertOk();

        $this->assertDatabaseHas('webhook_events', [
            'provider' => 'socialapi',
            'event_id' => 'del_1',
            'event_type' => 'dm.received',
            'signature_valid' => 1,
        ]);
        $this->assertDatabaseHas('messages', [
            'direction' => 'inbound',
            'text' => 'سلام',
            'socialapi_message_id' => 'sapi_dm_1',
        ]);
        $this->assertFalse(Message::query()->where('ai_generated', true)->exists());
        $this->assertDatabaseHas('conversations', [
            'socialapi_conversation_id' => 'conv_ig_1',
            'platform' => 'instagram',
        ]);
        $this->assertDatabaseHas('customer_social_profiles', [
            'platform' => 'instagram',
            'platform_user_id' => '1784',
            'username' => 'jane.ig',
            'avatar_url' => 'https://example.com/jane.jpg',
        ]);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'fal.run'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/inbox/conversations/'));
    }

    public function test_duplicate_delivery_id_does_not_create_a_second_message(): void
    {
        ['business' => $business] = $this->makeShop();
        $this->connectSocialAccount($business);

        Http::fake([
            'https://fal.run/*' => Http::response([
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => 'ok'],
                ]],
            ]),
            'https://api.social-api.ai/*' => Http::response(['id' => 'sapi_msg_out_dup']),
        ]);

        $payload = $this->dmPayload('acc_ig_1', 'مرة واحدة');
        $this->postSignedWebhook($payload, 'del_dup', 'dm.received')->assertOk();
        $this->postSignedWebhook($payload, 'del_dup', 'dm.received')->assertOk();

        $this->assertSame(1, WebhookEvent::query()->count());
        $this->assertSame(1, Message::query()->where('direction', 'inbound')->count());
    }

    public function test_simulator_reply_does_not_call_socialapi_send(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();

        Http::fake([
            'https://fal.run/*' => Http::response([
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => 'سلام'],
                ]],
            ]),
            'https://api.social-api.ai/*' => Http::response(['id' => 'should-not-send']),
        ]);

        $simulate = $this->asShopUser($owner, $business)
            ->postJson('/api/conversations/simulate', [
                'name' => 'Ahmed',
                'text' => 'سلام',
            ])
            ->assertOk();

        $id = $simulate->json('conversation.id');

        $this->asShopUser($owner, $business)
            ->postJson("/api/conversations/{$id}/messages", ['text' => 'مرحبا من الفريق'])
            ->assertOk();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'social-api.ai'));
    }

    public function test_human_reply_on_socialapi_thread_calls_send_api(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $account = $this->connectSocialAccount($business);
        $customer = $business->customers()->create(['name' => 'Lina']);
        $conversation = Conversation::query()->create([
            'business_id' => $business->id,
            'social_account_id' => $account->id,
            'socialapi_conversation_id' => 'conv_ig_9',
            'platform' => 'instagram',
            'customer_id' => $customer->id,
            'status' => 'human',
            'ai_enabled' => false,
        ]);

        Http::fake([
            'https://api.social-api.ai/*' => Http::response(['id' => 'sapi_msg_human_1']),
        ]);

        $this->asShopUser($owner, $business)
            ->postJson("/api/conversations/{$conversation->id}/messages", ['text' => 'نوصلو الطلب غدوة'])
            ->assertOk();

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && str_contains($request->url(), '/inbox/conversations/conv_ig_9/messages')
                && $request->data()['text'] === 'نوصلو الطلب غدوة'
                && $request->hasHeader('Authorization', 'Bearer sapi_key_test');
        });
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'text' => 'نوصلو الطلب غدوة',
            'socialapi_message_id' => 'sapi_msg_human_1',
        ]);
    }

    public function test_socapi_key_never_appears_in_json(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $this->connectSocialAccount($business);

        Http::fake([
            'https://fal.run/*' => Http::response([
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => 'سلام'],
                ]],
            ]),
            'https://api.social-api.ai/v1/brands' => Http::response([
                'id' => 'brand_secret_test',
                'name' => 'Maison Test',
            ], 201),
            'https://api.social-api.ai/*' => Http::response([
                'auth_url' => 'https://meta.example/oauth',
                'id' => 'msg_x',
            ]),
        ]);

        $key = (string) config('services.socialapi.key');
        $this->assertNotSame('', $key);

        $bodies = [];
        $bodies[] = $this->asShopUser($owner, $business)->getJson('/api/me')->assertOk()->getContent();
        $bodies[] = $this->asShopUser($owner, $business)->getJson('/api/business')->assertOk()->getContent();
        $bodies[] = $this->asShopUser($owner, $business)->getJson('/api/social-accounts')->assertOk()->getContent();
        $bodies[] = $this->asShopUser($owner, $business)
            ->postJson('/api/social-accounts/connect', ['platform' => 'instagram'])
            ->assertOk()
            ->getContent();
        $bodies[] = $this->asShopUser($owner, $business)
            ->postJson('/api/conversations/simulate', ['name' => 'Ahmed', 'text' => 'سلام'])
            ->assertOk()
            ->getContent();
        $bodies[] = $this->postSignedWebhook($this->dmPayload('acc_ig_1', 'hi'), 'del_key', 'dm.received')
            ->assertOk()
            ->getContent();

        foreach ($bodies as $body) {
            $this->assertStringNotContainsString($key, $body);
            $this->assertStringNotContainsString('SOCAPI_KEY', $body);
        }
    }

    public function test_dm_with_referral_stores_meta_ad_id_on_customer(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $this->connectSocialAccount($business);

        $payload = $this->dmPayload('acc_ig_1', 'سلام من الإعلان');
        $payload['data']['referral'] = [
            'ad_id' => '120212345678901234',
            'ad_title' => 'Summer sneakers — Alger',
            'source_id' => '120212345678901234',
        ];

        $this->postSignedWebhook($payload, 'del_ad_1', 'dm.received')->assertOk();

        $customer = $business->customers()->where('name', 'Jane')->first();
        $this->assertNotNull($customer);
        $this->assertSame('120212345678901234', $customer->metadata['meta_ad_id'] ?? null);
        $this->assertSame('Summer sneakers — Alger', $customer->metadata['meta_ad_title'] ?? null);

        $this->asShopUser($owner, $business)
            ->getJson('/api/conversations')
            ->assertOk()
            ->assertJsonPath('conversations.0.meta_ad_id', '120212345678901234')
            ->assertJsonPath('conversations.0.meta_ad_title', 'Summer sneakers — Alger');

        $this->asShopUser($owner, $business)
            ->getJson('/api/customers')
            ->assertOk()
            ->assertJsonPath('customers.0.meta_ad_id', '120212345678901234');
    }

    public function test_dm_referral_event_stores_meta_ad_id_without_ai(): void
    {
        ['business' => $business] = $this->makeShop();
        $this->connectSocialAccount($business);

        Http::fake([
            'https://fal.run/*' => Http::response(['choices' => [['message' => ['content' => 'nope']]]]),
            'https://api.social-api.ai/*' => Http::response(['id' => 'should-not-matter']),
        ]);

        $payload = [
            'event' => 'dm.referral',
            'data' => [
                'id' => 'sapi_ref_1',
                'type' => 'dm',
                'platform' => 'instagram',
                'account_id' => 'acc_ig_1',
                'conversation_id' => 'conv_ig_ref',
                'author' => [
                    'id' => '9001',
                    'name' => 'Karim',
                    'username' => 'karim.ads',
                ],
                'referral' => [
                    'source_id' => '998877665544',
                    'headline' => 'COD Oran',
                ],
                'content' => ['text' => ''],
            ],
        ];

        $this->postSignedWebhook($payload, 'del_ref_1', 'dm.referral')->assertOk();

        $customer = $business->customers()->where('name', 'Karim')->first();
        $this->assertNotNull($customer);
        $this->assertSame('998877665544', $customer->metadata['meta_ad_id'] ?? null);
        $this->assertSame('COD Oran', $customer->metadata['meta_ad_title'] ?? null);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'fal.run'));
    }

    public function test_nested_metadata_referral_and_per_conversation_ads(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $this->connectSocialAccount($business);

        $first = $this->dmPayload('acc_ig_1', 'من الإعلان الأول');
        $first['data']['id'] = 'sapi_dm_ad_a';
        $first['data']['conversation_id'] = 'conv_ad_a';
        $first['data']['metadata'] = [
            'referral' => [
                'ad_id' => '111111111111',
                'ads_context_data' => ['ad_title' => 'Ad Alpha'],
            ],
        ];
        $this->postSignedWebhook($first, 'del_ad_a', 'dm.received')->assertOk();

        $second = $this->dmPayload('acc_ig_1', 'من الإعلان الثاني');
        $second['data']['id'] = 'sapi_dm_ad_b';
        $second['data']['conversation_id'] = 'conv_ad_b';
        $second['data']['metadata'] = [
            'referral' => [
                'source_id' => '222222222222',
                'ads_context_data' => ['ad_title' => 'Ad Beta'],
            ],
        ];
        $this->postSignedWebhook($second, 'del_ad_b', 'dm.received')->assertOk();

        $customer = $business->customers()->where('name', 'Jane')->first();
        $this->assertNotNull($customer);
        $this->assertSame('222222222222', $customer->metadata['meta_ad_id'] ?? null);
        $this->assertCount(2, $customer->metadata['meta_ads'] ?? []);

        $inbox = $this->asShopUser($owner, $business)->getJson('/api/conversations')->assertOk()->json('conversations');
        $byThread = collect($inbox)->keyBy('socialapi_conversation_id');

        $this->assertSame('111111111111', $byThread['conv_ad_a']['meta_ad_id'] ?? null);
        $this->assertSame('Ad Alpha', $byThread['conv_ad_a']['meta_ad_title'] ?? null);
        $this->assertSame('222222222222', $byThread['conv_ad_b']['meta_ad_id'] ?? null);
        $this->assertSame('Ad Beta', $byThread['conv_ad_b']['meta_ad_title'] ?? null);
    }

    private function connectSocialAccount($business): SocialAccount
    {
        return SocialAccount::query()->create([
            'business_id' => $business->id,
            'provider' => 'socialapi',
            'socialapi_account_id' => 'acc_ig_1',
            'platform' => 'instagram',
            'name' => 'Shop IG',
            'status' => 'connected',
            'connected_at' => now(),
        ]);
    }

    private function dmPayload(string $accountId, string $text): array
    {
        return [
            'event' => 'dm.received',
            'data' => [
                'id' => 'sapi_dm_1',
                'type' => 'dm',
                'platform' => 'instagram',
                'account_id' => $accountId,
                'conversation_id' => 'conv_ig_1',
                'author' => [
                    'id' => '1784',
                    'name' => 'Jane',
                    'username' => 'jane.ig',
                    'profile_picture_url' => 'https://example.com/jane.jpg',
                ],
                'content' => ['text' => $text],
            ],
        ];
    }

    private function postSignedWebhook(array $payload, string $delivery, string $event)
    {
        $raw = json_encode($payload);
        $signature = 'sha256='.hash_hmac('sha256', $raw, (string) config('services.socialapi.webhook_secret'));

        return $this->call('POST', '/api/webhooks/socialapi', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SOCIALAPI_SIGNATURE' => $signature,
            'HTTP_X_SOCIALAPI_DELIVERY' => $delivery,
            'HTTP_X_SOCIALAPI_EVENT' => $event,
        ], $raw);
    }
}
