<?php

namespace Tests\Feature;

use App\AI\Tools\Owner\GenerateImage;
use App\Models\SocialAccount;
use App\Services\Channels\ChannelLogoResolver;
use App\Services\Campaigns\CampaignCreativeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChannelLogoRequiredTest extends TestCase
{
    use RefreshDatabase;

    public function test_generate_image_requires_channel_logo(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();

        $result = app(GenerateImage::class)->handle($business, [
            'prompt' => 'A clean product photo of dates',
        ], [
            'user_id' => $owner->id,
        ]);

        $this->assertSame('channel.logo_required', $result['code'] ?? null);
        $this->assertStringContainsString('logo', strtolower((string) ($result['error'] ?? '')));
    }

    public function test_mandatory_prefix_includes_page_logo_url(): void
    {
        ['business' => $business] = $this->makeShop();

        SocialAccount::query()->create([
            'business_id' => $business->id,
            'provider' => 'socialapi',
            'socialapi_account_id' => 'acc_1',
            'platform' => 'facebook',
            'name' => 'Shop Page',
            'avatar_url' => 'https://cdn.example/logo.png',
            'status' => 'connected',
        ]);

        $prefix = app(ChannelLogoResolver::class)->mandatoryPromptPrefix($business, 'facebook');

        $this->assertNotNull($prefix);
        $this->assertStringContainsString('PAGE_LOGO (MANDATORY)', $prefix);
        $this->assertStringContainsString('https://cdn.example/logo.png', $prefix);
    }

    public function test_campaign_generate_image_fails_without_logo(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();

        $result = app(CampaignCreativeService::class)->generateImage(
            $business,
            $owner,
            'Promo image of the weekly pass',
            'facebook',
            'feed',
        );

        $this->assertNull($result['asset']);
        $this->assertStringContainsString('logo', strtolower((string) ($result['image_error'] ?? '')));
    }
}
