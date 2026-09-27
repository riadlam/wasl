<?php

namespace Tests\Feature;

use App\Models\AgentAsset;
use App\Models\AgentChatMessage;
use App\Models\WalletLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AsyncImageJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_queued_reply_hides_prompt_and_webhook_charges_once(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $owner->update(['wallet_balance_da' => 200]);
        $business->agentSettings->update(['image_model' => 'flux']);

        Storage::fake('public');
        config(['services.fal.key' => 'fal_test_key', 'app.url' => 'http://localhost']);

        $english = 'A vibrant weekly pass poster with bold Algerian market colors and a clean square layout for Facebook';

        Http::fake([
            'https://fal.run/openrouter/router/openai/v1/chat/completions' => Http::sequence()
                ->push([
                    'choices' => [[
                        'message' => [
                            'role' => 'assistant',
                            'tool_calls' => [[
                                'id' => 'call_gen',
                                'type' => 'function',
                                'function' => [
                                    'name' => 'generate_image',
                                    'arguments' => json_encode([
                                        'prompt' => $english,
                                        'image_size' => 'square_hd',
                                    ]),
                                ],
                            ]],
                        ],
                    ]],
                ])
                ->push([
                    'choices' => [[
                        'message' => [
                            'role' => 'assistant',
                            'content' => "تمام، ها هو الوصف اللي راح نستعملو:\n".$english."\nشوف معايا الصورة كي توجد.",
                        ],
                    ]],
                ]),
            'https://queue.fal.run/fal-ai/flux/schnell*' => Http::response([
                'request_id' => 'req_async_1',
                'status_url' => 'https://queue.fal.run/fal-ai/flux/schnell/requests/req_async_1/status',
                'response_url' => 'https://queue.fal.run/fal-ai/flux/schnell/requests/req_async_1',
            ]),
            'https://cdn.example.com/async.jpg' => Http::response('jpeg-bytes', 200, [
                'Content-Type' => 'image/jpeg',
            ]),
        ]);

        $chat = $this->asShopUser($owner, $business)
            ->postJson('/api/agent/chat', ['text' => 'اعمل صورة تمريرة أسبوعية بالدارجة'])
            ->assertOk();

        $content = (string) $chat->json('message.content');
        $this->assertStringNotContainsString($english, $content);
        $this->assertStringNotContainsString('A vibrant', $content);
        $this->assertSame([], $chat->json('message.assets'));
        $this->assertSame('queued', $chat->json('message.image_jobs.0.status'));
        $this->assertArrayNotHasKey('prompt', $chat->json('message.image_jobs.0') ?? []);
        $encoded = json_encode($chat->json('message'));
        $this->assertStringNotContainsString($english, (string) $encoded);
        $this->assertSame(175.0, (float) $owner->fresh()->wallet_balance_da);
        $this->assertSame(0, AgentAsset::query()->count());

        $payload = [
            'request_id' => 'req_async_1',
            'status' => 'OK',
            'payload' => [
                'images' => [[
                    'url' => 'https://cdn.example.com/async.jpg',
                    'content_type' => 'image/jpeg',
                ]],
            ],
        ];

        $this->postJson('/api/webhooks/fal/image', $payload)->assertOk();

        $this->assertSame(1, AgentAsset::query()->count());
        $this->assertSame(174.0, (float) $owner->fresh()->wallet_balance_da);
        $this->assertSame(1, WalletLedger::query()->where('reason', 'agent_image_gen')->count());

        $this->postJson('/api/webhooks/fal/image', $payload)->assertOk();
        $this->assertSame(174.0, (float) $owner->fresh()->wallet_balance_da);
        $this->assertSame(1, WalletLedger::query()->where('reason', 'agent_image_gen')->count());

        $loaded = $this->asShopUser($owner, $business)
            ->getJson('/api/agent/chat')
            ->assertOk();

        $assistant = collect($loaded->json('messages'))->firstWhere('role', 'assistant');
        $this->assertNotEmpty($assistant['assets'][0]['url'] ?? null);
        $this->assertSame('completed', $assistant['image_jobs'][0]['status'] ?? null);
        $this->assertStringNotContainsString($english, json_encode($assistant));

        $row = AgentChatMessage::query()->where('role', 'assistant')->first();
        $this->assertNotEmpty($row->meta['asset_ids'] ?? null);
    }

    public function test_arabizi_regen_forces_queued_job_instead_of_fake_ready(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $owner->update(['wallet_balance_da' => 200]);
        $business->agentSettings->update(['image_model' => 'flux']);

        Storage::fake('public');
        config(['services.fal.key' => 'fal_test_key', 'app.url' => 'http://localhost']);

        AgentChatMessage::query()->create([
            'business_id' => $business->id,
            'user_id' => $owner->id,
            'role' => 'user',
            'content' => 'akhdmli photo 3la 3 weekly pass bl darja',
            'meta' => [],
        ]);
        AgentChatMessage::query()->create([
            'business_id' => $business->id,
            'user_id' => null,
            'role' => 'assistant',
            'content' => "تمام\nها هو الوصف اللي راح نستعملو:\nA vibrant weekly pass poster with bold colors for Facebook.\nشوف معايا.",
            'meta' => [],
        ]);

        Http::fake([
            'https://fal.run/openrouter/router/openai/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => "هاهي الصورة مجددة مرة أخرى.\nحاب تستعملها في بوست؟",
                    ],
                ]],
            ]),
            'https://queue.fal.run/fal-ai/flux/schnell*' => Http::response([
                'request_id' => 'req_force_3awd',
                'status_url' => 'https://queue.fal.run/fal-ai/flux/schnell/requests/req_force_3awd/status',
                'response_url' => 'https://queue.fal.run/fal-ai/flux/schnell/requests/req_force_3awd',
            ]),
        ]);

        $response = $this->asShopUser($owner, $business)
            ->postJson('/api/agent/chat', ['text' => '3awd akhdmha'])
            ->assertOk();

        $content = (string) $response->json('message.content');
        $this->assertStringNotContainsString('مجددة', $content);
        $this->assertStringContainsString('راني نجهز', $content);
        $this->assertSame('queued', $response->json('message.image_jobs.0.status'));
        $this->assertSame([], $response->json('message.assets'));
        $this->assertDatabaseHas('agent_image_jobs', [
            'fal_request_id' => 'req_force_3awd',
            'status' => 'queued',
        ]);
    }
}
