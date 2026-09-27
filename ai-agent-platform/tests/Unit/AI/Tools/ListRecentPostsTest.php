<?php

namespace Tests\Unit\AI\Tools;

use App\AI\Tools\Owner\ListRecentPosts;
use App\Models\SocialAccount;
use App\Services\Campaigns\CampaignMcpGateway;
use App\Services\SocialApi\SocialApiPostsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ListRecentPostsTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_newest_five_by_published_at_not_sync_created_at(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        SocialAccount::query()->create([
            'business_id' => $business->id,
            'provider' => 'socialapi',
            'socialapi_account_id' => 'acc_99',
            'platform' => 'facebook',
            'name' => 'Page',
            'status' => 'connected',
            'connected_at' => now(),
        ]);

        $posts = Mockery::mock(SocialApiPostsService::class);
        $posts->shouldReceive('listPublishedPosts')
            ->once()
            ->with(['acc_99'], null, null, 40)
            ->andReturn(['data' => []]);
        $posts->shouldReceive('normalizeList')
            ->once()
            ->andReturn([
                'posts' => [
                    [
                        'caption' => 'old post Dec',
                        'published_at' => '2025-12-23T18:59:34Z',
                        'platform' => 'facebook',
                        'permalink' => 'https://fb/1',
                    ],
                    [
                        'caption' => 'weekly pass diamond MLBB',
                        'published_at' => '2026-09-20T12:00:00Z',
                        'platform' => 'facebook',
                        'permalink' => 'https://fb/2',
                    ],
                    [
                        'caption' => 'mid post',
                        'published_at' => '2026-01-02T21:51:33Z',
                        'platform' => 'facebook',
                        'permalink' => 'https://fb/3',
                    ],
                ],
                'pagination' => ['has_more' => false, 'next_cursor' => null],
            ]);

        $tool = new ListRecentPosts($posts, Mockery::mock(CampaignMcpGateway::class));
        $result = $tool->handle($business, ['limit' => 5], ['user_id' => $owner->id]);

        $this->assertTrue($result['ok']);
        $this->assertSame(5, $result['limit']);
        $this->assertSame(3, $result['count']);
        $this->assertSame('published_at_desc', $result['sorted_by']);
        $this->assertSame('rest', $result['source']);
        $this->assertSame('weekly pass diamond MLBB', $result['posts'][0]['text']);
        $this->assertStringContainsString('weekly pass', $result['posts_text']);
    }

    public function test_defaults_limit_to_ten(): void
    {
        ['business' => $business] = $this->makeShop();
        SocialAccount::query()->create([
            'business_id' => $business->id,
            'provider' => 'socialapi',
            'socialapi_account_id' => 'acc_1',
            'platform' => 'instagram',
            'name' => 'IG',
            'status' => 'connected',
            'connected_at' => now(),
        ]);

        $posts = Mockery::mock(SocialApiPostsService::class);
        $posts->shouldReceive('listPublishedPosts')
            ->once()
            ->with(['acc_1'], null, null, 40)
            ->andReturn(['data' => []]);
        $posts->shouldReceive('normalizeList')
            ->once()
            ->andReturn(['posts' => [], 'pagination' => ['has_more' => false, 'next_cursor' => null]]);

        $tool = new ListRecentPosts($posts);
        $result = $tool->handle($business, []);

        $this->assertSame(10, $result['limit']);
        $this->assertSame(0, $result['count']);
    }
}
