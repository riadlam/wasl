<?php

namespace Tests\Unit\AI\Runtime;

use App\AI\Runtime\SkAgentClient;
use App\Models\Business;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SkIdentityClientTest extends TestCase
{
    public function test_build_identity_posts_corpus_to_runtime(): void
    {
        config([
            'ai_runtime.url' => 'http://runtime.test',
            'ai_runtime.service_key' => 'secret',
            'ai_runtime.timeout' => 5,
        ]);

        Http::fake([
            'runtime.test/v1/identity/build' => Http::response([
                'ok' => true,
                'business_id' => 1,
                'social_account_id' => 9,
                'chunks_upserted' => 5,
                'namespaces' => ['brand', 'tone'],
                'summary' => 'Test shop',
                'storage' => 'supabase',
            ], 200),
        ]);

        $business = new Business;
        $business->id = 1;

        $client = new SkAgentClient;
        $result = $client->buildIdentity($business, 9, ['posts' => []], 'google/gemini-2.5-flash');

        $this->assertTrue($result['ok']);
        $this->assertSame(5, $result['chunks_upserted']);
        $this->assertSame('Test shop', $result['summary']);
        $this->assertSame('supabase', $result['storage']);
    }
}
