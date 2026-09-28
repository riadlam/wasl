<?php

namespace Tests\Feature;

use App\AI\Memory\ConversationMemory;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\SocialAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationMemoryTimestampsTest extends TestCase
{
    use RefreshDatabase;

    public function test_history_messages_include_timestamps_and_age(): void
    {
        ['business' => $business] = $this->makeShop();
        $account = SocialAccount::query()->create([
            'business_id' => $business->id,
            'provider' => 'socialapi',
            'socialapi_account_id' => 'acc_ts',
            'platform' => 'facebook',
            'name' => 'Shop',
            'status' => 'connected',
            'connected_at' => now(),
        ]);
        $customer = Customer::query()->create([
            'business_id' => $business->id,
            'platform' => 'facebook',
            'external_id' => 'u_ts',
            'name' => 'Buyer',
            'phone' => '0555123456',
        ]);
        $conversation = Conversation::query()->create([
            'business_id' => $business->id,
            'social_account_id' => $account->id,
            'customer_id' => $customer->id,
            'platform' => 'facebook',
            'ai_enabled' => true,
            'status' => 'open',
        ]);

        $old = Message::query()->create([
            'business_id' => $business->id,
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'direction' => 'inbound',
            'type' => 'text',
            'sender_type' => 'customer',
            'text' => 'رقمي 0555123456',
            'status' => 'received',
            'created_at' => now()->subDays(3),
            'updated_at' => now()->subDays(3),
        ]);
        Message::query()->create([
            'business_id' => $business->id,
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'direction' => 'outbound',
            'type' => 'text',
            'sender_type' => 'agent',
            'text' => 'واخا',
            'ai_generated' => true,
            'status' => 'sent',
            'created_at' => now()->subMinutes(2),
            'updated_at' => now()->subMinutes(2),
        ]);

        $rows = app(ConversationMemory::class)->messages($conversation);
        $this->assertCount(2, $rows);
        $this->assertStringContainsString('ago', $rows[0]['content']);
        $this->assertStringContainsString('رقمي 0555123456', $rows[0]['content']);
        $this->assertNotEmpty($rows[0]['at'] ?? null);
        $this->assertSame('user', $rows[0]['role']);
        $this->assertSame('assistant', $rows[1]['role']);
        $this->assertTrue($old->created_at->lt(now()->subDay()));
    }
}
