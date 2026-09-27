<?php

namespace Tests\Feature;

use App\AI\Prompts\BusinessAgentPrompt;
use App\AI\Prompts\OwnerAgentPrompt;
use App\Models\Agent;
use App\Models\AgentSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReplyLanguageTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_prompt_replies_in_french_without_copying_user_language(): void
    {
        ['business' => $business] = $this->makeShop();
        Agent::query()->where('business_id', $business->id)->update(['language' => 'French']);
        AgentSetting::query()->where('business_id', $business->id)->update(['language' => 'French']);

        $text = app(OwnerAgentPrompt::class)->system($business->fresh(), null, 'darija');

        $this->assertStringContainsString('Reply language (mandatory): French', $text);
        $this->assertStringContainsString('Do not copy the user\'s language', $text);
        $this->assertStringNotContainsString('Reply in the same language the user writes', $text);
        $this->assertStringContainsString('Speech input hint only', $text);
    }

    public function test_customer_prompt_replies_in_darija(): void
    {
        ['business' => $business] = $this->makeShop();
        $business->load(['agent', 'agentSettings']);

        $text = app(BusinessAgentPrompt::class)->system(
            $business,
            $business->agent,
            $business->agentSettings,
            null,
        );

        $this->assertStringContainsString('Reply language (mandatory): Algerian Darija', $text);
        $this->assertStringContainsString('Arabic script only', $text);
        $this->assertStringContainsString('arabizi', $text);
        $this->assertStringContainsString('Translate the reply; do not reinterpret it.', $text);
        $this->assertStringNotContainsString('Language: Darija.', $text);
    }

    public function test_put_agent_language_updates_agent_and_settings(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();

        $this->asShopUser($owner, $business)
            ->putJson('/api/agent', ['language' => 'French'])
            ->assertOk()
            ->assertJsonPath('agent.language', 'French')
            ->assertJsonPath('settings.language', 'French');

        $this->assertSame('French', Agent::query()->where('business_id', $business->id)->value('language'));
        $this->assertSame('French', AgentSetting::query()->where('business_id', $business->id)->value('language'));
    }
}
