<?php

namespace Tests\Feature;

use App\Models\AiTaskCharge;
use App\Services\Wallet\AiTaskBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FalUsageBillingTest extends TestCase
{
    use RefreshDatabase;

    public function test_chat_bills_fal_usage_cost_not_catalog_flat(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $owner->update(['wallet_balance_da' => 200]);
        $business->agentSettings->update(['llm_model' => 'gemini']);

        Http::fake([
            'https://fal.run/*' => Http::response([
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => 'Salam'],
                ]],
                'usage' => [
                    'prompt_tokens' => 100,
                    'completion_tokens' => 20,
                    'cost' => 0.012, // round(0.012*250)=3 DA
                ],
            ]),
        ]);

        $this->asShopUser($owner, $business)
            ->postJson('/api/agent/chat', ['text' => 'salam'])
            ->assertOk();

        $this->assertSame(197.0, (float) $owner->fresh()->wallet_balance_da);
        $charge = AiTaskCharge::query()->latest('id')->first();
        $this->assertSame(0.012, (float) $charge->cost_usd);
        $this->assertSame(3.0, (float) $charge->cost_da);
        $this->assertSame('fal_usage_cost', $charge->meta['billed_from'] ?? null);
    }

    public function test_chat_falls_back_to_catalog_when_fal_omits_cost(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $owner->update(['wallet_balance_da' => 200]);

        Http::fake([
            'https://fal.run/*' => Http::response([
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => 'Ok'],
                ]],
                'usage' => [
                    'prompt_tokens' => 10,
                    'completion_tokens' => 5,
                ],
            ]),
        ]);

        $this->asShopUser($owner, $business)
            ->postJson('/api/agent/chat', ['text' => 'hi'])
            ->assertOk();

        $this->assertSame(175.0, (float) $owner->fresh()->wallet_balance_da);
        $this->assertSame(
            'catalog_estimate_missing_fal_cost',
            AiTaskCharge::query()->latest('id')->value('meta')['billed_from'] ?? null
        );
    }

    public function test_no_charge_when_fal_never_ran(): void
    {
        $bill = app(AiTaskBillingService::class)->resolveChatBill('claude_sonnet', [
            'fal_calls' => 0,
            'cost_usd' => 0,
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'calls_with_cost' => 0,
        ]);

        $this->assertNull($bill);
    }

    public function test_absurd_fal_cost_is_clamped(): void
    {
        $bill = app(AiTaskBillingService::class)->resolveChatBill('claude_sonnet', [
            'fal_calls' => 1,
            'cost_usd' => 50.0,
            'prompt_tokens' => 10,
            'completion_tokens' => 10,
            'calls_with_cost' => 1,
        ]);

        $this->assertSame(2.0, $bill['cost_usd']);
        $this->assertSame('fal_usage_cost_clamped', $bill['billed_from']);
    }

    public function test_tiny_fal_cost_rounds_not_ceil_to_one_da(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $owner->update(['wallet_balance_da' => 200]);
        $business->agentSettings->update(['llm_model' => 'gemini']);

        Http::fake([
            'https://fal.run/*' => Http::response([
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => 'ok'],
                ]],
                'usage' => [
                    'prompt_tokens' => 5,
                    'completion_tokens' => 2,
                    'cost' => 0.001, // round = 0.25 DA, not ceil 1
                ],
            ]),
        ]);

        $this->asShopUser($owner, $business)
            ->postJson('/api/agent/chat', ['text' => 'hi'])
            ->assertOk();

        $this->assertEqualsWithDelta(199.75, (float) $owner->fresh()->wallet_balance_da, 0.001);
    }
}
