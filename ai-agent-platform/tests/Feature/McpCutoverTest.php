<?php

namespace Tests\Feature;

use App\AI\Mcp\SocialApiMcpClassifier;
use App\AI\Tools\Owner\GetBusinessContext;
use App\AI\Tools\Owner\SocialApiMcpTool;
use App\Http\Controllers\Api\AgentChatController;
use App\Models\AgentPendingAction;
use App\Models\AiProfilePerChannel;
use App\Models\SocialAccount;
use App\Services\SocialApi\SocialApiMcpClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\TestCase;

class McpCutoverTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_tools_drop_drafts_and_include_business_context_plus_mcp(): void
    {
        ['business' => $business] = $this->makeShop();
        config([
            'services.socialapi.key' => 'sapi_key_test',
            'services.socialapi.mcp_enabled' => true,
            'services.socialapi.mcp_url' => 'https://api.social-api.ai/mcp',
        ]);
        Cache::forget('socialapi.mcp.tools');

        Http::fake([
            'https://api.social-api.ai/mcp' => Http::response([
                'jsonrpc' => '2.0',
                'id' => 1,
                'result' => [
                    'tools' => [
                        [
                            'name' => 'create_post',
                            'description' => 'Create a post',
                            'inputSchema' => [
                                'type' => 'object',
                                'properties' => ['text' => ['type' => 'string']],
                            ],
                        ],
                        [
                            'name' => 'list_posts',
                            'description' => 'List posts',
                            'inputSchema' => ['type' => 'object', 'properties' => []],
                        ],
                    ],
                ],
            ]),
        ]);

        $controller = app(AgentChatController::class);
        $method = new ReflectionMethod($controller, 'ownerTools');
        $method->setAccessible(true);
        $registry = $method->invoke($controller, $business);
        $names = array_map(fn ($t) => $t->name(), $registry->all());

        $this->assertContains('get_business_context', $names);
        $this->assertContains('get_agent_auto_settings', $names);
        $this->assertContains('update_agent_auto_settings', $names);
        $this->assertContains('list_channels', $names);
        $this->assertContains('list_scheduled_posts', $names);
        $this->assertContains('sapi_create_post', $names);
        $this->assertContains('sapi_list_posts', $names);
        $this->assertNotContains('draft_create_post', $names);
        $this->assertNotContains('draft_schedule_post', $names);
        $this->assertContains('search_products', $names);
        $this->assertContains('list_delivery_zones', $names);
        $this->assertContains('list_orders', $names);
        $this->assertContains('create_ai_campaign', $names);
        $this->assertNotContains('create_order', $names);
        $this->assertNotContains('handoff_to_human', $names);
    }

    public function test_get_business_context_identity_only_skips_mcp_http(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $account = $this->connectAccount($business, 'acc_ig_1', 'instagram', 'Shop IG');

        AiProfilePerChannel::query()->create([
            'business_id' => $business->id,
            'social_account_id' => $account->id,
            'platform' => 'instagram',
            'profile' => [
                'storage' => 'supabase',
                'summary' => 'friendly Darija shop',
                'namespaces' => ['brand', 'tone'],
                'chunks_upserted' => 3,
            ],
            'status' => AiProfilePerChannel::STATUS_READY,
            'generated_at' => now(),
        ]);

        config([
            'services.socialapi.key' => 'sapi_key_test',
            'services.socialapi.mcp_enabled' => true,
            'services.socialapi.mcp_url' => 'https://api.social-api.ai/mcp',
            'ai_runtime.url' => 'http://runtime.test',
            'ai_runtime.service_key' => 'secret',
        ]);

        Http::fake([
            'runtime.test/v1/knowledge/search' => Http::response([
                'ok' => true,
                'hits' => [
                    [
                        'content' => 'tone: friendly Darija',
                        'namespace' => 'tone',
                        'score' => 0.91,
                        'metadata' => ['social_account_id' => $account->id],
                    ],
                ],
            ], 200),
        ]);

        $result = app(GetBusinessContext::class)->handle($business, [
            'include' => ['identity'],
            'account_id' => $account->id,
        ], ['user_id' => $owner->id]);

        $this->assertTrue($result['ok'] ?? false);
        $this->assertSame('supabase', $result['identity'][0]['storage'] ?? null);
        $this->assertSame('friendly Darija shop', $result['identity'][0]['summary'] ?? null);
        $this->assertArrayHasKey('profile', $result['identity'][0]);
        $this->assertNull($result['identity'][0]['profile']);
        $this->assertSame('acc_ig_1', $result['channels'][0]['socialapi_account_id'] ?? null);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/knowledge/search'));
    }

    public function test_get_business_context_posts_calls_mcp_read(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $account = $this->connectAccount($business, 'acc_ig_1', 'instagram', 'Shop IG');

        config([
            'services.socialapi.key' => 'sapi_key_test',
            'services.socialapi.mcp_enabled' => true,
            'services.socialapi.mcp_url' => 'https://api.social-api.ai/mcp',
        ]);
        Cache::forget('socialapi.mcp.tools');

        Http::fake(function ($request) {
            $payload = $request->data();
            $method = $payload['method'] ?? '';
            if ($method === 'tools/list') {
                return Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => [
                        'tools' => [
                            ['name' => 'list_posts', 'description' => 'List', 'inputSchema' => ['type' => 'object']],
                        ],
                    ],
                ]);
            }
            if ($method === 'tools/call') {
                $this->assertSame('list_posts', $payload['params']['name'] ?? null);
                $this->assertSame('acc_ig_1', $payload['params']['arguments']['account_id'] ?? null);

                return Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => ['posts' => [['id' => 'p1', 'text' => 'Hello']]],
                ]);
            }

            return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'error' => ['message' => 'unexpected']], 500);
        });

        $result = app(GetBusinessContext::class)->handle($business, [
            'include' => ['posts'],
            'account_id' => $account->id,
            'limit' => 3,
        ], ['user_id' => $owner->id]);

        $this->assertTrue($result['ok'] ?? false);
        $this->assertSame('list_posts', $result['posts']['tool'] ?? null);
        $this->assertSame('p1', $result['posts']['items'][0]['data']['posts'][0]['id'] ?? null);
    }

    public function test_mcp_create_and_schedule_post_gate_to_publish_flag(): void
    {
        $classifier = app(SocialApiMcpClassifier::class);

        foreach (['create_post', 'schedule_post', 'create_scheduled_post'] as $name) {
            $class = $classifier->classify($name);
            $this->assertTrue($class['mutate'], $name);
            $this->assertSame('posts', $class['category'], $name);
            $this->assertSame('ai_auto_publish_posts', $class['setting'], $name);
        }

        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $tool = new SocialApiMcpTool(
            'schedule_post',
            'Schedule a post',
            ['type' => 'object', 'properties' => ['text' => ['type' => 'string']]],
            app(SocialApiMcpClient::class),
            $classifier,
        );

        $pending = $tool->handle($business, ['text' => 'Later'], ['user_id' => $owner->id]);
        $this->assertTrue($pending['pending'] ?? false);
        $this->assertStringContainsString('schedule_post', (string) AgentPendingAction::query()->find($pending['action_id'])->summary);
        $this->assertStringContainsString('Later', (string) AgentPendingAction::query()->find($pending['action_id'])->summary);
    }

    public function test_agent_auto_settings_tools_read_and_update(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();

        $got = app(\App\AI\Tools\Owner\GetAgentAutoSettings::class)->handle($business, [], ['user_id' => $owner->id]);
        $this->assertTrue($got['ok'] ?? false);
        $this->assertFalse($got['flags']['ai_auto_publish_posts']);

        $updated = app(\App\AI\Tools\Owner\UpdateAgentAutoSettings::class)->handle($business, [
            'ai_auto_publish_posts' => true,
            'ai_auto_send_dms' => true,
        ], ['user_id' => $owner->id]);

        $this->assertTrue($updated['ok'] ?? false);
        $this->assertTrue($updated['flags']['ai_auto_publish_posts']);
        $this->assertTrue($updated['flags']['ai_auto_send_dms']);
        $this->assertFalse($updated['flags']['ai_auto_reply_comments']);
        $this->assertTrue((bool) $business->fresh()->agentSettings->ai_auto_publish_posts);
    }

    private function connectAccount($business, string $remoteId, string $platform, string $name): SocialAccount
    {
        return SocialAccount::query()->create([
            'business_id' => $business->id,
            'provider' => 'socialapi',
            'socialapi_account_id' => $remoteId,
            'platform' => $platform,
            'name' => $name,
            'status' => 'connected',
            'connected_at' => now(),
        ]);
    }
}
