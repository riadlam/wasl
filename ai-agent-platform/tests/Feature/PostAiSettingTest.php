<?php

namespace Tests\Feature;

use App\Models\PostAiSetting;
use App\Models\SocialAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Legacy post AI settings API still exists for image uploads used by workflows.
 */
class PostAiSettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_patch_saves_modes_and_payloads(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $account = $this->connectAccount($business);

        $this->asShopUser($owner, $business)
            ->patchJson('/api/posts/ai-settings', [
                'items' => [[
                    'account_id' => $account->id,
                    'platform_post_id' => 'post_123',
                    'ai_comment_reply' => true,
                    'ai_private_reply' => true,
                    'comment_mode' => 'fixed',
                    'fixed_comment_text' => 'Thanks for the love!',
                    'dm_mode' => 'fixed',
                    'fixed_dm_text' => 'Hi — we DMed you.',
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('settings.0.ai_comment_reply', true)
            ->assertJsonPath('settings.0.comment_mode', 'fixed');

        $this->assertDatabaseHas('post_ai_settings', [
            'business_id' => $business->id,
            'platform_post_id' => 'post_123',
            'comment_mode' => 'fixed',
        ]);
    }

    public function test_upload_image_returns_path_and_url(): void
    {
        Storage::fake('public');
        ['owner' => $owner, 'business' => $business] = $this->makeShop();

        $response = $this->asShopUser($owner, $business)
            ->post('/api/posts/ai-settings/image', [
                'image' => UploadedFile::fake()->image('reply.jpg', 200, 200),
            ])
            ->assertOk();

        $path = $response->json('path');
        $this->assertNotEmpty($path);
        Storage::disk('public')->assertExists($path);
    }

    private function connectAccount($business): SocialAccount
    {
        return SocialAccount::query()->create([
            'business_id' => $business->id,
            'provider' => 'socialapi',
            'socialapi_account_id' => 'acc_ig_1',
            'platform' => 'instagram',
            'name' => 'Shop IG',
            'status' => 'connected',
            'connected_at' => now(),
        ]);
    }
}
