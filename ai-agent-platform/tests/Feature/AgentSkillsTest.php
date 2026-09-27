<?php

namespace Tests\Feature;

use App\AI\Prompts\BusinessAgentPrompt;
use App\AI\Prompts\ChannelAiProfilePrompt;
use App\AI\Prompts\OwnerAgentPrompt;
use App\AI\Skills\ReplyGroundingGuard;
use App\AI\Skills\SkillRegistry;
use App\Models\Agent;
use App\Models\AgentSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentSkillsTest extends TestCase
{
    use RefreshDatabase;

    public function test_registry_loads_frontmatter_and_skips_disabled_skill(): void
    {
        $registry = app(SkillRegistry::class);
        $names = array_keys($registry->all());
        $this->assertContains('dm-closer', $names);
        $this->assertContains('channel-identity', $names);

        config(['ai_skills.surfaces.dm' => ['grounded-facts', 'reply-language']]);
        ['business' => $business] = $this->makeShop();
        $prompt = $registry->promptFor('dm', $business);

        $this->assertStringContainsString('Skill: grounded-facts', $prompt);
        $this->assertStringNotContainsString('Skill: dm-closer', $prompt);
    }

    public function test_dm_comment_and_owner_prompts_include_their_skills_and_language(): void
    {
        ['business' => $business] = $this->makeShop();
        Agent::query()->where('business_id', $business->id)->update(['language' => 'French']);
        AgentSetting::query()->where('business_id', $business->id)->update(['language' => 'French']);
        $business->load(['agent', 'agentSettings']);

        $dm = app(BusinessAgentPrompt::class)->system($business, $business->agent, $business->agentSettings, null, [], true, null, null, 'dm');
        $comment = app(BusinessAgentPrompt::class)->system($business, $business->agent, $business->agentSettings, null, [], true, null, null, 'comment');
        $owner = app(OwnerAgentPrompt::class)->system($business->fresh(), null, 'darija');
        $profile = app(ChannelAiProfilePrompt::class)->system();

        $this->assertStringContainsString('Skill: dm-closer', $dm);
        $this->assertStringContainsString('French', $dm);
        $this->assertStringContainsString('Arabic script only', $dm);
        $this->assertStringContainsString('Skill: comment-reply', $comment);
        $this->assertStringNotContainsString('Skill: dm-closer', $comment);
        $this->assertStringContainsString('Skill: owner-assistant', $owner);
        $this->assertStringContainsString('Skill: post-draft', $owner);
        $this->assertStringContainsString('Skill: image-gen', $owner);
        $this->assertStringContainsString('generate_image', $owner);
        $this->assertStringContainsString('FORBIDDEN', $owner);
        $this->assertStringContainsString('هل أنت موافق', $owner);
        $this->assertStringContainsString('French', $owner);
        $this->assertStringContainsString('Skill: channel-identity', $profile);
        $this->assertStringContainsString('"version": 2', $profile);
    }

    public function test_price_post_check_regenerates_once_then_falls_back(): void
    {
        $guard = new ReplyGroundingGuard();
        $calls = 0;
        $safe = $guard->ensure(
            'The price is 4500 DA.',
            ['quoted 2500'],
            function () use (&$calls): string {
                $calls++;

                return 'Still 4500.';
            },
            'I will check.',
        );

        $this->assertSame(1, $calls);
        $this->assertSame('I will check.', $safe);

        $kept = $guard->ensure(
            'It is 2500.',
            ['quoted 2500'],
            fn (): string => 'should not run',
            'I will check.',
        );
        $this->assertSame('It is 2500.', $kept);
    }

    public function test_digital_offer_does_not_ask_delivery(): void
    {
        $gaps = app(\App\Services\ProfileInterviewService::class)->detectGaps([
            'business' => ['offer_type' => 'digital', 'what_we_sell' => 'online course'],
            'operations' => ['delivery' => '', 'returns' => '', 'hours' => ''],
        ]);
        $paths = array_column($gaps, 'field_path');
        $this->assertNotContains('operations.delivery', $paths);
        $this->assertNotContains('operations.returns', $paths);
        $this->assertContains('operations.hours', $paths);
    }
}
