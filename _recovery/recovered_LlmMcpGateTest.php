<?php

namespace Tests\Feature;

use App\AI\Mcp\SocialApiMcpClassifier;
use App\AI\Tools\Owner\ConfirmPendingAction;
use App\AI\Tools\Owner\SocialApiMcpTool;
use App\Models\AgentPendingAction;
use App\Services\SocialApi\SocialApiMcpClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LlmMcpGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_llm_catalog_patch_and_chat_charge_uses_selected_price(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $owner->update(['wallet_tokens' => 100]);

        $this->asShopUser($owner, $business)
            ->getJson('/api/agent/llm-models')
            ->assertOk()
            ->assertJsonPath('default', 'claude_sonnet')
            ->assertJsonMissingPath('models.0.model');

        $this->asShopUser($owner, $business)
            ->patchJson('/api/agent/llm-model', ['llm_model' => 'gemini'])
            ->assertOk()
            ->assertJsonPath('llm_model', 'gemini');

        Http::fake([
            'https://fal.run/*' => Http::response([
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => 'Salam'],
                ]],
            ]),
        ]);

        $this->asShopUser($owner, $business)
            ->postJson('/api/agent/chat', ['text' => 'salam'])
            ->assertOk();

        $this->assertSame(90.0, (float) $owner->fresh()->wallet_tokens);
    }

    public function test_mcp_mutate_requires_confirm_unless_auto_flag(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        config([
            'services.socialapi.key' => 'sapi_key_test',
            'services.socialapi.mcp_enabled' => true,
            'services.socialapi.mcp_url' => 'https://api.social-api.ai/mcp',
        ]);

        Http::fake([
            'https://api.social-api.ai/mcp' => Http::response([
                'jsonrpc' => '2.0',
                'id' => 1,
                'result' => ['ok' => true, 'post_id' => 'p1'],
            ]),
        ]);

        $tool = new SocialApiMcpTool(
            'create_post',
            'Create a post',
            ['type' => 'object', 'properties' => ['text' => ['type' => 'string']]],
            app(SocialApiMcpClient::class),
            app(SocialApiMcpClassifier::class),
        );

        $pending = $tool->handle($business, ['text' => 'Hello'], ['user_id' => $owner->id]);
        $this->assertTrue($pending['pending'] ?? false);
        $this->assertDatabaseHas('agent_pending_actions', [
            'id' => $pending['action_id'],
            'type' => AgentPendingAction::TYPE_MCP,
            'status' => AgentPendingAction::STATUS_PENDING,
        ]);
        Http::assertNothingSent();

        $confirmed = app(ConfirmPendingAction::class)->handle($business, [
            'action_id' => $pending['action_id'],
            'confirmed' => true,
        ], ['user_id' => $owner->id]);
        $this->assertTrue($confirmed['ok'] ?? false);
        Http::assertSent(fn ($r) => $r->url() === 'https://api.social-api.ai/mcp');

        $business->agentSettings->update(['ai_auto_publish_posts' => true]);
        Http::fake([
            'https://api.social-api.ai/mcp' => Http::response([
                'jsonrpc' => '2.0',
                'id' => 1,
                'result' => ['ok' => true],
            ]),
        ]);
        $direct = $tool->handle($business->fresh(), ['text' => 'Now'], ['user_id' => $owner->id]);
        $this->assertTrue($direct['executed'] ?? false);
        $this->assertArrayNotHasKey('pending', $direct);
    }
}
