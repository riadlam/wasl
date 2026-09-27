<?php

namespace Tests\Feature;

use App\Jobs\ProcessCampaignSlot;
use App\Models\AgentAsset;
use App\Models\AiCampaign;
use App\Models\AiCampaignSlot;
use App\Models\AiCampaignSlotTarget;
use App\Models\Business;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Campaigns\AiCampaignService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CampaignLaunchReadinessTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array<string, mixed>> */
    private array $createPostArgs = [];

    /** @var list<string> */
    private array $deleted = [];

    public function test_launch_requires_wallet_to_cover_the_estimate(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $owner->update(['wallet_balance_da' => 0]);
        $account = $this->connect($business, 'acc_fb', 'facebook');
        Bus::fake();
        $payload = $this->payload([$account->id], $this->asset($business)->id);

        $this->asShopUser($owner, $business)
            ->postJson('/api/agent/campaigns/estimate', $payload)
            ->assertOk()
            ->assertJsonPath('estimate.affordable', false)
            ->assertJsonPath('estimate.slots', 2);

        $this->asShopUser($owner, $business)
            ->postJson('/api/agent/campaigns', $payload)
            ->assertStatus(402);

        $this->assertSame(0, AiCampaign::query()->count());
        Bus::assertNotDispatched(ProcessCampaignSlot::class);
    }

    public function test_dispatch_due_only_queues_slots_inside_the_lookahead_once(): void
    {
        config(['campaigns.draft_asap' => false]);
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $campaign = $this->launch($owner, $business, [$this->connect($business, 'acc_fb', 'facebook')->id]);
        [$soon, $later] = $campaign->slots()->orderBy('id')->get()->all();
        $soon->update(['scheduled_at' => now()->addHour()]);
        $later->update(['scheduled_at' => now()->addDays(2)]);
        Bus::fake();

        $this->artisan('campaigns:dispatch-due')->assertSuccessful();
        $this->artisan('campaigns:dispatch-due')->assertSuccessful();

        Bus::assertDispatchedTimes(ProcessCampaignSlot::class, 1);
        Bus::assertDispatched(ProcessCampaignSlot::class, fn (ProcessCampaignSlot $job) => $job->uniqueId() === 'campaign-slot-'.$soon->id);
        $this->assertNotNull($soon->fresh()->dispatched_at);
        $this->assertNull($later->fresh()->dispatched_at);
    }

    public function test_draft_asap_queues_far_future_slots_one_at_a_time(): void
    {
        config(['campaigns.draft_asap' => true]);
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $campaign = $this->launch($owner, $business, [$this->connect($business, 'acc_fb', 'facebook')->id]);
        [$first, $second] = $campaign->slots()->orderBy('id')->get()->all();
        $first->update(['scheduled_at' => now()->addDays(3), 'dispatched_at' => null]);
        $second->update(['scheduled_at' => now()->addDays(5), 'dispatched_at' => null]);
        Bus::fake();

        $this->artisan('campaigns:dispatch-due')->assertSuccessful();
        $this->artisan('campaigns:dispatch-due')->assertSuccessful();

        Bus::assertDispatchedTimes(ProcessCampaignSlot::class, 1);
        Bus::assertDispatched(ProcessCampaignSlot::class, fn (ProcessCampaignSlot $job) => $job->uniqueId() === 'campaign-slot-'.$first->id);
        $this->assertNotNull($first->fresh()->dispatched_at);
        $this->assertNull($second->fresh()->dispatched_at);

        $first->update(['status' => AiCampaignSlot::STATUS_SCHEDULED]);
        Bus::fake();
        app(AiCampaignService::class)->dispatchNextForCampaign($campaign->fresh());
        Bus::assertDispatchedTimes(ProcessCampaignSlot::class, 1);
        Bus::assertDispatched(ProcessCampaignSlot::class, fn (ProcessCampaignSlot $job) => $job->uniqueId() === 'campaign-slot-'.$second->id);
    }

    public function test_dispatch_due_runs_one_slot_at_a_time_per_campaign(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $campaign = $this->launch($owner, $business, [$this->connect($business, 'acc_fb', 'facebook')->id]);
        [$first, $second] = $campaign->slots()->orderBy('id')->get()->all();
        $first->update(['scheduled_at' => now()->addMinutes(10), 'dispatched_at' => null]);
        $second->update(['scheduled_at' => now()->addMinutes(20), 'dispatched_at' => null]);
        Bus::fake();

        app(AiCampaignService::class)->dispatchDue($campaign->fresh());
        Bus::assertDispatchedTimes(ProcessCampaignSlot::class, 1);
        Bus::assertDispatched(ProcessCampaignSlot::class, fn (ProcessCampaignSlot $job) => $job->uniqueId() === 'campaign-slot-'.$first->id);

        $first->update(['status' => AiCampaignSlot::STATUS_AWAITING_APPROVAL, 'dispatched_at' => now()]);
        Bus::fake();
        app(AiCampaignService::class)->dispatchDue($campaign->fresh());
        Bus::assertNotDispatched(ProcessCampaignSlot::class);

        $first->update(['status' => AiCampaignSlot::STATUS_SCHEDULED]);
        Bus::fake();
        app(AiCampaignService::class)->dispatchNextForCampaign($campaign->fresh());
        Bus::assertDispatchedTimes(ProcessCampaignSlot::class, 1);
        Bus::assertDispatched(ProcessCampaignSlot::class, fn (ProcessCampaignSlot $job) => $job->uniqueId() === 'campaign-slot-'.$second->id);
    }

    public function test_multi_platform_posts_and_stories_use_live_schema_and_are_idempotent(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $fb = $this->connect($business, 'acc_fb', 'facebook');
        $ig = $this->connect($business, 'acc_ig', 'instagram');
        $campaign = $this->launch($owner, $business, [$fb->id, $ig->id]);
        $this->fakeMcpAndLlm(liveSchema: true);

        $this->processAll($campaign);
        $this->assertSame(0, count($this->createPostArgs), 'must not schedule before Accept');
        $this->acceptAll($campaign);
        $calls = count($this->createPostArgs);
        $this->processAll($campaign);
        $this->acceptAll($campaign);

        $this->assertSame(4, $calls, 'one create_post per platform for the post and the story');
        $this->assertCount($calls, $this->createPostArgs, 'processing again must not create duplicates');

        $campaign->refresh()->load('slots.targets');
        $this->assertSame(AiCampaign::STATUS_COMPLETED, $campaign->status);
        $this->assertSame(4, AiCampaignSlotTarget::query()->where('status', AiCampaignSlotTarget::STATUS_SCHEDULED)->count());

        $keys = array_column($this->createPostArgs, 'idempotency_key');
        $this->assertCount(4, array_unique($keys));
        foreach ($keys as $key) {
            $this->assertMatchesRegularExpression('/^wasl-slot-\d+-(facebook|instagram)-ok$/', $key);
        }

        $stories = array_filter($this->createPostArgs, fn ($a) => ($a['post_type'] ?? null) === 'story');
        $this->assertCount(2, $stories);
        foreach ($this->createPostArgs as $args) {
            $this->assertSame(['med_1'], $args['media_ids']);
            $this->assertCount(1, $args['account_ids']);
        }
    }

    public function test_story_on_a_platform_without_stories_is_skipped(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $campaign = $this->launch($owner, $business, [$this->connect($business, 'acc_x', 'twitter')->id]);
        $this->fakeMcpAndLlm();

        $this->processAll($campaign);
        $this->acceptAll($campaign);

        $story = $campaign->slots()->where('slot_kind', 'story')->firstOrFail();
        $this->assertSame(AiCampaignSlotTarget::STATUS_SKIPPED, $story->targets()->first()->status);
        $this->assertCount(1, $this->createPostArgs);
    }

    public function test_cancel_deletes_future_scheduled_posts(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $campaign = $this->launch($owner, $business, [$this->connect($business, 'acc_fb', 'facebook')->id]);
        $this->fakeMcpAndLlm();
        $post = $campaign->slots()->where('slot_kind', 'post')->firstOrFail();
        app(AiCampaignService::class)->processSlot($post->id);
        $this->assertSame(AiCampaignSlot::STATUS_AWAITING_APPROVAL, $post->fresh()->status);
        app(\App\Services\Campaigns\CampaignSlotApprovalService::class)->accept($post->fresh());
        $this->assertSame(AiCampaignSlot::STATUS_SCHEDULED, $post->fresh()->status);

        $this->asShopUser($owner, $business)
            ->postJson('/api/agent/campaigns/'.$campaign->id.'/cancel')
            ->assertOk()
            ->assertJsonPath('campaign.status', 'cancelled');

        $this->assertSame(['post_77'], $this->deleted);
        $this->assertSame(AiCampaignSlotTarget::STATUS_DELETED, $post->targets()->first()->status);
        $this->assertSame(0, AiCampaignSlot::query()->whereIn('status', AiCampaignSlot::OPEN_STATUSES)->count());
    }

    public function test_low_wallet_pauses_then_resumes_after_top_up(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $campaign = $this->launch($owner, $business, [$this->connect($business, 'acc_fb', 'facebook')->id]);
        $this->fakeMcpAndLlm();
        $owner->update(['wallet_balance_da' => 0]);
        $slot = $campaign->slots()->where('slot_kind', 'post')->firstOrFail();

        app(AiCampaignService::class)->processSlot($slot->id);

        $this->assertSame(AiCampaign::STATUS_PAUSED_WALLET, $campaign->fresh()->status);
        $this->assertSame(AiCampaignSlot::STATUS_PENDING, $slot->fresh()->status);
        $this->assertSame([], $this->createPostArgs);

        $owner->update(['wallet_balance_da' => 20000]);
        Bus::fake();
        app(AiCampaignService::class)->dispatchDue($campaign->fresh());

        $this->assertNotSame(AiCampaign::STATUS_PAUSED_WALLET, $campaign->fresh()->status);
    }

    public function test_failed_slot_can_be_retried(): void
    {
        ['owner' => $owner, 'staff' => $staff, 'business' => $business] = $this->makeShop();
        $campaign = $this->launch($owner, $business, [$this->connect($business, 'acc_fb', 'facebook')->id]);
        $slot = $campaign->slots()->where('slot_kind', 'post')->firstOrFail();
        app(AiCampaignService::class)->failSlot($slot->id, 'SocialAPI rejected the media.');
        $this->assertSame(AiCampaignSlot::STATUS_FAILED, $slot->fresh()->status);

        $this->asShopUser($staff, $business)
            ->postJson('/api/agent/campaigns/'.$campaign->id.'/slots/'.$slot->id.'/retry')
            ->assertForbidden();

        Bus::fake();
        $this->asShopUser($owner, $business)
            ->postJson('/api/agent/campaigns/'.$campaign->id.'/slots/'.$slot->id.'/retry')
            ->assertOk();

        $slot->refresh();
        $this->assertSame(AiCampaignSlot::STATUS_PENDING, $slot->status);
        $this->assertNull($slot->error);
        $this->assertSame(AiCampaignSlotTarget::STATUS_PENDING, $slot->targets()->first()->status);

        $this->asShopUser($owner, $business)
            ->postJson('/api/agent/campaigns/'.$campaign->id.'/slots/'.$slot->id.'/retry')
            ->assertUnprocessable();
    }

    public function test_detail_exposes_targets_progress_and_spend(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $campaign = $this->launch($owner, $business, [$this->connect($business, 'acc_fb', 'facebook')->id]);
        $this->fakeMcpAndLlm();
        $this->processAll($campaign);
        $this->acceptAll($campaign);

        $this->asShopUser($owner, $business)
            ->getJson('/api/agent/campaigns/'.$campaign->id)
            ->assertOk()
            ->assertJsonPath('campaign.progress.total', 2)
            ->assertJsonPath('campaign.progress.scheduled', 2)
            ->assertJsonPath('campaign.slots.0.targets.0.platform', 'facebook')
            ->assertJsonPath('campaign.slots.0.targets.0.status', 'scheduled')
            ->assertJsonStructure(['campaign' => ['estimated_da', 'spent_da', 'warnings', 'slots' => [['cost_da', 'attempts', 'image_url']]]]);
    }

    public function test_queue_health_reports_a_stale_scheduler(): void
    {
        Cache::forget('ops.scheduler_heartbeat');
        $this->getJson('/api/health/queue')
            ->assertStatus(503)
            ->assertJsonPath('ok', false)
            ->assertJsonFragment(['problems' => ['scheduler_stale']]);

        Cache::put('ops.scheduler_heartbeat', now()->toIso8601String(), 3600);
        $this->getJson('/api/health/queue')
            ->assertOk()
            ->assertJsonPath('ok', true);
    }

    private function launch(User $owner, Business $business, array $channelIds): AiCampaign
    {
        $owner->update(['wallet_balance_da' => 20000]);
        Storage::fake('public');
        $asset = $this->asset($business);
        Bus::fake();
        $campaign = app(AiCampaignService::class)->launch($business, $owner, $this->payload($channelIds, $asset->id));
        $this->enableMcp();

        return $campaign->fresh();
    }

    private function processAll(AiCampaign $campaign): void
    {
        $service = app(AiCampaignService::class);
        foreach ($campaign->slots()->orderBy('id')->pluck('id') as $id) {
            $service->processSlot((int) $id);
        }
    }

    private function acceptAll(AiCampaign $campaign): void
    {
        $approvals = app(\App\Services\Campaigns\CampaignSlotApprovalService::class);
        foreach ($campaign->slots()->orderBy('id')->get() as $slot) {
            if ($slot->fresh()->status === AiCampaignSlot::STATUS_AWAITING_APPROVAL) {
                $approvals->accept($slot->fresh());
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $channelIds, int $assetId): array
    {
        return [
            'name' => 'Launch week',
            'channel_ids' => $channelIds,
            'day_count' => 1,
            'days' => [['day_index' => 1, 'posts' => 1, 'stories' => 1, 'times' => ['11:00']]],
            'content_mode' => 'product_images',
            'focus_prompt' => null,
            'asset_ids' => [$assetId],
        ];
    }

    private function asset(Business $business): AgentAsset
    {
        Storage::fake('public');
        $path = 'agent-assets/'.$business->id.'/product.jpg';
        Storage::disk('public')->put($path, 'fake-image-bytes');

        return AgentAsset::query()->create([
            'business_id' => $business->id,
            'agent_id' => $business->agent?->id,
            'disk' => 'public',
            'path' => $path,
            'original_name' => 'product.jpg',
            'mime' => 'image/jpeg',
            'size' => 16,
        ]);
    }

    private function connect(Business $business, string $remoteId, string $platform): SocialAccount
    {
        return SocialAccount::query()->create([
            'business_id' => $business->id,
            'provider' => 'socialapi',
            'socialapi_account_id' => $remoteId,
            'platform' => $platform,
            'name' => ucfirst($platform).' page',
            'status' => 'connected',
            'connected_at' => now(),
        ]);
    }

    private function enableMcp(): void
    {
        config([
            'services.socialapi.mcp_enabled' => true,
            'services.socialapi.key' => 'test-key',
            'services.socialapi.mcp_url' => 'https://api.social-api.ai/mcp',
            'services.fal.key' => 'fal-test-key',
        ]);
        Cache::forget('socialapi.mcp.tools');
    }

    private function fakeMcpAndLlm(bool $liveSchema = false): void
    {
        $this->createPostArgs = [];
        $this->deleted = [];

        Http::fake(function (Request $request) use ($liveSchema) {
            $url = $request->url();
            if (str_contains($url, 'fal.run')) {
                return Http::response([
                    'choices' => [['message' => [
                        'content' => '{"title":"Weekend shop tease","caption":"Sample caption for the shop","hashtags":["#Wasl"],"image_prompt":"Product on a clean table"}',
                    ]]],
                    'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 8, 'cost' => 0.001],
                ]);
            }

            $data = $request->data();
            $method = $data['method'] ?? '';
            if ($method === 'tools/list') {
                if (! $liveSchema) {
                    return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'error' => ['message' => 'no schema']]);
                }

                return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['tools' => [
                    ['name' => 'social_api_list_posts', 'inputSchema' => ['type' => 'object', 'properties' => ['account_ids' => ['type' => 'string'], 'limit' => ['type' => 'integer']]]],
                    ['name' => 'upload_media', 'inputSchema' => ['type' => 'object', 'properties' => ['url' => ['type' => 'string'], 'filename' => ['type' => 'string']]]],
                    ['name' => 'social_api_delete_post', 'inputSchema' => ['type' => 'object', 'properties' => ['post_id' => ['type' => 'string']]]],
                    ['name' => 'social_api_create_post', 'inputSchema' => ['type' => 'object', 'properties' => [
                        'text' => ['type' => 'string'],
                        'scheduled_at' => ['type' => 'string'],
                        'account_ids' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'media_ids' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'idempotency_key' => ['type' => 'string'],
                        'post_type' => ['type' => 'string', 'enum' => ['feed', 'story', 'reel']],
                    ]]],
                ]]]);
            }

            $name = $data['params']['name'] ?? '';
            $args = $data['params']['arguments'] ?? [];
            if (in_array($name, ['create_post', 'social_api_create_post'], true)) {
                $this->createPostArgs[] = $args;
            }
            if (in_array($name, ['delete_post', 'social_api_delete_post'], true)) {
                $this->deleted[] = (string) ($args['post_id'] ?? '');
            }
            $result = match ($name) {
                'list_posts', 'social_api_list_posts' => ['content' => [['text' => 'Recent post about delivery']]],
                'upload_media' => ['media_id' => 'med_1'],
                'create_post', 'social_api_create_post' => ['id' => 'post_77'],
                'delete_post', 'social_api_delete_post' => ['ok' => true],
                default => null,
            };

            return $result === null
                ? Http::response(['jsonrpc' => '2.0', 'id' => 1, 'error' => ['message' => 'unexpected '.$name]])
                : Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => $result]);
        });
    }
}
