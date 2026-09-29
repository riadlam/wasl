<?php

namespace Tests\Feature;

use App\Models\AiTaskCharge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAiUsageTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_list_ai_usage_charges(): void
    {
        ['admin' => $admin, 'owner' => $owner, 'business' => $business] = $this->makeShop();
        $owner->update(['wallet_balance_da' => 100]);

        AiTaskCharge::query()->create([
            'business_id' => $business->id,
            'user_id' => $owner->id,
            'actor_user_id' => $owner->id,
            'task_type' => AiTaskCharge::TYPE_AGENT_DM_REPLY,
            'model_key' => 'gemini',
            'provider_model' => 'google/gemini-2.5-flash',
            'cost_usd' => 0.02,
            'cost_da' => 5,
            'usd_to_da' => 250,
            'status' => AiTaskCharge::STATUS_CHARGED,
            'meta' => ['surface' => 'dm'],
        ]);

        $this->actingAs($admin)
            ->getJson('/api/admin/ai-usage')
            ->assertOk()
            ->assertJsonPath('charges.0.task_type', AiTaskCharge::TYPE_AGENT_DM_REPLY)
            ->assertJsonPath('charges.0.business_id', $business->id)
            ->assertJsonPath('charges.0.cost_da', 5)
            ->assertJsonStructure(['task_types', 'pagination']);

        $this->actingAs($admin)
            ->getJson('/api/admin/ai-usage?task_type='.AiTaskCharge::TYPE_AGENT_CHAT)
            ->assertOk()
            ->assertJsonCount(0, 'charges');

        $this->actingAs($admin)
            ->getJson('/api/admin/wallet/balances')
            ->assertOk()
            ->assertJsonPath('balances.0.business_id', $business->id)
            ->assertJsonPath('balances.0.balance_da', 100)
            ->assertJsonPath('balances.0.owner_user_id', $owner->id);
    }

    public function test_owner_cannot_access_admin_usage(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();

        $this->asShopUser($owner, $business)
            ->getJson('/api/admin/ai-usage')
            ->assertForbidden();

        $this->asShopUser($owner, $business)
            ->getJson('/api/admin/wallet/balances')
            ->assertForbidden();
    }

    public function test_tenant_list_includes_wallet_balance(): void
    {
        ['admin' => $admin, 'owner' => $owner] = $this->makeShop();
        $owner->update(['wallet_balance_da' => 42]);

        $this->actingAs($admin)
            ->getJson('/api/admin/businesses')
            ->assertOk()
            ->assertJsonPath('businesses.0.wallet_balance_da', 42)
            ->assertJsonPath('businesses.0.owner_user_id', $owner->id);
    }
}
