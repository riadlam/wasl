<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\SocialAccount;
use App\Services\OnboardingService;
use App\Services\SocialApi\SocialApiAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReconnectInboxDedupeTest extends TestCase
{
    use RefreshDatabase;

    public function test_disconnect_does_not_reset_finished_onboarding(): void
    {
        ['business' => $business] = $this->makeShop();
        $business->update(['onboarding_status' => \App\Models\Business::ONBOARDING_DONE]);

        app(OnboardingService::class)->onChannelDisconnected($business->fresh());

        $this->assertSame(\App\Models\Business::ONBOARDING_DONE, $business->fresh()->onboarding_status);
    }

    public function test_reconnect_reuses_same_social_account_for_same_page(): void
    {
        ['business' => $business] = $this->makeShop();
        $business->update(['socialapi_brand_id' => 'brand_1']);

        $old = SocialAccount::query()->create([
            'business_id' => $business->id,
            'provider' => 'socialapi',
            'socialapi_account_id' => 'acc_old',
            'socialapi_brand_id' => 'brand_1',
            'platform' => 'facebook',
            'platform_account_id' => 'page_123',
            'name' => 'Shop Page',
            'status' => 'disconnected',
            'disconnected_at' => now(),
        ]);

        $customer = Customer::query()->create([
            'business_id' => $business->id,
            'name' => 'Amina',
            'platform' => 'facebook',
            'platform_user_id' => 'user_9',
        ]);
        $conversation = Conversation::query()->create([
            'business_id' => $business->id,
            'social_account_id' => $old->id,
            'socialapi_conversation_id' => 'conv_old',
            'platform' => 'facebook',
            'customer_id' => $customer->id,
            'status' => 'open',
            'ai_enabled' => true,
        ]);
        Message::query()->create([
            'business_id' => $business->id,
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'socialapi_message_id' => 'msg_a',
            'direction' => 'inbound',
            'type' => 'text',
            'sender_type' => 'customer',
            'sender_id' => $customer->id,
            'text' => 'Salam',
            'status' => 'received',
            'metadata' => ['platform_id' => 'mid.111'],
        ]);

        $reconnected = app(SocialApiAccountService::class)->upsertFromRemote($business, [
            'id' => 'acc_new',
            'brand_id' => 'brand_1',
            'platform' => 'facebook',
            'page' => [
                'page_id' => 'page_123',
                'name' => 'Shop Page',
            ],
        ]);

        $this->assertSame($old->id, $reconnected->id);
        $this->assertSame('acc_new', $reconnected->socialapi_account_id);
        $this->assertSame('connected', $reconnected->status);
        $this->assertSame(1, SocialAccount::query()->where('business_id', $business->id)->where('platform', 'facebook')->count());
        $this->assertSame($old->id, $conversation->fresh()->social_account_id);
    }

    public function test_history_import_does_not_duplicate_by_platform_id(): void
    {
        ['business' => $business] = $this->makeShop();
        $account = SocialAccount::query()->create([
            'business_id' => $business->id,
            'provider' => 'socialapi',
            'socialapi_account_id' => 'acc_1',
            'platform' => 'instagram',
            'platform_account_id' => 'ig_1',
            'status' => 'connected',
        ]);
        $customer = Customer::query()->create([
            'business_id' => $business->id,
            'name' => 'Sara',
            'platform' => 'instagram',
            'platform_user_id' => 'ig_user_1',
        ]);
        $conversation = Conversation::query()->create([
            'business_id' => $business->id,
            'social_account_id' => $account->id,
            'socialapi_conversation_id' => 'conv_1',
            'platform' => 'instagram',
            'customer_id' => $customer->id,
            'status' => 'open',
            'ai_enabled' => true,
        ]);
        Message::query()->create([
            'business_id' => $business->id,
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'socialapi_message_id' => 'webhook_uuid',
            'direction' => 'inbound',
            'type' => 'text',
            'sender_type' => 'customer',
            'sender_id' => $customer->id,
            'text' => 'Hello',
            'status' => 'received',
            'metadata' => ['platform_id' => 'mid.dup'],
            'created_at' => now()->subMinute(),
        ]);

        $method = new \ReflectionMethod(\App\Services\ConversationService::class, 'upsertRemoteMessage');
        $method->setAccessible(true);
        $imported = $method->invoke(
            app(\App\Services\ConversationService::class),
            $conversation,
            [
                'id' => 'list_uuid_different',
                'platform_id' => 'mid.dup',
                'text' => 'Hello',
                'direction' => 'inbound',
                'created_at' => now()->subMinute()->toIso8601String(),
            ],
        );

        $this->assertFalse($imported);
        $this->assertSame(1, Message::query()->where('conversation_id', $conversation->id)->count());
        $this->assertSame('list_uuid_different', Message::query()->first()->socialapi_message_id);
    }
}
