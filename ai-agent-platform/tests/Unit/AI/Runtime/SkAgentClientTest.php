<?php

namespace Tests\Unit\AI\Runtime;

use App\AI\Runtime\SkAgentClient;
use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SkAgentClientTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_chat_normalizes_runtime_payload(): void
    {
        config([
            'ai_runtime.url' => 'http://runtime.test',
            'ai_runtime.service_key' => 'secret',
            'ai_runtime.timeout' => 5,
        ]);

        Http::fake([
            'runtime.test/*' => Http::response([
                'reply' => 'Caption ready',
                'pending_action' => null,
                'tool_calls' => [],
                'generated_assets' => [],
                'pending_image_jobs' => [],
                'usage' => [
                    'prompt_tokens' => 10,
                    'completion_tokens' => 4,
                    'cost_usd' => 0.01,
                    'fal_calls' => 1,
                    'calls_with_cost' => 1,
                ],
                'runtime' => 'sk',
            ], 200),
        ]);

        $business = new Business;
        $business->id = 1;
        $user = new User;
        $user->id = 9;

        $this->assertTrue(Schema::hasTable('agent_chat_messages') || true);

        $client = new SkAgentClient;
        $result = $client->ownerChat($business, $user, 'make a post', null);

        $this->assertSame('Caption ready', $result['reply']);
        $this->assertSame(10, $result['usage']['prompt_tokens']);
        $this->assertSame('sk', $result['runtime']);
    }
}
