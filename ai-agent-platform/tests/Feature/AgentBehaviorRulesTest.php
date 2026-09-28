<?php

namespace Tests\Feature;

use App\AI\Prompts\BusinessAgentPrompt;
use App\AI\Prompts\OwnerAgentPrompt;
use App\Models\AgentBehaviorRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentBehaviorRulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_behavior_rules_crud(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();

        $this->asShopUser($owner, $business)
            ->postJson('/api/agent/behavior-rules', [
                'polarity' => 'should',
                'body' => 'Always greet in Darija',
            ])
            ->assertCreated()
            ->assertJsonPath('rule.polarity', 'should')
            ->assertJsonPath('rule.body', 'Always greet in Darija');

        $id = AgentBehaviorRule::query()->firstOrFail()->id;

        $this->asShopUser($owner, $business)
            ->getJson('/api/agent/behavior-rules')
            ->assertOk()
            ->assertJsonCount(1, 'rules');

        $this->asShopUser($owner, $business)
            ->putJson('/api/agent/behavior-rules/'.$id, [
                'body' => 'Always greet warmly in Darija',
            ])
            ->assertOk()
            ->assertJsonPath('rule.body', 'Always greet warmly in Darija');

        $this->asShopUser($owner, $business)
            ->deleteJson('/api/agent/behavior-rules/'.$id)
            ->assertOk();

        $this->assertDatabaseMissing('agent_behavior_rules', ['id' => $id]);
    }

    public function test_rules_inject_into_owner_and_customer_prompts(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();

        AgentBehaviorRule::query()->create([
            'business_id' => $business->id,
            'polarity' => AgentBehaviorRule::POLARITY_SHOULD,
            'body' => 'Offer cash on delivery first',
            'sort_order' => 1,
        ]);
        AgentBehaviorRule::query()->create([
            'business_id' => $business->id,
            'polarity' => AgentBehaviorRule::POLARITY_MUST_NOT,
            'body' => 'Never invent discounts',
            'sort_order' => 1,
        ]);

        $ownerPrompt = app(OwnerAgentPrompt::class)->system($business, $owner);
        $this->assertStringContainsString('HARD BUSINESS RULES', $ownerPrompt);
        $this->assertStringContainsString('Offer cash on delivery first', $ownerPrompt);
        $this->assertStringContainsString('Never invent discounts', $ownerPrompt);

        $customerPrompt = app(BusinessAgentPrompt::class)->system(
            $business,
            $business->agent,
            $business->agentSettings,
            null,
        );
        $this->assertStringContainsString('HARD BUSINESS RULES', $customerPrompt);
        $this->assertStringContainsString('Offer cash on delivery first', $customerPrompt);
        $this->assertStringContainsString('Never invent discounts', $customerPrompt);
    }

    public function test_behavior_rules_block_includes_must_not_for_campaign_paths(): void
    {
        ['business' => $business] = $this->makeShop();

        AgentBehaviorRule::query()->create([
            'business_id' => $business->id,
            'polarity' => AgentBehaviorRule::POLARITY_MUST_NOT,
            'body' => 'Do not mention competitor brands',
            'sort_order' => 1,
        ]);

        $block = app(\App\Services\Agents\BehaviorRulesPrompt::class)->block($business);
        $this->assertStringContainsString('HARD BUSINESS RULES', $block);
        $this->assertStringContainsString('MUST NOT', $block);
        $this->assertStringContainsString('Do not mention competitor brands', $block);
    }
}
