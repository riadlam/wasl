<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SecretLeakTest extends TestCase
{
    use RefreshDatabase;

    public function test_fal_key_is_never_in_json(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();

        Http::fake([
            'https://fal.run/*' => Http::response([
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => 'سلام'],
                ]],
            ]),
        ]);

        $response = $this->asShopUser($owner, $business)
            ->postJson('/api/conversations/simulate', [
                'name' => 'Ahmed',
                'text' => 'سلام',
            ]);

        $response->assertOk();
        $this->assertStringNotContainsString((string) config('services.fal.key'), $response->getContent());
        $this->assertStringNotContainsString('FAL_KEY', $response->getContent());
        $this->assertStringNotContainsString((string) config('services.socialapi.key'), $response->getContent());
        $this->assertStringNotContainsString('SOCAPI_KEY', $response->getContent());
    }
}
