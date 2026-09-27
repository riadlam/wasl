<?php

namespace Tests\Unit\AI\Runtime;

use App\AI\Runtime\SkAgentClient;
use App\Models\AiProfilePerChannel;
use App\Models\Business;
use App\Models\SocialAccount;
use App\Services\ProfileInterviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IdentityIngestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'ai_runtime.url' => 'http://runtime.test',
            'ai_runtime.service_key' => 'secret',
            'ai_runtime.timeout' => 5,
        ]);
    }

    public function test_interview_answer_ingests_into_knowledge_endpoint(): void
    {
        ['business' => $business] = $this->makeShop();
        $account = SocialAccount::query()->create([
            'business_id' => $business->id,
            'provider' => 'socialapi',
            'socialapi_account_id' => 'acc_ingest',
            'platform' => 'facebook',
            'name' => 'Ingest Page',
            'status' => 'connected',
            'connected_at' => now(),
        ]);
        AiProfilePerChannel::query()->create([
            'business_id' => $business->id,
            'social_account_id' => $account->id,
            'platform' => 'facebook',
            'profile' => [
                'storage' => 'supabase',
                'summary' => 'thin',
                'chunks_upserted' => 2,
                'interview_answers' => [],
            ],
            'model' => 'test',
            'source_counts' => [],
            'status' => AiProfilePerChannel::STATUS_READY,
            'generated_at' => now(),
        ]);

        Http::fake([
            'runtime.test/v1/knowledge/ingest' => Http::response(['ok' => true, 'chunks_upserted' => 1], 200),
        ]);

        $owner = \App\Models\User::query()->where('email', 'owner@example.test')->first();
        $service = app(ProfileInterviewService::class);
        $started = $service->start($business, $owner);
        $path = $started['interview']['questions'][0]['field_path'];
        $service->record($business, 0, 'We deliver nationwide');

        Http::assertSent(function ($request) use ($business, $path) {
            $data = $request->data();

            return str_contains($request->url(), '/v1/knowledge/ingest')
                && (int) ($data['business_id'] ?? 0) === (int) $business->id
                && ($data['source_type'] ?? null) === 'profile_interview'
                && str_contains((string) ($data['source_id'] ?? ''), $path)
                && str_contains((string) ($data['content'] ?? ''), 'We deliver nationwide');
        });

        $row = AiProfilePerChannel::query()->where('social_account_id', $account->id)->first();
        $this->assertSame('We deliver nationwide', $row->profile['interview_answers'][$path] ?? null);
        $this->assertArrayNotHasKey('operations', $row->profile);
    }

    public function test_search_knowledge_scopes_request_to_business(): void
    {
        Http::fake([
            'runtime.test/v1/knowledge/search' => Http::response([
                'ok' => true,
                'hits' => [
                    ['content' => 'brand note', 'namespace' => 'brand', 'score' => 0.9],
                ],
            ], 200),
        ]);

        $business = new Business;
        $business->id = 42;

        $client = new SkAgentClient;
        $result = $client->searchKnowledge($business, 'tone', 'brand', 4);

        $this->assertTrue($result['ok']);
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/v1/knowledge/search')
                && ($request->header('X-Business-Id')[0] ?? null) === '42'
                && ($request->data()['namespace'] ?? null) === 'brand';
        });
    }
}
