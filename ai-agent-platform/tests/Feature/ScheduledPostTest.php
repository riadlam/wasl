<?php

namespace Tests\Feature;

use App\Models\SocialAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ScheduledPostTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_scheduled_posts_for_shop_accounts(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $this->connectAccount($business, 'acc_ig_1', 'instagram', 'Shop IG');

        Http::fake([
            'https://api.social-api.ai/v1/posts*' => Http::response([
                'data' => [[
                    'id' => 'p_sched_1',
                    'text' => 'Weekend drop',
                    'status' => 'scheduled',
                    'scheduled_at' => '2026-10-01T10:00:00Z',
                    'targets' => [
                        ['account_id' => 'acc_ig_1', 'platform' => 'instagram', 'status' => 'scheduled'],
                    ],
                ]],
                'pagination' => ['has_more' => false, 'next_cursor' => null],
            ]),
        ]);

        $this->asShopUser($owner, $business)
            ->getJson('/api/scheduled-posts')
            ->assertOk()
            ->assertJsonPath('posts.0.id', 'p_sched_1')
            ->assertJsonPath('posts.0.text', 'Weekend drop')
            ->assertJsonPath('posts.0.targets.0.account_name', 'Shop IG');
    }

    public function test_create_rejects_foreign_account_id(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $this->connectAccount($business, 'acc_ig_1', 'instagram', 'Shop IG');

        Http::fake();

        $this->asShopUser($owner, $business)
            ->postJson('/api/scheduled-posts', [
                'text' => 'Hello',
                'scheduled_at' => now()->addDay()->toIso8601String(),
                'account_ids' => [99999],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['account_ids']);

        Http::assertNothingSent();
    }

    public function test_create_schedules_via_socialapi(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $account = $this->connectAccount($business, 'acc_ig_1', 'instagram', 'Shop IG');

        Http::fake([
            'https://api.social-api.ai/v1/posts' => Http::response([
                'id' => 'p_new_1',
                'text' => 'Launching soon',
                'status' => 'scheduled',
                'scheduled_at' => '2026-10-02T09:00:00Z',
                'targets' => [
                    ['account_id' => 'acc_ig_1', 'platform' => 'instagram', 'status' => 'scheduled'],
                ],
            ], 201),
        ]);

        $this->asShopUser($owner, $business)
            ->postJson('/api/scheduled-posts', [
                'text' => 'Launching soon',
                'scheduled_at' => now()->addDays(2)->toIso8601String(),
                'account_ids' => [$account->id],
                'media_ids' => ['media-uuid-1'],
            ])
            ->assertCreated()
            ->assertJsonPath('post.id', 'p_new_1')
            ->assertJsonPath('post.status', 'scheduled');

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->method() === 'POST'
                && str_ends_with($request->url(), '/posts')
                && ($body['text'] ?? null) === 'Launching soon'
                && ($body['targets'][0]['account_id'] ?? null) === 'acc_ig_1'
                && ! empty($body['scheduled_at'])
                && ($body['media'][0]['source_type'] ?? null) === 'media_id'
                && ($body['media'][0]['source'] ?? null) === 'media-uuid-1';
        });
    }

    public function test_create_rejects_too_many_media_for_platform(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $account = $this->connectAccount($business, 'acc_yt_1', 'youtube', 'Shop YT');

        Http::fake();

        $this->asShopUser($owner, $business)
            ->postJson('/api/scheduled-posts', [
                'text' => 'Too many images',
                'scheduled_at' => now()->addDay()->toIso8601String(),
                'account_ids' => [$account->id],
                'media_ids' => ['m1', 'm2'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['media_ids']);

        Http::assertNothingSent();
    }

    public function test_upload_media_proxies_to_socialapi(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $this->connectAccount($business, 'acc_ig_1', 'instagram', 'Shop IG');

        Http::fake([
            'https://api.social-api.ai/v1/media/upload' => Http::response([
                'media_id' => 'uploaded-media-1',
            ], 201),
        ]);

        $file = \Illuminate\Http\UploadedFile::fake()->image('drop.jpg', 200, 200);

        $this->asShopUser($owner, $business)
            ->post('/api/scheduled-posts/media', ['image' => $file], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('media_id', 'uploaded-media-1');
    }

    public function test_limits_endpoint_returns_platform_caps(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();

        $this->asShopUser($owner, $business)
            ->getJson('/api/scheduled-posts/limits')
            ->assertOk()
            ->assertJsonPath('limits.instagram.max_media', 10)
            ->assertJsonPath('limits.youtube.max_media', 1);
    }

    public function test_delete_and_publish_require_owned_post(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $this->connectAccount($business, 'acc_ig_1', 'instagram', 'Shop IG');

        Http::fake([
            'https://api.social-api.ai/v1/posts/p_owned/publish' => Http::response([
                'id' => 'p_owned',
                'status' => 'published',
                'targets' => [['account_id' => 'acc_ig_1', 'platform' => 'instagram', 'status' => 'published']],
            ]),
            'https://api.social-api.ai/v1/posts/p_owned' => Http::sequence()
                ->push([
                    'id' => 'p_owned',
                    'status' => 'scheduled',
                    'targets' => [['account_id' => 'acc_ig_1', 'platform' => 'instagram']],
                ])
                ->push([], 200)
                ->push([
                    'id' => 'p_owned',
                    'status' => 'scheduled',
                    'targets' => [['account_id' => 'acc_ig_1', 'platform' => 'instagram']],
                ]),
        ]);

        $this->asShopUser($owner, $business)
            ->deleteJson('/api/scheduled-posts/p_owned')
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->asShopUser($owner, $business)
            ->postJson('/api/scheduled-posts/p_owned/publish')
            ->assertOk()
            ->assertJsonPath('post.status', 'published');
    }

    public function test_delete_rejects_foreign_post(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $this->connectAccount($business, 'acc_ig_1', 'instagram', 'Shop IG');

        Http::fake([
            'https://api.social-api.ai/v1/posts/p_other' => Http::response([
                'id' => 'p_other',
                'status' => 'scheduled',
                'targets' => [['account_id' => 'acc_someone_else', 'platform' => 'instagram']],
            ]),
        ]);

        $this->asShopUser($owner, $business)
            ->deleteJson('/api/scheduled-posts/p_other')
            ->assertStatus(422)
            ->assertJsonPath('message', 'This scheduled post does not belong to your shop.');
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
