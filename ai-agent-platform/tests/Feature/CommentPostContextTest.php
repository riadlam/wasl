<?php

namespace Tests\Feature;

use App\AI\Prompts\BusinessAgentPrompt;
use App\Models\AgentBehaviorRule;
use App\Services\Comments\CommentPostContextResolver;
use App\Services\SocialApi\SocialApiPostsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class CommentPostContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_comment_prompt_includes_parent_post_identity_and_hard_rules(): void
    {
        ['business' => $business] = $this->makeShop();

        AgentBehaviorRule::query()->create([
            'business_id' => $business->id,
            'polarity' => AgentBehaviorRule::POLARITY_MUST_NOT,
            'body' => 'Never invent discounts on comments',
            'sort_order' => 1,
        ]);

        $prompt = app(BusinessAgentPrompt::class)->system(
            $business,
            $business->agent,
            $business->agentSettings,
            null,
            [],
            true,
            null,
            null,
            'comment',
            null,
            "## PARENT POST (the post this customer commented on — answer in this context)\nplatform_post_id: ig_1\ncaption:\nWeekly Diamond Pass 320 DA",
        );

        $this->assertStringContainsString('PARENT POST', $prompt);
        $this->assertStringContainsString('Weekly Diamond Pass 320 DA', $prompt);
        $this->assertStringContainsString('HARD BUSINESS RULES', $prompt);
        $this->assertStringContainsString('Never invent discounts on comments', $prompt);
        $this->assertStringContainsString('Skill: comment-reply', $prompt);
        $this->assertStringContainsString('invite DM', $prompt);
    }

    public function test_resolver_builds_block_from_social_api_post(): void
    {
        ['business' => $business] = $this->makeShop();
        $account = \App\Models\SocialAccount::query()->create([
            'business_id' => $business->id,
            'provider' => 'socialapi',
            'socialapi_account_id' => 'acc_1',
            'platform' => 'facebook',
            'name' => 'Shop FB',
            'status' => 'connected',
            'connected_at' => now(),
        ]);

        $posts = Mockery::mock(SocialApiPostsService::class);
        $posts->shouldReceive('getPost')->once()->with('post_abc')->andReturn([
            'id' => 'post_abc',
            'caption' => 'MLBB Weekly Pass دروك',
            'platform' => 'facebook',
            'permalink' => 'https://facebook.com/x',
            'platform_post_id' => 'post_abc',
        ]);
        $posts->shouldReceive('normalizePost')->andReturnUsing(function (array $row) {
            return [
                'caption' => (string) ($row['caption'] ?? ''),
                'platform' => (string) ($row['platform'] ?? ''),
                'permalink' => (string) ($row['permalink'] ?? ''),
                'platform_post_id' => (string) ($row['platform_post_id'] ?? $row['id'] ?? ''),
            ];
        });
        $this->app->instance(SocialApiPostsService::class, $posts);

        $resolved = app(CommentPostContextResolver::class)->forComment($business, $account, 'post_abc');
        $this->assertNotNull($resolved);
        $this->assertStringContainsString('MLBB Weekly Pass', $resolved['block']);
        $this->assertStringContainsString('post_abc', $resolved['block']);
    }
}
