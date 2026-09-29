<?php

namespace Tests\Unit\Services\Campaigns;

use App\Services\Campaigns\CampaignMcpGateway;
use App\Services\Campaigns\SocialApiMcpSchema;
use App\Services\SocialApi\SocialApiMcpClient;
use App\Services\SocialApi\SocialApiPostsService;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class CampaignMcpStoryScheduleTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_story_schedule_requires_media(): void
    {
        config(['services.socialapi.mcp_enabled' => true]);

        $mcp = Mockery::mock(SocialApiMcpClient::class);
        $mcp->shouldReceive('enabled')->andReturn(true);

        $gateway = new CampaignMcpGateway(
            $mcp,
            new SocialApiMcpSchema($mcp),
            Mockery::mock(SocialApiPostsService::class),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Stories require an image');
        $gateway->schedulePost(['acc_1'], 'Story caption', now()->toIso8601String(), [], true);
    }

    public function test_story_sets_content_type_stories_on_targets(): void
    {
        config([
            'services.socialapi.mcp_enabled' => true,
            'socialapi_mcp.campaign_tools.create_post' => 'create_post',
        ]);

        $captured = null;
        $mcp = Mockery::mock(SocialApiMcpClient::class);
        $mcp->shouldReceive('enabled')->andReturn(true);
        $mcp->shouldReceive('listTools')->andReturn([]);
        $mcp->shouldReceive('callTool')
            ->once()
            ->withArgs(function (string $tool, array $args) use (&$captured) {
                $captured = $args;

                return $tool === 'create_post';
            })
            ->andReturn(['id' => 'post_story_1']);

        $gateway = new CampaignMcpGateway(
            $mcp,
            new SocialApiMcpSchema($mcp),
            Mockery::mock(SocialApiPostsService::class),
        );

        $ids = $gateway->schedulePost(
            ['acc_ig'],
            'Quick story',
            now()->addHour()->toIso8601String(),
            ['med_9'],
            true,
            'wasl-slot-1-instagram-ok',
        );

        $this->assertSame(['acc_ig' => 'post_story_1'], $ids);
        $this->assertSame('stories', $captured['targets'][0]['platform_data']['content_type'] ?? null);
        $this->assertSame('acc_ig', $captured['targets'][0]['account_id'] ?? null);
        $this->assertArrayHasKey('media', $captured);
    }

    public function test_story_uses_live_socialapi_targets_schema(): void
    {
        config([
            'services.socialapi.mcp_enabled' => true,
            'socialapi_mcp.campaign_tools.create_post' => 'social_api_create_post',
        ]);

        $captured = null;
        $mcp = Mockery::mock(SocialApiMcpClient::class);
        $mcp->shouldReceive('enabled')->andReturn(true);
        $mcp->shouldReceive('listTools')->andReturn([
            [
                'name' => 'social_api_create_post',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'text' => ['type' => 'string'],
                        'scheduled_at' => ['type' => 'string'],
                        'targets' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'account_id' => ['type' => 'string'],
                                    'platform_data' => ['type' => 'object', 'additionalProperties' => true],
                                ],
                            ],
                        ],
                        'media' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'source_type' => ['type' => 'string'],
                                    'source' => ['type' => 'string'],
                                ],
                            ],
                        ],
                        'platform_data' => ['type' => 'object', 'additionalProperties' => true],
                    ],
                ],
            ],
        ]);
        $mcp->shouldReceive('callTool')
            ->once()
            ->withArgs(function (string $tool, array $args) use (&$captured) {
                $captured = $args;

                return $tool === 'social_api_create_post';
            })
            ->andReturn(['id' => 'post_story_2']);

        $gateway = new CampaignMcpGateway(
            $mcp,
            new SocialApiMcpSchema($mcp),
            Mockery::mock(SocialApiPostsService::class),
        );

        $gateway->schedulePost(
            ['acc_fb'],
            'FB story',
            now()->addHour()->toIso8601String(),
            ['med_2'],
            true,
            'key-fb',
        );

        $this->assertSame('stories', $captured['targets'][0]['platform_data']['content_type'] ?? null);
        $this->assertSame('acc_fb', $captured['targets'][0]['account_id'] ?? null);
        $this->assertSame(
            [['source_type' => 'media_id', 'source' => 'med_2']],
            $captured['media'] ?? null,
        );
    }
}
