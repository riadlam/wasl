<?php

namespace Tests\Feature;

use App\Exceptions\InsufficientWalletException;
use App\Models\User;
use App\Models\WalletLedger;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalletTest extends TestCase
{
    use RefreshDatabase;

    public function test_usd_to_da_ceils_at_fx_rate(): void
    {
        $wallets = app(WalletService::class);

        $this->assertSame(0.0, $wallets->usdToDa(0));
        $this->assertSame(3.0, $wallets->usdToDa(0.01)); // ceil(2.5)=3
        $this->assertSame(25.0, $wallets->usdToDa(0.10));
        $this->assertSame(38.0, $wallets->usdToDa(0.15));
        $this->assertSame(50.0, $wallets->usdToDa(0.20));
    }

    public function test_owner_debit_reduces_balance_and_writes_ledger(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $owner->update(['wallet_balance_da' => 100]);

        $entry = app(WalletService::class)->chargeOwnerDa(
            $business,
            30,
            null,
            'agent_chat',
            $owner,
        );

        $this->assertSame(70.0, (float) $owner->fresh()->wallet_balance_da);
        $this->assertSame(WalletLedger::DIRECTION_DEBIT, $entry->direction);
        $this->assertSame(30.0, (float) $entry->amount_da);
        $this->assertSame(70.0, (float) $entry->balance_after);
        $this->assertDatabaseHas('wallet_ledger', [
            'user_id' => $owner->id,
            'business_id' => $business->id,
            'actor_user_id' => $owner->id,
            'reason' => 'agent_chat',
        ]);
    }

    public function test_staff_action_charges_owner_not_staff(): void
    {
        ['owner' => $owner, 'staff' => $staff, 'business' => $business] = $this->makeShop();
        $owner->update(['wallet_balance_da' => 50]);
        $staff->update(['wallet_balance_da' => 99]);

        app(WalletService::class)->chargeOwnerDa(
            $business,
            20,
            null,
            'fal_llm',
            $staff,
        );

        $this->assertSame(30.0, (float) $owner->fresh()->wallet_balance_da);
        $this->assertSame(99.0, (float) $staff->fresh()->wallet_balance_da);
        $this->assertDatabaseHas('wallet_ledger', [
            'user_id' => $owner->id,
            'actor_user_id' => $staff->id,
            'amount_da' => 20,
        ]);
    }

    public function test_insufficient_funds_does_not_debit(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $owner->update(['wallet_balance_da' => 10]);

        try {
            app(WalletService::class)->chargeOwnerDa($business, 50, null, 'agent_chat', $owner);
            $this->fail('Expected InsufficientWalletException');
        } catch (InsufficientWalletException $e) {
            $this->assertSame(50.0, $e->required);
            $this->assertSame(10.0, $e->available);
        }

        $this->assertSame(10.0, (float) $owner->fresh()->wallet_balance_da);
        $this->assertDatabaseCount('wallet_ledger', 0);
    }

    public function test_charge_from_usd_uses_ceil_da(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $owner->update(['wallet_balance_da' => 100]);

        app(WalletService::class)->chargeFromUsd(
            $business,
            0.15,
            'fal_image',
            $owner,
        );

        // ceil(0.15 * 250) = 38
        $this->assertSame(62.0, (float) $owner->fresh()->wallet_balance_da);
    }

    public function test_me_exposes_owner_wallet_for_staff(): void
    {
        ['owner' => $owner, 'staff' => $staff, 'business' => $business] = $this->makeShop();
        $owner->update(['wallet_balance_da' => 125]);

        $this->asShopUser($staff, $business)
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('wallet.balance_da', 125)
            ->assertJsonPath('wallet.balance', 125)
            ->assertJsonPath('wallet.owner_user_id', $owner->id)
            ->assertJsonPath('wallet.is_owner', false)
            ->assertJsonPath('wallet.currency', 'DZD')
            ->assertJsonPath('wallet.usd_to_da', 250);
    }

    public function test_wallet_show_and_ledger_for_owner(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $owner->update(['wallet_balance_da' => 40]);
        app(WalletService::class)->debit($owner, 10, 'agent_chat', $business, $owner);

        $this->asShopUser($owner, $business)
            ->getJson('/api/wallet')
            ->assertOk()
            ->assertJsonPath('wallet.balance_da', 30)
            ->assertJsonPath('wallet.currency', 'DZD');

        $this->asShopUser($owner, $business)
            ->getJson('/api/wallet/ledger')
            ->assertOk()
            ->assertJsonPath('ledger.0.amount_da', 10)
            ->assertJsonPath('ledger.0.direction', 'debit');
    }

    public function test_staff_cannot_view_ledger(): void
    {
        ['staff' => $staff, 'business' => $business, 'owner' => $owner] = $this->makeShop();
        $owner->update(['wallet_balance_da' => 40]);

        $this->asShopUser($staff, $business)
            ->getJson('/api/wallet/ledger')
            ->assertForbidden();
    }

    public function test_super_admin_can_topup_owner(): void
    {
        ['admin' => $admin, 'owner' => $owner, 'business' => $business] = $this->makeShop();
        $owner->update(['wallet_balance_da' => 0]);

        $this->actingAs($admin)
            ->postJson('/api/admin/wallet/topup', [
                'user_id' => $owner->id,
                'amount_da' => 250,
                'business_id' => $business->id,
                'note' => 'manual grant',
            ])
            ->assertOk()
            ->assertJsonPath('wallet.balance_da', 250)
            ->assertJsonPath('wallet.currency', 'DZD');

        $this->assertSame(250.0, (float) $owner->fresh()->wallet_balance_da);
        $this->assertDatabaseHas('wallet_ledger', [
            'user_id' => $owner->id,
            'direction' => 'credit',
            'amount_da' => 250,
            'reason' => 'topup',
        ]);
    }

    public function test_non_admin_cannot_topup(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();

        $this->asShopUser($owner, $business)
            ->postJson('/api/admin/wallet/topup', [
                'user_id' => $owner->id,
                'amount_da' => 10,
            ])
            ->assertForbidden();
    }

    public function test_locked_debit_prevents_overdraft_under_serial_pressure(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $owner->update(['wallet_balance_da' => 30]);
        $wallets = app(WalletService::class);

        $ok = 0;
        $fail = 0;
        foreach ([20, 20, 20] as $amount) {
            try {
                $wallets->chargeOwnerDa($business, $amount, null, 'agent_chat', $owner);
                $ok++;
            } catch (InsufficientWalletException) {
                $fail++;
            }
        }

        $this->assertSame(1, $ok);
        $this->assertSame(2, $fail);
        $this->assertSame(10.0, (float) $owner->fresh()->wallet_balance_da);
        $this->assertSame(1, WalletLedger::query()->count());
    }
}
