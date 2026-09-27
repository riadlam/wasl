<?php

namespace Tests\Feature;

use App\AI\Tools\Owner\ConfirmPendingAction;
use App\AI\Tools\Owner\DraftCreatePost;
use App\AI\Tools\Owner\DraftSchedulePost;
use App\AI\Tools\Owner\GenerateImage;
use App\AI\Tools\Owner\ListScheduledPosts;
use App\Models\AgentAsset;
use App\Models\AgentChatMessage;
use App\Models\AgentPendingAction;
use App\Models\SocialAccount;
use App\Models\WalletLedger;
use App\Support\CurrentBusiness;
use App\Support\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AgentChatTest extends TestCase
{
    use RefreshDatabase;

    public function test_chat_requires_auth(): void
    {
        $this->getJson('/api/agent/chat')->assertUnauthorized();
        $this->postJson('/api/agent/chat', ['text' => 'hi'])->assertUnauthorized();
    }

    public function test_chat_send_requires_agents_manage(): void
    {
        ['staff' => $staff, 'business' => $business, 'owner' => $owner] = $this->makeShop();
        $owner->update(['wallet_balance_da' => 200]);

        $this->asShopUser($staff, $business)
            ->postJson('/api/agent/chat', ['text' => 'Salam'])
            ->assertForbidden();

        $this->assertDatabaseCount('agent_chat_messages', 0);
    }

    public function test_insufficient_wallet_returns_402_and_stores_nothing(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $owner->update(['wallet_balance_da' => 0]);

        Http::fake();

        $this->asShopUser($owner, $business)
            ->postJson('/api/agent/chat', ['text' => 'Draft a post'])
            ->assertStatus(402)
            ->assertJsonPath('code', 'wallet.insufficient');

        $this->assertDatabaseCount('agent_chat_messages', 0);
        $this->assertDatabaseCount('wallet_ledger', 0);
        Http::assertNothingSent();
    }

    public function test_generate_image_creates_asset_and_charges_wallet(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $owner->update(['wallet_balance_da' => 50]);
        $business->agentSettings->update(['image_model' => 'flux']);

        Storage::fake('public');
        config(['services.fal.key' => 'fal_test_key']);

        Http::fake([
            'https://queue.fal.run/fal-ai/flux/schnell*' => Http::response([
                'request_id' => 'req_flux_asset',
                'status_url' => 'https://queue.fal.run/fal-ai/flux/schnell/requests/req_flux_asset/status',
                'response_url' => 'https://queue.fal.run/fal-ai/flux/schnell/requests/req_flux_asset',
            ]),
            'https://cdn.example.com/gen.jpg' => Http::response('fake-jpeg-bytes', 200, [
                'Content-Type' => 'image/jpeg',
            ]),
        ]);

        $result = app(GenerateImage::class)->handle($business, [
            'prompt' => 'A clean product photo of wireless earbuds on a desk',
            'image_size' => 'square_hd',
        ], ['user_id' => $owner->id]);

        $this->assertTrue($result['pending'] ?? false);
        $this->assertSame(50.0, (float) $owner->fresh()->wallet_balance_da);

        $this->postJson('/api/webhooks/fal/image', [
            'request_id' => 'req_flux_asset',
            'status' => 'OK',
            'payload' => [
                'images' => [[
                    'url' => 'https://cdn.example.com/gen.jpg',
                    'content_type' => 'image/jpeg',
                ]],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('agent_assets', [
            'business_id' => $business->id,
            'mime' => 'image/jpeg',
        ]);
        $this->assertSame(49.0, (float) $owner->fresh()->wallet_balance_da);
        $this->assertDatabaseHas('wallet_ledger', [
            'user_id' => $owner->id,
            'reason' => 'agent_image_gen',
            'amount_da' => 1,
        ]);
    }

    public function test_generate_image_insufficient_wallet_does_not_create_asset(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $owner->update(['wallet_balance_da' => 0]);

        Storage::fake('public');
        config(['services.fal.key' => 'fal_test_key']);
        Http::fake();

        $result = app(GenerateImage::class)->handle($business, [
            'prompt' => 'A clean product photo',
        ], ['user_id' => $owner->id]);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('wallet.insufficient', $result['code'] ?? null);
        $this->assertSame(0, AgentAsset::query()->where('business_id', $business->id)->count());
        Http::assertNothingSent();
    }

    public function test_chat_turn_with_generate_image_stores_assets_on_assistant_message(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $owner->update(['wallet_balance_da' => 200]);
        $business->agentSettings->update(['image_model' => 'flux']);

        Storage::fake('public');
        config(['services.fal.key' => 'fal_test_key']);

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
                                        'prompt' => 'Wireless earbuds on marble desk, soft daylight',
                                        'image_size' => 'square_hd',
                                    ]),
                                ],
                            ]],
                        ],
                    ]],
                    'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
                ])
                ->push([
                    'choices' => [[
                        'message' => [
                            'role' => 'assistant',
                            'content' => 'Here is a clean product shot. Want me to use it in a post?',
                        ],
                    ]],
                    'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 8],
                ]),
            'https://queue.fal.run/fal-ai/flux/schnell*' => Http::response([
                'request_id' => 'req_chat_flux',
                'status_url' => 'https://queue.fal.run/fal-ai/flux/schnell/requests/req_chat_flux/status',
                'response_url' => 'https://queue.fal.run/fal-ai/flux/schnell/requests/req_chat_flux',
            ]),
            'https://cdn.example.com/chat-gen.jpg' => Http::response('jpeg-bytes', 200, [
                'Content-Type' => 'image/jpeg',
            ]),
        ]);

        $response = $this->asShopUser($owner, $business)
            ->postJson('/api/agent/chat', [
                'text' => 'Generate a promo image for earbuds, square feed, clean desk vibe',
            ])
            ->assertOk();

        $assets = $response->json('message.assets');
        $this->assertSame([], $assets);
        $this->assertSame('queued', $response->json('message.image_jobs.0.status'));
        $this->assertSame(175.0, (float) $owner->fresh()->wallet_balance_da);

        $this->postJson('/api/webhooks/fal/image', [
            'request_id' => 'req_chat_flux',
            'status' => 'OK',
            'payload' => [
                'images' => [[
                    'url' => 'https://cdn.example.com/chat-gen.jpg',
                    'content_type' => 'image/jpeg',
                ]],
            ],
        ])->assertOk();

        $assistant = AgentChatMessage::query()
            ->where('business_id', $business->id)
            ->where('role', 'assistant')
            ->latest('id')
            ->first();
        $this->assertNotEmpty($assistant->meta['asset_ids'] ?? null);

        // chat (25 DA) + generate_image flux (1 DA)
        $this->assertSame(174.0, (float) $owner->fresh()->wallet_balance_da);
    }

    public function test_chat_generate_image_scrubs_asset_id_leak_and_returns_assets(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $owner->update(['wallet_balance_da' => 200]);
        $business->agentSettings->update(['image_model' => 'flux']);

        Storage::fake('public');
        config(['services.fal.key' => 'fal_test_key']);

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
                                        'prompt' => 'Clean product photo',
                                        'image_size' => 'square_hd',
                                    ]),
                                ],
                            ]],
                        ],
                    ]],
                    'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
                ])
                ->push([
                    'choices' => [[
                        'message' => [
                            'role' => 'assistant',
                            'content' => "هاهي الصورة واجدة.\n[Previously attached agent asset ids: 8]\nحاب تستعملها في بوست؟",
                        ],
                    ]],
                    'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 8],
                ]),
            'https://queue.fal.run/fal-ai/flux/schnell*' => Http::response([
                'request_id' => 'req_scrub',
                'status_url' => 'https://queue.fal.run/fal-ai/flux/schnell/requests/req_scrub/status',
                'response_url' => 'https://queue.fal.run/fal-ai/flux/schnell/requests/req_scrub',
            ]),
        ]);

        $response = $this->asShopUser($owner, $business)
            ->postJson('/api/agent/chat', ['text' => 'عاود لي هاد الصورة'])
            ->assertOk();

        $content = (string) $response->json('message.content');
        $this->assertStringNotContainsString('Previously attached', $content);
        $this->assertStringNotContainsString('agent asset ids', $content);
        $this->assertStringContainsString('راني نجهز الصورة', $content);
        $this->assertSame([], $response->json('message.assets') ?? []);
        $this->assertSame('queued', $response->json('message.image_jobs.0.status'));
    }

    public function test_chat_generate_image_failure_does_not_claim_ready(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $owner->update(['wallet_balance_da' => 200]);
        $business->agentSettings->update(['image_model' => 'gpt_image_2']);

        Storage::fake('public');
        config(['services.fal.key' => 'fal_test_key']);

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
                                        'prompt' => 'Clean product photo',
                                    ]),
                                ],
                            ]],
                        ],
                    ]],
                    'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
                ])
                ->push([
                    'choices' => [[
                        'message' => [
                            'role' => 'assistant',
                            'content' => "هاهي الصورة مجددة مرة أخرى.\n[Previously attached agent asset ids: 9]",
                        ],
                    ]],
                    'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 8],
                ]),
            'https://queue.fal.run/fal-ai/gpt-image-2*' => Http::response(['detail' => 'unauthorized'], 401),
        ]);

        $response = $this->asShopUser($owner, $business)
            ->postJson('/api/agent/chat', ['text' => 'عاود لي هاد الصورة'])
            ->assertOk();

        $content = (string) $response->json('message.content');
        $this->assertStringNotContainsString('Previously attached', $content);
        $this->assertStringNotContainsString('هاهي الصورة مجددة', $content);
        $this->assertStringContainsString('ما قدرتش نولّد الصورة', $content);
        $this->assertSame([], $response->json('message.assets') ?? []);
    }

    public function test_chat_gpt_queue_success_returns_assets_on_assistant_message(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $owner->update(['wallet_balance_da' => 200]);
        $business->agentSettings->update(['image_model' => 'gpt_image_2']);

        Storage::fake('public');
        config(['services.fal.key' => 'fal_test_key']);

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
                                        'prompt' => 'Wireless earbuds on marble',
                                        'image_size' => 'square_hd',
                                    ]),
                                ],
                            ]],
                        ],
                    ]],
                    'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
                ])
                ->push([
                    'choices' => [[
                        'message' => [
                            'role' => 'assistant',
                            'content' => "هاهي الصورة واجدة.\n[Previously attached agent asset ids: 8]",
                        ],
                    ]],
                    'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 8],
                ]),
            'https://queue.fal.run/fal-ai/gpt-image-2*' => Http::response([
                'request_id' => 'req_chat_gpt',
                'status_url' => 'https://queue.fal.run/fal-ai/gpt-image-2/requests/req_chat_gpt/status',
                'response_url' => 'https://queue.fal.run/fal-ai/gpt-image-2/requests/req_chat_gpt',
            ]),
            'https://cdn.example.com/chat-gpt.jpg' => Http::response('jpeg-bytes', 200),
        ]);

        $response = $this->asShopUser($owner, $business)
            ->postJson('/api/agent/chat', ['text' => 'Generate earbuds promo image'])
            ->assertOk();

        $content = (string) $response->json('message.content');
        $this->assertStringNotContainsString('Previously attached', $content);
        $this->assertSame([], $response->json('message.assets') ?? []);
        $this->assertSame('queued', $response->json('message.image_jobs.0.status'));
        $this->assertSame(175.0, (float) $owner->fresh()->wallet_balance_da);

        $this->postJson('/api/webhooks/fal/image', [
            'request_id' => 'req_chat_gpt',
            'status' => 'OK',
            'payload' => [
                'images' => [[
                    'url' => 'https://cdn.example.com/chat-gpt.jpg',
                    'content_type' => 'image/jpeg',
                ]],
            ],
        ])->assertOk();

        $this->assertSame(161.0, (float) $owner->fresh()->wallet_balance_da);
        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://queue.fal.run/fal-ai/gpt-image-2'));
        Http::assertNotSent(fn ($r) => str_starts_with($r->url(), 'https://fal.run/fal-ai/gpt-image-2'));
    }

    public function test_draft_create_post_does_not_hit_socialapi(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $account = $this->connectAccount($business, 'acc_ig_1', 'instagram', 'Shop IG');

        Http::fake();
        CurrentBusiness::set($business->id);

        $result = app(DraftCreatePost::class)->handle($business, [
            'account_ids' => [$account->id],
            'text' => 'Weekend drop — 20% off',
            'publish_now' => true,
        ], ['user_id' => $owner->id]);

        $this->assertTrue($result['ok'] ?? false);
        $this->assertDatabaseHas('agent_pending_actions', [
            'business_id' => $business->id,
            'type' => AgentPendingAction::TYPE_CREATE_POST,
            'status' => AgentPendingAction::STATUS_PENDING,
        ]);
        Http::assertNothingSent();
    }

    public function test_draft_schedule_post_requires_future_time_and_drafts_pending(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $account = $this->connectAccount($business, 'acc_ig_1', 'instagram', 'Shop IG');

        Http::fake();
        CurrentBusiness::set($business->id);

        $past = app(DraftSchedulePost::class)->handle($business, [
            'account_ids' => [$account->id],
            'text' => 'Too late',
            'scheduled_at' => now()->subHour()->toIso8601String(),
        ], ['user_id' => $owner->id]);

        $this->assertArrayHasKey('error', $past);

        $when = now()->addDays(3)->utc()->toIso8601String();
        $result = app(DraftSchedulePost::class)->handle($business, [
            'account_ids' => [$account->id],
            'text' => 'Weekend drop',
            'scheduled_at' => $when,
        ], ['user_id' => $owner->id]);

        $this->assertTrue($result['ok'] ?? false);
        $this->assertSame('schedule', $result['mode'] ?? null);
        $this->assertDatabaseHas('agent_pending_actions', [
            'business_id' => $business->id,
            'type' => AgentPendingAction::TYPE_CREATE_POST,
            'status' => AgentPendingAction::STATUS_PENDING,
        ]);

        $action = AgentPendingAction::query()->where('business_id', $business->id)->latest('id')->first();
        $this->assertFalse((bool) ($action->payload['publish_now'] ?? true));
        $this->assertNotEmpty($action->payload['scheduled_at'] ?? null);
        Http::assertNothingSent();
    }

    public function test_list_scheduled_posts_tool_returns_shop_queue(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $this->connectAccount($business, 'acc_ig_1', 'instagram', 'Shop IG');

        Http::fake([
            'https://api.social-api.ai/v1/posts*' => Http::response([
                'data' => [[
                    'id' => 'p_sched_1',
                    'text' => 'Friday sale',
                    'status' => 'scheduled',
                    'scheduled_at' => '2026-10-03T09:00:00Z',
                    'targets' => [
                        ['account_id' => 'acc_ig_1', 'platform' => 'instagram', 'status' => 'scheduled'],
                    ],
                    'media' => [],
                ]],
                'pagination' => ['has_more' => false, 'next_cursor' => null],
            ]),
        ]);

        $result = app(ListScheduledPosts::class)->handle($business, [
            'limit' => 5,
        ], ['user_id' => $owner->id]);

        $this->assertTrue($result['ok'] ?? false);
        $this->assertSame(1, $result['count'] ?? 0);
        $this->assertSame('p_sched_1', $result['posts'][0]['id'] ?? null);
        $this->assertSame('Friday sale', $result['posts'][0]['text'] ?? null);
    }

    public function test_confirm_pending_action_creates_scheduled_post(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $account = $this->connectAccount($business, 'acc_ig_1', 'instagram', 'Shop IG');

        CurrentBusiness::set($business->id);

        $draft = app(DraftCreatePost::class)->handle($business, [
            'account_ids' => [$account->id],
            'text' => 'Launching soon',
            'publish_now' => false,
            'scheduled_at' => now()->addDays(2)->toIso8601String(),
        ], ['user_id' => $owner->id]);

        $actionId = (int) $draft['action_id'];

        Http::fake([
            'https://api.social-api.ai/v1/posts' => Http::response([
                'id' => 'p_agent_1',
                'text' => 'Launching soon',
                'status' => 'scheduled',
                'scheduled_at' => '2026-10-02T09:00:00Z',
                'targets' => [
                    ['account_id' => 'acc_ig_1', 'platform' => 'instagram', 'status' => 'scheduled'],
                ],
            ], 201),
        ]);

        $result = app(ConfirmPendingAction::class)->handle($business, [
            'action_id' => $actionId,
            'confirmed' => true,
        ], ['user_id' => $owner->id]);

        $this->assertTrue($result['ok'] ?? false);
        $this->assertSame('p_agent_1', $result['result']['post']['id'] ?? null);
        $this->assertDatabaseHas('agent_pending_actions', [
            'id' => $actionId,
            'status' => AgentPendingAction::STATUS_CONFIRMED,
        ]);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->method() === 'POST'
                && str_ends_with($request->url(), '/posts')
                && ($body['text'] ?? null) === 'Launching soon'
                && ($body['targets'][0]['account_id'] ?? null) === 'acc_ig_1';
        });
    }

    public function test_chat_turn_charges_owner_wallet_even_for_staff(): void
    {
        ['owner' => $owner, 'staff' => $staff, 'business' => $business] = $this->makeShop();
        $owner->update(['wallet_balance_da' => 200]);
        $staff->update(['wallet_balance_da' => 99]);

        $business->members()->where('user_id', $staff->id)->update([
            'permissions' => [
                Permission::AgentsView->value,
                Permission::AgentsManage->value,
            ],
        ]);

        Http::fake([
            'https://fal.run/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Oui, je peux t’aider à préparer un post.',
                    ],
                ]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 8],
            ]),
        ]);

        $this->asShopUser($staff, $business)
            ->postJson('/api/agent/chat', ['text' => 'Peux-tu m’aider?', 'voice_lang' => 'french'])
            ->assertOk()
            ->assertJsonPath('message.role', 'assistant');

        $this->assertSame(175.0, (float) $owner->fresh()->wallet_balance_da);
        $this->assertSame(99.0, (float) $staff->fresh()->wallet_balance_da);
        $this->assertDatabaseHas('wallet_ledger', [
            'user_id' => $owner->id,
            'actor_user_id' => $staff->id,
            'reason' => 'agent_chat',
            'amount_da' => 25,
            'direction' => WalletLedger::DIRECTION_DEBIT,
        ]);
        $this->assertDatabaseHas('ai_task_charges', [
            'business_id' => $business->id,
            'user_id' => $owner->id,
            'actor_user_id' => $staff->id,
            'task_type' => 'agent_chat',
            'model_key' => 'claude_sonnet',
            'cost_da' => 25,
            'status' => 'charged',
        ]);
        $this->assertSame(2, AgentChatMessage::query()->where('business_id', $business->id)->count());
    }

    public function test_chat_llm_mcp_post_then_confirm_endpoint(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $owner->update(['wallet_balance_da' => 200]);
        $account = $this->connectAccount($business, 'acc_ig_1', 'instagram', 'Shop IG');

        config([
            'services.socialapi.key' => 'sapi_key_test',
            'services.socialapi.mcp_enabled' => true,
            'services.socialapi.mcp_url' => 'https://api.social-api.ai/mcp',
        ]);
        \Illuminate\Support\Facades\Cache::forget('socialapi.mcp.tools');

        Http::fake(function ($request) use ($account) {
            if (str_contains($request->url(), 'fal.run')) {
                static $fal = 0;
                $fal++;
                if ($fal === 1) {
                    return Http::response([
                        'choices' => [[
                            'message' => [
                                'role' => 'assistant',
                                'tool_calls' => [[
                                    'id' => 'call_mcp',
                                    'type' => 'function',
                                    'function' => [
                                        'name' => 'sapi_create_post',
                                        'arguments' => json_encode([
                                            'account_id' => $account->socialapi_account_id,
                                            'text' => 'Flash sale today',
                                        ]),
                                    ],
                                ]],
                            ],
                        ]],
                        'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 4],
                    ]);
                }

                return Http::response([
                    'choices' => [[
                        'message' => [
                            'role' => 'assistant',
                            'content' => 'Ready — confirm and I will publish.',
                        ],
                    ]],
                    'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 10],
                ]);
            }

            $payload = $request->data();
            $method = $payload['method'] ?? '';
            if ($method === 'tools/list') {
                return Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => [
                        'tools' => [
                            [
                                'name' => 'create_post',
                                'description' => 'Create a post',
                                'inputSchema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'account_id' => ['type' => 'string'],
                                        'text' => ['type' => 'string'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ]);
            }
            if ($method === 'tools/call') {
                return Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => ['ok' => true, 'post_id' => 'p_live_1'],
                ]);
            }

            return Http::response(['error' => 'unexpected'], 500);
        });

        $chat = $this->asShopUser($owner, $business)
            ->postJson('/api/agent/chat', ['text' => 'انشر بوست على الانستغرام: Flash sale today'])
            ->assertOk();

        $pendingId = $chat->json('pending_action.id');
        $this->assertNotNull($pendingId);
        $this->assertDatabaseHas('agent_pending_actions', [
            'id' => $pendingId,
            'type' => AgentPendingAction::TYPE_MCP,
            'status' => AgentPendingAction::STATUS_PENDING,
        ]);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.social-api.ai/mcp')
            && ($request->data()['method'] ?? '') === 'tools/call');

        $this->asShopUser($owner, $business)
            ->postJson('/api/agent/chat/confirm', ['action_id' => $pendingId])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseHas('agent_pending_actions', [
            'id' => $pendingId,
            'status' => AgentPendingAction::STATUS_CONFIRMED,
        ]);
    }

    public function test_chat_index_returns_history_for_viewers(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();

        AgentChatMessage::query()->create([
            'business_id' => $business->id,
            'user_id' => $owner->id,
            'role' => 'user',
            'content' => 'Hello',
        ]);
        AgentChatMessage::query()->create([
            'business_id' => $business->id,
            'user_id' => null,
            'role' => 'assistant',
            'content' => 'Hi there',
        ]);

        $this->asShopUser($owner, $business)
            ->getJson('/api/agent/chat')
            ->assertOk()
            ->assertJsonPath('messages.0.content', 'Hello')
            ->assertJsonPath('messages.1.content', 'Hi there');
    }

    public function test_chat_with_asset_ids_stores_assets_on_user_message(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $owner->update(['wallet_balance_da' => 200]);

        Storage::fake('public');
        $path = 'agent-assets/'.$business->id.'/shot.jpg';
        Storage::disk('public')->put($path, 'fake-image-bytes');
        $asset = AgentAsset::query()->create([
            'business_id' => $business->id,
            'agent_id' => $business->agent?->id,
            'disk' => 'public',
            'path' => $path,
            'original_name' => 'shot.jpg',
            'mime' => 'image/jpeg',
            'size' => 16,
        ]);

        Http::fake([
            'https://fal.run/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Saw the image.',
                    ],
                ]],
                'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 3],
            ]),
        ]);

        $this->asShopUser($owner, $business)
            ->postJson('/api/agent/chat', [
                'text' => 'Post this',
                'asset_ids' => [$asset->id],
            ])
            ->assertOk()
            ->assertJsonPath('user_message.assets.0.id', $asset->id)
            ->assertJsonPath('user_message.content', 'Post this');

        $row = AgentChatMessage::query()
            ->where('business_id', $business->id)
            ->where('role', 'user')
            ->latest('id')
            ->first();
        $this->assertSame([$asset->id], $row->meta['asset_ids'] ?? null);
        $this->assertSame($asset->id, $row->meta['assets'][0]['id'] ?? null);
    }

    public function test_chat_with_only_asset_ids_allowed(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $owner->update(['wallet_balance_da' => 200]);

        Storage::fake('public');
        $path = 'agent-assets/'.$business->id.'/only.jpg';
        Storage::disk('public')->put($path, 'bytes');
        $asset = AgentAsset::query()->create([
            'business_id' => $business->id,
            'agent_id' => $business->agent?->id,
            'disk' => 'public',
            'path' => $path,
            'original_name' => 'only.jpg',
            'mime' => 'image/jpeg',
            'size' => 5,
        ]);

        Http::fake([
            'https://fal.run/*' => Http::response([
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => 'Got it.'],
                ]],
                'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
            ]),
        ]);

        $this->asShopUser($owner, $business)
            ->postJson('/api/agent/chat', ['asset_ids' => [$asset->id]])
            ->assertOk()
            ->assertJsonPath('user_message.assets.0.id', $asset->id);
    }

    public function test_draft_create_post_uploads_asset_ids_to_socialapi_media(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $account = $this->connectAccount($business, 'acc_ig_1', 'instagram', 'Shop IG');

        Storage::fake('public');
        $path = 'agent-assets/'.$business->id.'/post.jpg';
        Storage::disk('public')->put($path, 'image-bytes');
        $asset = AgentAsset::query()->create([
            'business_id' => $business->id,
            'agent_id' => $business->agent?->id,
            'disk' => 'public',
            'path' => $path,
            'original_name' => 'post.jpg',
            'mime' => 'image/jpeg',
            'size' => 11,
        ]);

        Http::fake([
            'https://api.social-api.ai/v1/media/upload' => Http::response([
                'media_id' => 'media_from_asset_9',
            ], 201),
        ]);
        CurrentBusiness::set($business->id);

        $result = app(DraftCreatePost::class)->handle($business, [
            'account_ids' => [$account->id],
            'text' => 'With photo',
            'asset_ids' => [$asset->id],
            'publish_now' => true,
        ], ['user_id' => $owner->id]);

        $this->assertTrue($result['ok'] ?? false);
        $this->assertSame(['media_from_asset_9'], $result['media_ids'] ?? null);

        $action = AgentPendingAction::query()->find($result['action_id']);
        $this->assertSame(['media_from_asset_9'], $action->payload['media_ids'] ?? null);
        $this->assertSame([$asset->id], $action->payload['asset_ids'] ?? null);
        $this->assertNotContains($asset->id, $action->payload['media_ids'] ?? []);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/media/upload'));
    }

    public function test_caption_request_does_not_queue_an_image(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $owner->update(['wallet_balance_da' => 200]);
        config(['services.fal.key' => 'fal_test_key']);

        Http::fake([
            'https://fal.run/openrouter/router/openai/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'هاهو الكابشن: عرض اليوم. واش نزيدو هاشتاغ؟',
                    ],
                ]],
            ]),
            'https://queue.fal.run/*' => Http::response(['request_id' => 'should-not-run'], 500),
        ]);

        $this->asShopUser($owner, $business)
            ->postJson('/api/agent/chat', ['text' => 'اكتب كابشن للبوست بلا صورة'])
            ->assertOk();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'queue.fal.run'));
        $this->assertDatabaseCount('agent_image_jobs', 0);
    }

    public function test_draft_uses_affirmed_image_not_later_regen(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $account = $this->connectAccount($business, 'acc_ig_1', 'instagram', 'Shop IG');
        Storage::fake('public');
        Storage::disk('public')->put('agent-assets/'.$business->id.'/a.jpg', 'one');
        Storage::disk('public')->put('agent-assets/'.$business->id.'/b.jpg', 'two');

        $older = AgentAsset::query()->create([
            'business_id' => $business->id,
            'disk' => 'public',
            'path' => 'agent-assets/'.$business->id.'/a.jpg',
            'original_name' => 'a.jpg',
            'mime' => 'image/jpeg',
            'size' => 3,
        ]);
        $newer = AgentAsset::query()->create([
            'business_id' => $business->id,
            'disk' => 'public',
            'path' => 'agent-assets/'.$business->id.'/b.jpg',
            'original_name' => 'b.jpg',
            'mime' => 'image/jpeg',
            'size' => 3,
        ]);

        AgentChatMessage::query()->create([
            'business_id' => $business->id,
            'role' => 'assistant',
            'content' => 'هاهي الصورة.',
            'meta' => ['asset_ids' => [$older->id], 'assets' => [['id' => $older->id]]],
        ]);
        AgentChatMessage::query()->create([
            'business_id' => $business->id,
            'role' => 'user',
            'content' => 'use it',
            'meta' => [],
        ]);
        AgentChatMessage::query()->create([
            'business_id' => $business->id,
            'role' => 'assistant',
            'content' => 'هاهي الصورة المجددة.',
            'meta' => ['asset_ids' => [$newer->id], 'assets' => [['id' => $newer->id]]],
        ]);

        Http::fake([
            'https://api.social-api.ai/v1/media/upload' => Http::response(['media_id' => 'media_old'], 201),
        ]);

        $kept = app(DraftCreatePost::class)->handle($business, [
            'account_ids' => [$account->id],
            'text' => 'Caption ok',
            'publish_now' => true,
        ], ['user_id' => $owner->id, 'owner_brief' => 'dir el post']);

        $this->assertTrue($kept['ok'] ?? false);
        $action = AgentPendingAction::query()->find($kept['action_id']);
        $this->assertSame([$older->id], $action->payload['asset_ids']);

        AgentChatMessage::query()->create([
            'business_id' => $business->id,
            'role' => 'user',
            'content' => 'use the new one',
            'meta' => [],
        ]);

        $fresh = app(DraftCreatePost::class)->handle($business, [
            'account_ids' => [$account->id],
            'text' => 'Caption ok',
            'publish_now' => true,
        ], ['user_id' => $owner->id, 'owner_brief' => 'use the new one']);

        $this->assertTrue($fresh['ok'] ?? false);
        $second = AgentPendingAction::query()->find($fresh['action_id']);
        $this->assertSame([$newer->id], $second->payload['asset_ids']);
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
