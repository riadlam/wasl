<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Wallet\WalletService;
use App\Support\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_cannot_create_products(): void
    {
        ['staff' => $staff, 'business' => $business] = $this->makeShop();

        $this->asShopUser($staff, $business)
            ->postJson('/api/products', [
                'name' => 'Hat',
                'price' => 1000,
                'stock' => 2,
            ])
            ->assertForbidden();
    }

    public function test_owner_can_create_staff_with_inbox_only(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $facebook = $this->makeChannel($business, 'facebook', 'acc_fb_team');

        $this->asShopUser($owner, $business)
            ->postJson('/api/team', [
                'name' => 'Lina',
                'email' => 'lina@example.test',
                'password' => 'password123',
                'permissions' => [Permission::InboxView->value, Permission::InboxReply->value],
                'channel_ids' => [$facebook->id],
            ])
            ->assertCreated()
            ->assertJsonPath('member.role', 'staff')
            ->assertJsonPath('member.wallet', 'shop')
            ->assertJsonPath('member.channel_ids.0', $facebook->id);

        $this->assertDatabaseHas('business_users', [
            'business_id' => $business->id,
            'role' => 'staff',
        ]);
    }

    public function test_staff_inbox_is_limited_to_assigned_channel(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $facebook = $this->makeChannel($business, 'facebook', 'acc_fb_scope');
        $instagram = $this->makeChannel($business, 'instagram', 'acc_ig_scope');

        $this->asShopUser($owner, $business)
            ->postJson('/api/team', [
                'name' => 'FB Only',
                'email' => 'fb.only@example.test',
                'password' => 'password123',
                'permissions' => [Permission::InboxView->value],
                'channel_ids' => [$facebook->id],
            ])
            ->assertCreated();

        $staff = User::query()->where('email', 'fb.only@example.test')->firstOrFail();

        $customer = Customer::query()->create([
            'business_id' => $business->id,
            'name' => 'Buyer',
        ]);

        Conversation::query()->create([
            'business_id' => $business->id,
            'social_account_id' => $facebook->id,
            'socialapi_conversation_id' => 'conv_fb',
            'platform' => 'facebook',
            'customer_id' => $customer->id,
            'status' => 'open',
            'ai_enabled' => true,
            'last_message_at' => now(),
        ]);

        Conversation::query()->create([
            'business_id' => $business->id,
            'social_account_id' => $instagram->id,
            'socialapi_conversation_id' => 'conv_ig',
            'platform' => 'instagram',
            'customer_id' => $customer->id,
            'status' => 'open',
            'ai_enabled' => true,
            'last_message_at' => now(),
        ]);

        $this->asShopUser($staff, $business)
            ->getJson('/api/conversations')
            ->assertOk()
            ->assertJsonCount(1, 'conversations')
            ->assertJsonPath('conversations.0.platform', 'facebook');

        $this->asShopUser($staff, $business)
            ->getJson('/api/social-accounts')
            ->assertOk()
            ->assertJsonCount(1, 'accounts')
            ->assertJsonPath('accounts.0.platform', 'facebook');
    }

    public function test_foreign_channel_ids_are_rejected(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $local = $this->makeChannel($business, 'facebook', 'acc_fb_local');

        $otherOwner = User::factory()->create(['email' => 'other.owner@example.test']);
        $other = app(\App\Services\ShopProvisioner::class)->createShop($otherOwner, 'Other Shop');
        $foreign = $this->makeChannel($other, 'instagram', 'acc_ig_foreign');

        $this->asShopUser($owner, $business)
            ->postJson('/api/team', [
                'name' => 'Bad Scope',
                'email' => 'bad.scope@example.test',
                'password' => 'password123',
                'permissions' => [Permission::InboxView->value],
                'channel_ids' => [$local->id, $foreign->id],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['channel_ids']);
    }

    public function test_channel_scoped_staff_still_charges_owner_wallet(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $facebook = $this->makeChannel($business, 'facebook', 'acc_fb_wallet');
        $owner->update(['wallet_balance_da' => 100]);

        $this->asShopUser($owner, $business)
            ->postJson('/api/team', [
                'name' => 'Wallet Staff',
                'email' => 'wallet.staff@example.test',
                'password' => 'password123',
                'permissions' => [Permission::AgentsView->value],
                'channel_ids' => [$facebook->id],
            ])
            ->assertCreated();

        $staff = User::query()->where('email', 'wallet.staff@example.test')->firstOrFail();
        $staff->update(['wallet_balance_da' => 50]);

        $resolved = app(WalletService::class)->ownerForBusiness($business);
        $this->assertTrue($resolved->is($owner));
        $this->assertFalse($resolved->is($staff));

        app(WalletService::class)->chargeOwnerDa($business, 20, null, 'agent_chat', $staff);
        $this->assertSame(80.0, (float) $owner->fresh()->wallet_balance_da);
        $this->assertSame(50.0, (float) $staff->fresh()->wallet_balance_da);
    }

    private function makeChannel(Business $business, string $platform, string $remoteId): SocialAccount
    {
        return SocialAccount::query()->create([
            'business_id' => $business->id,
            'provider' => 'socialapi',
            'socialapi_account_id' => $remoteId,
            'platform' => $platform,
            'name' => ucfirst($platform).' Page',
            'status' => 'connected',
            'connected_at' => now(),
        ]);
    }
}
