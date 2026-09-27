<?php

namespace Tests\Feature;

use App\AI\Tools\Owner\ConfirmPendingAction;
use App\Mcp\McpContext;
use App\Mcp\Tools\CreateAiCampaign;
use App\Jobs\ProcessAiCampaign;
use App\Models\AgentAsset;
use App\Models\AgentPendingAction;
use App\Models\AiCampaign;
use App\Models\AiCampaignSlot;
use App\Models\AiCampaignSlotTarget;
use App\Models\Product;
use App\Models\SocialAccount;
use App\Services\Campaigns\AiCampaignService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AiCampaignTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Campaign unit tests cover the PHP AgentLoop path; SK is exercised via live runtime.
        config(['ai_runtime.driver' => 'php']);
    }

    public function test_guest_cannot_list_campaigns(): void
    {
        $this->getJson('/api/agent/campaigns')->assertUnauthorized();
    }

    public function test_staff_cannot_launch_campaign(): void
    {
        ['staff' => $staff, 'business' => $business] = $this->makeShop();
        $account = $this->connectAccount($business, 'acc_1');

        $this->asShopUser($staff, $business)
            ->postJson('/api/agent/campaigns', $this->payload($account->id))
            ->assertForbidden();
    }

    public function test_launch_validates_days_prompt_and_image_coverage(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $account = $this->connectAccount($business, 'acc_1');
        Bus::fake();

        $this->asShopUser($owner, $business)
            ->postJson('/api/agent/campaigns', $this->payload($account->id, ['day_count' => 8]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['day_count']);

        $this->asShopUser($owner, $business)
            ->postJson('/api/agent/campaigns', $this->payload($account->id, ['focus_prompt' => 'short']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['focus_prompt']);

        $this->asShopUser($owner, $business)
            ->postJson('/api/agent/campaigns', $this->payload($account->id, [
                'content_mode' => 'product_images',
                'focus_prompt' => null,
                'asset_ids' => [],
                'days' => [[
                    'day_index' => 1,
                    'posts' => 2,
                    'stories' => 0,
                    'times' => ['10:00', '18:00'],
                ]],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['asset_ids']);
    }

    public function test_launch_is_scoped_and_creates_slots(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        ['owner' => $otherOwner, 'business' => $other] = $this->makeShopPair('other');
        $owner->update(['wallet_balance_da' => 5000]);
        $account = $this->connectAccount($business, 'acc_1');
        Bus::fake();

        $created = $this->asShopUser($owner, $business)
            ->postJson('/api/agent/campaigns', $this->payload($account->id, [
                'name' => 'Ramadan',
                'days' => [[
                    'day_index' => 1,
                    'posts' => 1,
                    'stories' => 1,
                    'times' => ['09:30'],
                ]],
            ]))
            ->assertCreated()
            ->json('campaign');

        $this->assertSame('scheduled', $created['status']);
        $this->assertGreaterThan(0, $created['estimated_da']);
        $this->assertCount(2, $created['slots']);
        $this->assertSame(['post', 'story'], collect($created['slots'])->pluck('slot_kind')->all());
        Bus::assertDispatched(ProcessAiCampaign::class);

        $this->asShopUser($otherOwner, $other)
            ->getJson('/api/agent/campaigns/'.$created['id'])
            ->assertNotFound();

        $this->asShopUser($owner, $business)
            ->getJson('/api/agent/campaigns')
            ->assertOk()
            ->assertJsonPath('campaigns.0.id', $created['id']);
    }

    public function test_example_uses_mcp_list_posts_and_returns_caption(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $owner->update(['wallet_balance_da' => 5000]);
        $business->agentSettings?->update(['image_model' => 'flux']);
        $account = $this->connectAccount($business, 'acc_1');
        $this->enableMcp();
        $this->fakeMcpAndLlm();

        $this->asShopUser($owner, $business)
            ->postJson('/api/agent/campaigns/example', $this->payload($account->id))
            ->assertOk()
            ->assertJsonPath('caption', 'Sample caption for the shop')
            ->assertJsonPath('title', 'Weekend shop tease')
            ->assertJsonPath('hashtags.0', '#Wasl')
            ->assertJsonPath('image_size', 'square_hd')
            ->assertJsonPath('platform', 'facebook')
            ->assertJsonPath('agents.writer', 'CampaignCreativeAgent')
            ->assertJsonPath('agents.approver', 'CaptionApprover')
            ->assertJsonPath('approver.approved', true)
            ->assertJsonStructure(['image_url']);

        Http::assertSent(function (Request $request) {
            $params = $request->data()['params'] ?? [];
            $name = $params['name'] ?? null;

            return str_contains($request->url(), 'api.social-api.ai/mcp')
                && in_array($name, ['list_posts', 'social_api_list_posts'], true);
        });
    }

    public function test_example_story_schedule_uses_portrait_size(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $owner->update(['wallet_balance_da' => 5000]);
        $business->agentSettings?->update(['image_model' => 'flux']);
        $account = $this->connectAccount($business, 'acc_ig', 'instagram');
        $this->enableMcp();
        $this->fakeMcpAndLlm();

        $this->asShopUser($owner, $business)
            ->postJson('/api/agent/campaigns/example', $this->payload($account->id, [
                'days' => [[
                    'day_index' => 1,
                    'posts' => 0,
                    'stories' => 1,
                    'times' => [],
                ]],
            ]))
            ->assertOk()
            ->assertJsonPath('image_size', 'portrait_16_9')
            ->assertJsonPath('platform', 'instagram');
    }

    public function test_example_uses_shop_image_model(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $owner->update(['wallet_balance_da' => 5000]);
        $business->agentSettings?->update(['image_model' => 'flux']);
        $account = $this->connectAccount($business, 'acc_1');
        $this->enableMcp();
        $this->fakeMcpAndLlm();

        $this->asShopUser($owner, $business)
            ->postJson('/api/agent/campaigns/example', $this->payload($account->id))
            ->assertOk();

        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), 'fal.run/fal-ai/flux');
        });
    }

    public function test_product_image_example_requires_an_asset(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $account = $this->connectAccount($business, 'acc_1');

        $this->asShopUser($owner, $business)
            ->postJson('/api/agent/campaigns/example', $this->payload($account->id, [
                'content_mode' => 'product_images',
                'focus_prompt' => null,
                'asset_ids' => [],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['asset_ids']);
    }

    public function test_example_brief_asks_then_marks_ready(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $owner->update(['wallet_balance_da' => 5000]);
        $account = $this->connectAccount($business, 'acc_1');
        $this->enableMcp();
        $this->fakeMcpAndLlm();

        $first = $this->asShopUser($owner, $business)
            ->postJson('/api/agent/campaigns/example/brief', $this->payload($account->id, [
                'messages' => [],
            ]))
            ->assertOk()
            ->assertJsonPath('ready', false)
            ->json();

        $this->assertNotEmpty($first['message']);
        $this->assertCount(1, $first['messages']);
        $this->assertSame('assistant', $first['messages'][0]['role']);

        $this->asShopUser($owner, $business)
            ->postJson('/api/agent/campaigns/example/brief', $this->payload($account->id, [
                'messages' => [
                    ...$first['messages'],
                    ['role' => 'user', 'content' => 'Highlight Weekly Diamond Pass for gamers'],
                ],
            ]))
            ->assertOk()
            ->assertJsonPath('ready', true)
            ->assertJsonPath('agents.brief', 'CampaignBriefAgent')
            ->assertJsonPath('agents.writer', 'CampaignCreativeAgent')
            ->assertJsonPath('agents.approver', 'CaptionApprover')
            ->assertJsonPath('messages.2.role', 'assistant');
    }

    public function test_example_brief_forces_ready_after_enough_answers(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $owner->update(['wallet_balance_da' => 5000]);
        $account = $this->connectAccount($business, 'acc_1');
        $this->enableMcp();

        // Keep returning ready=false so the server-side force gate must fire.
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'fal.run')) {
                return Http::response([
                    'choices' => [[
                        'message' => [
                            'content' => '{"ready":false,"message":"Still need more.","question":"Anything else?"}',
                        ],
                    ]],
                    'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 5, 'cost' => 0.01],
                ]);
            }

            return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['content' => [['text' => 'ok']]]]);
        });

        $messages = [];
        $ready = false;
        for ($i = 0; $i < 4; $i++) {
            if ($i > 0) {
                $messages[] = ['role' => 'user', 'content' => 'Push Weekly Diamond Pass at 401 DA'];
            }
            $turn = $this->asShopUser($owner, $business)
                ->postJson('/api/agent/campaigns/example/brief', $this->payload($account->id, [
                    'messages' => $messages,
                    'focus_prompt' => 'Weekly Diamond Pass promo for MLBB gamers in Biskra this weekend',
                ]))
                ->assertOk()
                ->json();
            $messages = $turn['messages'];
            $ready = (bool) $turn['ready'];
            if ($ready) {
                break;
            }
        }

        $this->assertTrue($ready, 'Brief must force ready=true and stop the loop');
    }

    public function test_example_passes_brief_notes_into_focus(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $owner->update(['wallet_balance_da' => 5000]);
        $business->agentSettings?->update(['image_model' => 'flux']);
        $account = $this->connectAccount($business, 'acc_1');
        $this->enableMcp();
        $this->fakeMcpAndLlm();

        $this->asShopUser($owner, $business)
            ->postJson('/api/agent/campaigns/example', $this->payload($account->id, [
                'brief_messages' => [
                    ['role' => 'assistant', 'content' => 'Which product?'],
                    ['role' => 'user', 'content' => 'Weekly Diamond Pass only'],
                ],
            ]))
            ->assertOk()
            ->assertJsonPath('caption', 'Sample caption for the shop');

        Http::assertSent(function (Request $request) {
            if (! str_contains($request->url(), 'chat/completions') && ! str_contains($request->url(), 'fal.run')) {
                return false;
            }
            if (str_contains($request->url(), 'fal-ai/')) {
                return false;
            }
            $body = json_encode($request->data());

            return is_string($body) && str_contains($body, 'Weekly Diamond Pass only');
        });
    }

    public function test_example_injects_verified_product_facts_and_algerian_dialect_lock(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $owner->update(['wallet_balance_da' => 5000]);
        $business->agentSettings?->update([
            'image_model' => 'flux',
            'language' => 'Darija',
        ]);
        Product::query()->create([
            'business_id' => $business->id,
            'name' => 'Weekly Diamond Pass',
            'slug' => 'weekly-diamond-pass',
            'description' => 'One week MLBB pass. Automatic top-up. No daily diamond claim invented.',
            'price' => 401,
            'stock' => 99,
            'status' => 'active',
            'type' => 'digital',
        ]);
        $account = $this->connectAccount($business, 'acc_1');
        $this->enableMcp();
        $this->fakeMcpAndLlm();

        $this->asShopUser($owner, $business)
            ->postJson('/api/agent/campaigns/example', $this->payload($account->id, [
                'focus_prompt' => 'Push Weekly Diamond Pass at 401 DA for Mobile Legends buyers',
            ]))
            ->assertOk();

        $sawFacts = false;
        $sawDialect = false;
        foreach (Http::recorded() as [$request]) {
            $url = $request->url();
            if (! str_contains($url, 'fal.run') || str_contains($url, 'fal-ai/')) {
                continue;
            }
            $body = json_encode($request->data(), JSON_UNESCAPED_UNICODE) ?: '';
            if (str_contains($body, 'VERIFIED_PRODUCT_FACTS')
                && str_contains($body, 'Weekly Diamond Pass')
                && str_contains($body, '401')
                && str_contains($body, 'Automatic top-up')) {
                $sawFacts = true;
            }
            if (str_contains($body, 'Algerian Maghrebi')
                && (str_contains($body, 'دلوقتي') || str_contains($body, 'Maghrebi Darija'))) {
                $sawDialect = true;
            }
        }

        $this->assertTrue($sawFacts, 'Expected VERIFIED_PRODUCT_FACTS with catalog product in LLM payload');
        $this->assertTrue($sawDialect, 'Expected Algerian dialect lock in creative system prompt');
    }

    public function test_cancel_skips_pending_slots(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $owner->update(['wallet_balance_da' => 5000]);
        $account = $this->connectAccount($business, 'acc_1');
        Bus::fake();

        $id = $this->asShopUser($owner, $business)
            ->postJson('/api/agent/campaigns', $this->payload($account->id))
            ->assertCreated()
            ->json('campaign.id');

        $this->asShopUser($owner, $business)
            ->postJson('/api/agent/campaigns/'.$id.'/cancel')
            ->assertOk()
            ->assertJsonPath('campaign.status', 'cancelled');

        $this->assertSame(0, AiCampaignSlot::query()->where('ai_campaign_id', $id)->where('status', 'pending')->count());
        $this->assertSame(0, AiCampaignSlot::query()->where('ai_campaign_id', $id)->where('status', '!=', 'cancelled')->count());
        $this->assertSame(0, AiCampaignSlotTarget::query()->where('status', 'pending')->count());
    }

    public function test_job_calls_mcp_create_post_and_media_upload(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $owner->update(['wallet_balance_da' => 20000]);
        $business->agentSettings?->update(['image_model' => 'flux']);
        $account = $this->connectAccount($business, 'acc_9');
        Storage::fake('public');
        $path = 'agent-assets/'.$business->id.'/product.jpg';
        Storage::disk('public')->put($path, 'fake-image-bytes');
        $asset = AgentAsset::query()->create([
            'business_id' => $business->id,
            'agent_id' => $business->agent?->id,
            'disk' => 'public',
            'path' => $path,
            'original_name' => 'product.jpg',
            'mime' => 'image/jpeg',
            'size' => 16,
        ]);
        $this->enableMcp();
        $this->fakeMcpAndLlm();

        $id = $this->asShopUser($owner, $business)
            ->postJson('/api/agent/campaigns', $this->payload($account->id, [
                'content_mode' => 'product_images',
                'focus_prompt' => null,
                'asset_ids' => [$asset->id],
                'days' => [[
                    'day_index' => 1,
                    'posts' => 1,
                    'stories' => 0,
                    'times' => ['11:00'],
                ]],
            ]))
            ->assertCreated()
            ->json('campaign.id');

        $campaign = AiCampaign::query()->findOrFail($id);
        $this->assertSame(AiCampaign::STATUS_SCHEDULED, $campaign->status);
        $this->processAll($campaign);

        $campaign->refresh();
        $slot = $campaign->slots()->first();
        $this->assertSame(AiCampaignSlot::STATUS_AWAITING_APPROVAL, $slot->status);
        $this->assertNull($slot->socialapi_post_id);
        $this->assertSame(AiCampaignSlotTarget::STATUS_READY, $slot->targets()->first()->status);

        $namesBeforeAccept = [];
        Http::recorded(function (Request $request) use (&$namesBeforeAccept) {
            if (! str_contains($request->url(), 'api.social-api.ai/mcp')) {
                return false;
            }
            $namesBeforeAccept[] = $request->data()['params']['name'] ?? null;

            return true;
        });
        $this->assertFalse(
            in_array('create_post', $namesBeforeAccept, true) || in_array('social_api_create_post', $namesBeforeAccept, true),
            'schedulePost must not run before Accept'
        );

        $this->acceptAll($campaign);
        $campaign->refresh();
        $slot->refresh();
        $this->assertSame(AiCampaign::STATUS_COMPLETED, $campaign->status);
        $this->assertSame(AiCampaignSlot::STATUS_SCHEDULED, $slot->status);
        $this->assertSame('post_77', $slot->socialapi_post_id);
        $this->assertSame('post_77', $slot->targets()->first()->socialapi_post_id);

        $names = [];
        Http::recorded(function (Request $request) use (&$names) {
            if (! str_contains($request->url(), 'api.social-api.ai/mcp')) {
                return false;
            }
            $names[] = $request->data()['params']['name'] ?? null;

            return true;
        });
        $this->assertContains('upload_media', $names);
        $this->assertTrue(in_array('create_post', $names, true) || in_array('social_api_create_post', $names, true));
    }

    public function test_ai_recent_job_generates_image_and_uploads_via_mcp(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $owner->update(['wallet_balance_da' => 20000]);
        $business->agentSettings?->update(['image_model' => 'flux']);
        $account = $this->connectAccount($business, 'acc_ai');
        Storage::fake('public');
        $this->enableMcp();
        $this->fakeMcpAndLlm();

        $id = $this->asShopUser($owner, $business)
            ->postJson('/api/agent/campaigns', $this->payload($account->id))
            ->assertCreated()
            ->json('campaign.id');

        $campaign = AiCampaign::query()->findOrFail($id);
        $this->processAll($campaign);

        $campaign->refresh();
        $this->assertSame(AiCampaignSlot::STATUS_AWAITING_APPROVAL, $campaign->slots()->first()?->status);
        $this->assertNotNull($campaign->slots()->first()?->agent_asset_id);

        $this->acceptAll($campaign);
        $campaign->refresh();
        $this->assertSame(AiCampaign::STATUS_COMPLETED, $campaign->status);
        $this->assertGreaterThan(0, (float) $campaign->spent_da);

        $names = [];
        Http::recorded(function (Request $request) use (&$names) {
            if (! str_contains($request->url(), 'api.social-api.ai/mcp')) {
                return false;
            }
            $names[] = $request->data()['params']['name'] ?? null;

            return true;
        });
        $this->assertContains('list_posts', $names);
        $this->assertContains('upload_media', $names);
        $this->assertTrue(in_array('create_post', $names, true) || in_array('social_api_create_post', $names, true));
    }

    public function test_launch_ingests_campaign_memory_and_stores_plan_meta(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $owner->update(['wallet_balance_da' => 5000]);
        $account = $this->connectAccount($business, 'acc_mem');
        Bus::fake();

        Http::fake([
            config('ai_runtime.url').'/v1/knowledge/ingest' => Http::response([
                'ok' => true,
                'chunks_upserted' => 1,
                'business_id' => $business->id,
                'source_type' => 'ai_campaign',
                'source_id' => 'campaign_prefs:'.$business->id,
            ], 200),
            '*' => Http::response(['ok' => true], 200),
        ]);

        $planMeta = [
            'analysis' => 'Single SKU sneakers vibe',
            'plan_notes' => 'Highlight weekend drop',
            'product_focus' => 'white sneakers',
            'tease_caption' => 'Drop weekend sneakers 👟',
        ];

        $created = $this->asShopUser($owner, $business)
            ->postJson('/api/agent/campaigns', $this->payload($account->id, [
                'plan_meta' => $planMeta,
                'accepted_tease' => 'Drop weekend sneakers 👟',
                'brief_notes' => 'Keep it Darija casual',
            ]))
            ->assertCreated()
            ->json('campaign');

        $campaign = AiCampaign::query()->findOrFail($created['id']);
        $this->assertSame('Highlight weekend drop', $campaign->plan_meta['plan_notes'] ?? null);

        Http::assertSent(function (Request $request) use ($business) {
            if (! str_contains($request->url(), '/v1/knowledge/ingest')) {
                return false;
            }
            $data = $request->data();

            return ($data['namespace'] ?? null) === 'memories'
                && ($data['source_id'] ?? null) === 'campaign_prefs:'.$business->id
                && str_contains((string) ($data['content'] ?? ''), 'Accepted tease');
        });
    }

    public function test_dashboard_slot_accept_cancel_regenerate(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $owner->update(['wallet_balance_da' => 20000]);
        $account = $this->connectAccount($business, 'acc_gate');
        Storage::fake('public');
        $path = 'agent-assets/'.$business->id.'/p.jpg';
        Storage::disk('public')->put($path, 'bytes');
        $asset = AgentAsset::query()->create([
            'business_id' => $business->id,
            'agent_id' => $business->agent?->id,
            'disk' => 'public',
            'path' => $path,
            'original_name' => 'p.jpg',
            'mime' => 'image/jpeg',
            'size' => 5,
        ]);
        $this->enableMcp();
        $this->fakeMcpAndLlm();

        $id = $this->asShopUser($owner, $business)
            ->postJson('/api/agent/campaigns', $this->payload($account->id, [
                'content_mode' => 'product_images',
                'focus_prompt' => null,
                'asset_ids' => [$asset->id],
            ]))
            ->assertCreated()
            ->json('campaign.id');

        $campaign = AiCampaign::query()->findOrFail($id);
        $this->processAll($campaign);
        $slot = $campaign->slots()->first();
        $this->assertSame(AiCampaignSlot::STATUS_AWAITING_APPROVAL, $slot->status);

        $regen = $this->asShopUser($owner, $business)
            ->postJson("/api/agent/campaigns/{$id}/slots/{$slot->id}/regenerate")
            ->assertOk()
            ->json('campaign.slots.0.status');
        $this->assertContains($regen, [
            AiCampaignSlot::STATUS_REGEN_REQUESTED,
            AiCampaignSlot::STATUS_AWAITING_APPROVAL,
            AiCampaignSlot::STATUS_GENERATING,
            AiCampaignSlot::STATUS_PENDING,
        ]);

        $slot->refresh();
        if ($slot->status !== AiCampaignSlot::STATUS_AWAITING_APPROVAL) {
            $slot->update(['status' => AiCampaignSlot::STATUS_PENDING, 'dispatched_at' => null]);
            $slot->targets()->update(['status' => AiCampaignSlotTarget::STATUS_PENDING, 'caption' => null]);
            app(AiCampaignService::class)->processSlot($slot->id);
            $slot->refresh();
        }
        $this->assertSame(AiCampaignSlot::STATUS_AWAITING_APPROVAL, $slot->status);

        $this->asShopUser($owner, $business)
            ->postJson("/api/agent/campaigns/{$id}/slots/{$slot->id}/accept")
            ->assertOk()
            ->assertJsonPath('campaign.slots.0.status', AiCampaignSlot::STATUS_SCHEDULED);

        $id2 = $this->asShopUser($owner, $business)
            ->postJson('/api/agent/campaigns', $this->payload($account->id, [
                'name' => 'CancelMe',
                'content_mode' => 'product_images',
                'focus_prompt' => null,
                'asset_ids' => [$asset->id],
            ]))
            ->assertCreated()
            ->json('campaign.id');
        $c2 = AiCampaign::query()->findOrFail($id2);
        $this->processAll($c2);
        $s2 = $c2->slots()->first();
        $this->asShopUser($owner, $business)
            ->postJson("/api/agent/campaigns/{$id2}/slots/{$s2->id}/cancel")
            ->assertOk()
            ->assertJsonPath('campaign.slots.0.status', AiCampaignSlot::STATUS_CANCELLED);
    }

    public function test_product_images_brief_context_excludes_catalog_dump(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        Product::query()->create([
            'business_id' => $business->id,
            'name' => 'monthly pass',
            'slug' => 'monthly-pass',
            'price' => 1300,
            'stock' => 20,
            'status' => 'active',
            'type' => 'digital',
        ]);
        Product::query()->create([
            'business_id' => $business->id,
            'name' => 'weekly elite',
            'slug' => 'weekly-elite',
            'price' => 320,
            'stock' => 50,
            'status' => 'active',
            'type' => 'digital',
        ]);
        Storage::fake('public');
        $path = 'agent-assets/'.$business->id.'/pass.jpg';
        Storage::disk('public')->put($path, 'img');
        $asset = AgentAsset::query()->create([
            'business_id' => $business->id,
            'agent_id' => $business->agent?->id,
            'disk' => 'public',
            'path' => $path,
            'original_name' => 'weekly-pass.jpg',
            'mime' => 'image/jpeg',
            'size' => 3,
        ]);

        $svc = app(\App\Services\Campaigns\CampaignExampleBriefService::class);
        $ref = new \ReflectionMethod($svc, 'contextBlock');
        $ref->setAccessible(true);
        $block = $ref->invoke($svc, $business, [
            'content_mode' => 'product_images',
            'focus_prompt' => '',
            'asset_ids' => [$asset->id],
            '_vision_understanding' => 'Weekly Pass flash sale 1100 DZD',
        ]);

        $this->assertStringContainsString('VISION UNDERSTANDING', $block);
        $this->assertStringContainsString('Weekly Pass flash sale', $block);
        $this->assertStringContainsString('weekly-pass.jpg', $block);
        $this->assertStringNotContainsString('monthly pass', $block);
        $this->assertStringNotContainsString('weekly elite', $block);
        $this->assertStringNotContainsString('Catalog sample', $block);
    }

    public function test_product_images_brief_awaits_accept_after_vision_understand(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $owner->update(['wallet_balance_da' => 5000]);
        $account = $this->connectAccount($business, 'acc_vision');
        config(['ai_runtime.driver' => 'sk', 'ai_runtime.url' => 'http://runtime.test']);
        Storage::fake('public');
        $path = 'agent-assets/'.$business->id.'/w.jpg';
        Storage::disk('public')->put($path, 'bytes');
        $asset = AgentAsset::query()->create([
            'business_id' => $business->id,
            'agent_id' => $business->agent?->id,
            'disk' => 'public',
            'path' => $path,
            'original_name' => 'weekly.jpg',
            'mime' => 'image/jpeg',
            'size' => 5,
        ]);

        Http::fake([
            'http://runtime.test/v1/post_crafter/understand' => Http::response([
                'summary' => 'Mobile Legends Weekly Pass flash sale at 1100 DZD.',
                'product_focus' => 'Weekly Pass',
                'multi_product' => false,
                'image_analyses' => [[
                    'url' => 'http://example/w.jpg',
                    'label' => 'asset_'.$asset->id,
                    'description' => 'Weekly Pass',
                    'ok' => true,
                ]],
                'claims' => [
                    ['id' => 'product_weekly', 'text' => 'Product is Weekly Pass', 'group' => 'vision', 'required' => true],
                    ['id' => 'price_1100', 'text' => 'Price shown is 1100 DZD', 'group' => 'vision', 'required' => true],
                ],
                'process_options' => [
                    ['id' => 'tone_darija', 'text' => 'Write in Darija', 'group' => 'process', 'default_on' => true],
                    ['id' => 'separate_posts', 'text' => 'Use each image on a separate post', 'group' => 'process', 'default_on' => false],
                ],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20, 'cost_usd' => 0.01, 'fal_calls' => 1, 'calls_with_cost' => 1],
                'runtime' => 'sk',
            ], 200),
            'http://runtime.test/v1/translation/translate' => Http::response([
                'texts' => [
                    'message' => "شفت العرض: Weekly Pass\n\nأكّد باش نولّد النموذج.",
                    'understanding' => 'Mobile Legends Weekly Pass flash sale at 1100 DZD.',
                    'card_0' => 'استعمل كل صورة في بوست وحدو',
                ],
                'language' => 'darija',
                'label' => 'Algerian Darija',
                'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 8, 'cost_usd' => 0.0, 'fal_calls' => 0, 'calls_with_cost' => 0],
                'agents' => ['translator' => 'TranslationAgent'],
                'runtime' => 'sk',
            ], 200),
            '*' => Http::response(['ok' => true], 200),
        ]);

        $res = $this->asShopUser($owner, $business)
            ->postJson('/api/agent/campaigns/example/brief', $this->payload($account->id, [
                'content_mode' => 'product_images',
                'focus_prompt' => null,
                'asset_ids' => [$asset->id],
                'messages' => [],
            ]))
            ->assertOk()
            ->assertJsonPath('ready', true)
            ->assertJsonPath('awaiting_accept', true)
            ->assertJsonPath('product_focus', 'Weekly Pass');

        $this->assertStringContainsString('Weekly Pass', (string) $res->json('understanding'));
        $this->assertStringContainsString('شفت', (string) $res->json('message'));
        $this->assertSame('TranslationAgent', $res->json('agents.translator'));
        $cards = $res->json('confirm_cards');
        $this->assertIsArray($cards);
        // Vision claims are trusted — not shown as Confirm/Deny cards.
        $this->assertFalse(collect($cards)->contains(fn ($c) => ($c['group'] ?? '') === 'vision'));
        // Naive language cards are stripped; useful process cards remain.
        $this->assertFalse(collect($cards)->contains(fn ($c) => str_contains(strtolower((string) ($c['text'] ?? '')), 'darija')));
        $this->assertTrue(collect($cards)->contains(fn ($c) => ($c['id'] ?? '') === 'separate_posts'));
        $this->assertStringNotContainsString('monthly pass', strtolower((string) $res->json('message')));
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/v1/post_crafter/understand'));
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/v1/translation/translate'));
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/v1/post_crafter/plan'));
    }

    public function test_create_tool_waits_for_confirm(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $owner->update(['wallet_balance_da' => 5000]);
        $account = $this->connectAccount($business, 'acc_1');
        Bus::fake();

        $pending = app(CreateAiCampaign::class)->handle(
            $business,
            $this->payload($account->id),
            new McpContext(McpContext::SURFACE_OWNER, [McpContext::SURFACE_OWNER], userId: $owner->id),
        );

        $this->assertTrue($pending['pending'] ?? false);
        $this->assertSame(0, AiCampaign::query()->count());
        $action = AgentPendingAction::query()->find($pending['action_id']);
        $this->assertSame(AgentPendingAction::TYPE_AI_CAMPAIGN, $action->type);

        $confirmed = app(ConfirmPendingAction::class)->handle($business, [
            'action_id' => $action->id,
            'confirmed' => true,
        ], ['user_id' => $owner->id]);

        $this->assertTrue($confirmed['ok'] ?? false);
        $this->assertSame(1, AiCampaign::query()->count());
        Bus::assertDispatched(ProcessAiCampaign::class);
    }

    private function processAll(AiCampaign $campaign): void
    {
        $service = app(AiCampaignService::class);
        foreach ($campaign->slots()->pluck('id') as $slotId) {
            $service->processSlot((int) $slotId);
        }
    }

    private function acceptAll(AiCampaign $campaign): void
    {
        $approvals = app(\App\Services\Campaigns\CampaignSlotApprovalService::class);
        foreach ($campaign->slots()->get() as $slot) {
            if ($slot->status === AiCampaignSlot::STATUS_AWAITING_APPROVAL) {
                $approvals->accept($slot);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(int $accountId, array $overrides = []): array
    {
        return array_replace_recursive([
            'name' => 'Spring',
            'channel_ids' => [$accountId],
            'day_count' => 1,
            'days' => [[
                'day_index' => 1,
                'posts' => 1,
                'stories' => 0,
                'times' => ['10:00'],
            ]],
            'content_mode' => 'ai_recent',
            'focus_prompt' => 'Ramadan bundles with free shipping',
            'asset_ids' => [],
        ], $overrides);
    }

    private function connectAccount($business, string $remoteId, string $platform = 'facebook'): SocialAccount
    {
        return SocialAccount::query()->create([
            'business_id' => $business->id,
            'provider' => 'socialapi',
            'socialapi_account_id' => $remoteId,
            'platform' => $platform,
            'name' => 'Page',
            'status' => 'connected',
            'connected_at' => now(),
        ]);
    }

    /**
     * @return array{owner: \App\Models\User, business: \App\Models\Business}
     */
    private function makeShopPair(string $prefix): array
    {
        $owner = \App\Models\User::factory()->create([
            'email' => $prefix.'@example.test',
        ]);
        $business = app(\App\Services\ShopProvisioner::class)->createShop($owner, $prefix.' shop', [
            'wilaya' => 'Oran',
        ]);

        return compact('owner', 'business');
    }

    private function enableMcp(): void
    {
        config([
            'services.socialapi.mcp_enabled' => true,
            'services.socialapi.key' => 'test-key',
            'services.socialapi.mcp_url' => 'https://api.social-api.ai/mcp',
            'services.fal.key' => 'fal-test-key',
        ]);
    }

    private function fakeMcpAndLlm(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();

            if (str_contains($url, 'cdn.example.test/gen.jpg') || str_contains($url, 'generated-sample.jpg')) {
                return Http::response('fake-jpeg-bytes', 200, ['Content-Type' => 'image/jpeg']);
            }

            if (str_contains($url, 'fal.run/fal-ai/') || str_contains($url, 'fal.run/fal-ai%2F')) {
                return Http::response([
                    'images' => [[
                        'url' => 'https://cdn.example.test/gen.jpg',
                        'content_type' => 'image/jpeg',
                        'width' => 1024,
                        'height' => 1024,
                    ]],
                ]);
            }

            if (str_contains($url, 'fal.run')) {
                $payload = json_encode($request->data()) ?: '';
                $isBrief = str_contains($payload, 'campaign brief assistant')
                    || str_contains($payload, 'Start the briefing')
                    || str_contains($payload, 'Continue the briefing');
                $isApprover = str_contains($payload, 'You are CaptionApprover');
                $content = $isBrief
                    ? (str_contains($payload, 'Continue the briefing')
                        ? '{"ready":true,"message":"Perfect, I have enough to write the tease.","question":""}'
                        : '{"ready":false,"message":"Got it.","question":"Which product or offer should this tease push?"}')
                    : ($isApprover
                        ? '{"decision":"approved","score":0.92,"reasons":["on_brief"],"feedback":""}'
                        : '{"title":"Weekend shop tease","caption":"Sample caption for the shop","hashtags":["#Wasl","#Promo"],"image_prompt":"Clean shop window display"}');

                return Http::response([
                    'choices' => [[
                        'message' => [
                            'content' => $content,
                        ],
                    ]],
                    'usage' => [
                        'prompt_tokens' => 10,
                        'completion_tokens' => 8,
                        'cost' => 0.01,
                    ],
                ]);
            }

            $name = $request->data()['params']['name'] ?? '';
            $result = match ($name) {
                'list_posts', 'social_api_list_posts' => ['content' => [['text' => 'Recent post about delivery']]],
                'upload_media' => ['media_id' => 'med_1'],
                'create_post', 'social_api_create_post' => ['id' => 'post_77'],
                default => null,
            };
            if ($result === null) {
                return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'error' => ['message' => 'unexpected '.$name]]);
            }

            return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => $result]);
        });
    }
}
