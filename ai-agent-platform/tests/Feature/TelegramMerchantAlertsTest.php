<?php

namespace Tests\Feature;

use App\Jobs\Telegram\SendTelegramAlertJob;
use App\Models\AgentPendingAction;
use App\Models\BusinessTelegramSetting;
use App\Models\TelegramOutboundMessage;
use App\Services\OrderService;
use App\Services\Telegram\TelegramLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramMerchantAlertsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.bot_username' => 'WaslTestBot',
            'services.telegram.webhook_secret' => 'hook-secret',
        ]);
    }

    public function test_link_webhook_binds_chat_id(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $link = app(TelegramLinkService::class)->createLink($business);

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1, 'chat' => ['id' => 999]]], 200),
        ]);

        $this->postJson('/api/telegram/webhook/hook-secret', [
            'message' => [
                'text' => '/start '.$link['code'],
                'chat' => ['id' => 555001],
                'from' => ['username' => 'merchant_riad'],
            ],
        ])->assertOk();

        $settings = BusinessTelegramSetting::query()->where('business_id', $business->id)->first();
        $this->assertNotNull($settings);
        $this->assertSame('555001', $settings->telegram_chat_id);
        $this->assertSame('merchant_riad', $settings->telegram_username);
        $this->assertTrue($settings->isLinked());
    }

    public function test_settings_api_returns_status_and_updates_toggles(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        BusinessTelegramSetting::query()->create([
            'business_id' => $business->id,
            'telegram_chat_id' => '111',
            'linked_at' => now(),
            'enabled' => true,
            'notify_new_orders' => true,
            'notify_ai_needs_human' => true,
            'notify_post_events' => true,
        ]);

        $this->asShopUser($owner, $business)
            ->getJson('/api/telegram')
            ->assertOk()
            ->assertJsonPath('linked', true)
            ->assertJsonPath('configured', true);

        $this->asShopUser($owner, $business)
            ->putJson('/api/telegram', ['notify_new_orders' => false])
            ->assertOk()
            ->assertJsonPath('notify_new_orders', false);
    }

    public function test_order_create_dispatches_telegram_job_when_linked(): void
    {
        Bus::fake([SendTelegramAlertJob::class]);
        ['business' => $business] = $this->makeShop();
        BusinessTelegramSetting::query()->create([
            'business_id' => $business->id,
            'telegram_chat_id' => '42',
            'linked_at' => now(),
            'enabled' => true,
            'notify_new_orders' => true,
            'notify_ai_needs_human' => true,
            'notify_post_events' => true,
        ]);
        $customer = $business->customers()->create([
            'name' => 'Karim',
            'phone' => '0555111222',
            'wilaya' => 'Biskra',
        ]);
        $product = $business->products()->first();

        $created = app(OrderService::class)->create($business, $customer, [
            'product_id' => $product->id,
            'quantity' => 1,
            'phone' => '0555111222',
            'wilaya' => 'Biskra',
            'delivery_type' => 'home',
        ]);
        $this->assertTrue($created['ok']);

        Bus::assertDispatched(SendTelegramAlertJob::class, function (SendTelegramAlertJob $job) {
            return $job->kind === TelegramOutboundMessage::KIND_ORDER_CREATED
                && $job->mode === 'send'
                && str_contains($job->text, '🛒')
                && str_contains($job->text, '<b>New order</b>');
        });
    }

    public function test_order_create_skips_when_toggle_off(): void
    {
        Bus::fake([SendTelegramAlertJob::class]);
        ['business' => $business] = $this->makeShop();
        BusinessTelegramSetting::query()->create([
            'business_id' => $business->id,
            'telegram_chat_id' => '42',
            'linked_at' => now(),
            'enabled' => true,
            'notify_new_orders' => false,
            'notify_ai_needs_human' => true,
            'notify_post_events' => true,
        ]);
        $customer = $business->customers()->create(['name' => 'Karim', 'phone' => '0555111222', 'wilaya' => 'Biskra']);
        $product = $business->products()->first();

        app(OrderService::class)->create($business, $customer, [
            'product_id' => $product->id,
            'quantity' => 1,
            'phone' => '0555111222',
            'wilaya' => 'Biskra',
            'delivery_type' => 'home',
        ]);

        Bus::assertNotDispatched(SendTelegramAlertJob::class);
    }

    public function test_webhook_approve_confirms_pending_and_edits_message(): void
    {
        Bus::fake([SendTelegramAlertJob::class]);
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        BusinessTelegramSetting::query()->create([
            'business_id' => $business->id,
            'telegram_chat_id' => '777',
            'linked_at' => now(),
            'enabled' => true,
            'notify_post_events' => true,
        ]);

        $action = AgentPendingAction::query()->create([
            'business_id' => $business->id,
            'user_id' => $owner->id,
            'type' => AgentPendingAction::TYPE_CREATE_POST,
            'payload' => [
                'account_ids' => [],
                'text' => 'Hello',
                'media_ids' => [],
                'scheduled_at' => now()->addHour()->toIso8601String(),
                'publish_now' => false,
            ],
            'status' => AgentPendingAction::STATUS_PENDING,
            'summary' => 'Create post test',
        ]);

        TelegramOutboundMessage::query()->create([
            'business_id' => $business->id,
            'subject_type' => AgentPendingAction::class,
            'subject_id' => $action->id,
            'kind' => TelegramOutboundMessage::KIND_PENDING_POST,
            'chat_id' => '777',
            'message_id' => '88',
            'last_text' => 'pending',
        ]);

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => true], 200),
        ]);

        $this->postJson('/api/telegram/webhook/hook-secret', [
            'callback_query' => [
                'id' => 'cb1',
                'data' => 'tg:no:'.$action->id,
                'message' => ['chat' => ['id' => 777], 'message_id' => 88],
            ],
        ])->assertOk();

        $this->assertSame(AgentPendingAction::STATUS_CANCELLED, $action->fresh()->status);
        Bus::assertDispatched(SendTelegramAlertJob::class, function (SendTelegramAlertJob $job) {
            return $job->mode === 'edit' && $job->kind === TelegramOutboundMessage::KIND_PENDING_POST;
        });
    }

    public function test_unlink_clears_chat_id(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        BusinessTelegramSetting::query()->create([
            'business_id' => $business->id,
            'telegram_chat_id' => '999',
            'telegram_username' => 'x',
            'linked_at' => now(),
        ]);

        $this->asShopUser($owner, $business)
            ->deleteJson('/api/telegram')
            ->assertOk()
            ->assertJsonPath('linked', false);

        $this->assertNull(BusinessTelegramSetting::query()->where('business_id', $business->id)->value('telegram_chat_id'));
    }

    public function test_invalid_webhook_secret_is_404(): void
    {
        $this->postJson('/api/telegram/webhook/wrong', ['message' => ['text' => '/start x']])->assertNotFound();
    }
}
