<?php

namespace Tests\Feature;

use App\AI\Tools\Owner\RecordProfileAnswer;
use App\Models\AiProfilePerChannel;
use App\Models\ChannelProfileInterview;
use App\Models\SocialAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProfileInterviewTest extends TestCase
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
        Http::fake([
            'runtime.test/v1/knowledge/ingest' => Http::response(['ok' => true, 'chunks_upserted' => 1], 200),
        ]);
    }

    public function test_complete_profile_hides_interview_card(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $this->seedProfile($business->id, $this->fullProfile());

        $this->asShopUser($owner, $business)
            ->getJson('/api/agent/profile-interview')
            ->assertOk()
            ->assertJsonPath('needed', false)
            ->assertJsonPath('interview', null);
    }

    public function test_start_creates_at_most_ten_questions(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $this->seedProfile($business->id, $this->thinProfile());

        $response = $this->asShopUser($owner, $business)
            ->postJson('/api/agent/profile-interview/start')
            ->assertOk()
            ->assertJsonPath('interview.status', 'active');

        $questions = $response->json('interview.questions');
        $this->assertIsArray($questions);
        $this->assertGreaterThan(0, count($questions));
        $this->assertLessThanOrEqual(10, count($questions));
        $this->assertSame(0, $response->json('interview.current_index'));
        $this->assertDatabaseHas('agent_chat_messages', [
            'business_id' => $business->id,
            'role' => 'assistant',
        ]);
    }

    public function test_record_rejects_wrong_index_and_merges_the_current_answer(): void
    {
        ['business' => $business] = $this->makeShop();
        $account = $this->seedProfile($business->id, $this->thinProfile());

        $service = app(\App\Services\ProfileInterviewService::class);
        $started = $service->start($business, \App\Models\User::query()->where('email', 'owner@example.test')->first());
        $this->assertSame('active', $started['interview']['status']);

        $tool = app(RecordProfileAnswer::class);
        $wrong = $tool->handle($business, ['question_index' => 3, 'answer' => 'CCP and BaridiMob']);
        $this->assertFalse($wrong['ok']);
        $this->assertSame(0, $wrong['current_index']);

        $firstPath = $started['interview']['questions'][0]['field_path'];
        $ok = $tool->handle($business, ['question_index' => 0, 'answer' => 'We ship to 58 wilayas']);
        $this->assertTrue($ok['ok']);
        $this->assertSame(1, $ok['current_index']);

        $profile = AiProfilePerChannel::query()->where('social_account_id', $account->id)->first();
        $this->assertSame('supabase', $profile->profile['storage'] ?? null);
        $this->assertSame('We ship to 58 wilayas', $profile->profile['interview_answers'][$firstPath] ?? null);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/knowledge/ingest')
            && str_contains((string) ($request->data()['content'] ?? ''), 'We ship to 58 wilayas')
            && ($request->data()['source_type'] ?? null) === 'profile_interview');

        $interview = ChannelProfileInterview::query()->where('business_id', $business->id)->first();
        $this->assertSame(ChannelProfileInterview::STATUS_ACTIVE, $interview->status);
        $this->assertSame(1, $interview->current_index);
    }

    public function test_abandon_frees_chat_and_a_new_start_is_allowed(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $this->seedProfile($business->id, $this->thinProfile());

        $this->asShopUser($owner, $business)
            ->postJson('/api/agent/profile-interview/start')
            ->assertOk();

        $this->asShopUser($owner, $business)
            ->postJson('/api/agent/profile-interview/abandon')
            ->assertOk()
            ->assertJsonPath('interview', null)
            ->assertJsonPath('needed', true);

        $this->assertDatabaseHas('channel_profile_interviews', [
            'business_id' => $business->id,
            'status' => ChannelProfileInterview::STATUS_ABANDONED,
        ]);

        $this->asShopUser($owner, $business)
            ->postJson('/api/agent/profile-interview/start')
            ->assertOk()
            ->assertJsonPath('interview.status', 'active');

        $this->assertSame(1, ChannelProfileInterview::query()
            ->where('business_id', $business->id)
            ->where('status', ChannelProfileInterview::STATUS_ACTIVE)
            ->count());
    }

    public function test_digital_skip_makes_stale_delivery_record_succeed(): void
    {
        ['business' => $business] = $this->makeShop();
        $this->seedProfile($business->id, [
            'version' => 2,
            'business' => [
                'display_name' => 'DiasZone',
                'offer_type' => 'digital',
                'what_we_sell' => 'online courses',
                'payment_signals' => [],
            ],
            'channel' => ['page_name' => 'DiasZone'],
            'catalog_signals' => [],
            'audience' => ['faqs' => []],
            'reply_guidance' => ['dm_patterns' => [], 'escalation_topics' => []],
            'operations' => ['delivery' => '', 'returns' => '', 'hours' => ''],
            'evidence' => ['gaps' => []],
        ]);

        $interview = ChannelProfileInterview::query()->create([
            'business_id' => $business->id,
            'social_account_id' => SocialAccount::query()->where('business_id', $business->id)->value('id'),
            'created_by' => \App\Models\User::query()->where('email', 'owner@example.test')->value('id'),
            'status' => ChannelProfileInterview::STATUS_ACTIVE,
            'questions' => [
                [
                    'id' => '1',
                    'field_path' => 'operations.delivery',
                    'question' => 'How does delivery work?',
                    'answer' => null,
                    'gap_label' => '',
                ],
                [
                    'id' => '2',
                    'field_path' => 'operations.hours',
                    'question' => 'What are your hours?',
                    'answer' => null,
                    'gap_label' => '',
                ],
            ],
            'current_index' => 0,
        ]);

        $tool = app(RecordProfileAnswer::class);
        // Agent still has index 0 from the previous turn; get_state already skipped delivery.
        app(\App\Services\ProfileInterviewService::class)->toolState($business);
        $result = $tool->handle($business, [
            'question_index' => 0,
            'answer' => 'oui',
        ]);

        $this->assertTrue($result['ok']);
        $interview->refresh();
        $this->assertSame('ماكانش توصيل مادي. منتج رقمي.', $interview->questions[0]['answer']);
        $this->assertStringNotContainsString('No physical delivery', $interview->questions[0]['answer']);
        $this->assertSame(1, (int) $interview->current_index);
        $this->assertStringContainsString('hours', strtolower($result['follow_up']));
    }

    public function test_completed_interview_clears_needed(): void
    {
        ['business' => $business] = $this->makeShop();
        $profile = $this->fullProfile();
        $profile['operations']['hours'] = '';
        $account = $this->seedProfile($business->id, $profile);

        ChannelProfileInterview::query()->create([
            'business_id' => $business->id,
            'social_account_id' => $account->id,
            'created_by' => \App\Models\User::query()->where('email', 'owner@example.test')->value('id'),
            'status' => ChannelProfileInterview::STATUS_ACTIVE,
            'questions' => [[
                'id' => '1',
                'field_path' => 'operations.hours',
                'question' => 'وقتاش تخدموا؟',
                'answer' => null,
                'gap_label' => '',
            ]],
            'current_index' => 0,
        ]);

        $service = app(\App\Services\ProfileInterviewService::class);
        $saved = $service->record($business, 0, '24/7');
        $this->assertTrue($saved['completed']);
        $this->assertSame(ChannelProfileInterview::STATUS_COMPLETED, ChannelProfileInterview::query()->where('business_id', $business->id)->value('status'));

        $state = $service->state($business->fresh());
        $this->assertFalse($state['needed']);
        $this->assertNull($state['interview']);
    }

    private function seedProfile(int $businessId, array $profile): SocialAccount
    {
        $account = SocialAccount::query()->create([
            'business_id' => $businessId,
            'provider' => 'socialapi',
            'socialapi_account_id' => 'acc_interview_'.$businessId,
            'platform' => 'facebook',
            'name' => 'Dias Zone',
            'username' => 'diaszone',
            'status' => 'connected',
            'connected_at' => now(),
        ]);

        AiProfilePerChannel::query()->create([
            'business_id' => $businessId,
            'social_account_id' => $account->id,
            'platform' => 'facebook',
            'profile' => $profile,
            'model' => 'test',
            'source_counts' => ['posts' => 1],
            'status' => AiProfilePerChannel::STATUS_READY,
            'generated_at' => now(),
        ]);

        return $account;
    }

    /**
     * @return array<string, mixed>
     */
    private function fullProfile(): array
    {
        return [
            'version' => 2,
            'business' => [
                'display_name' => 'Dias Zone',
                'payment_signals' => ['CCP'],
            ],
            'channel' => ['page_name' => 'Dias Zone'],
            'catalog_signals' => [[
                'name_or_category' => 'Shoes',
                'quoted_prices' => ['4500 DA'],
                'price_hints' => [],
            ]],
            'audience' => [
                'faqs' => [['q' => 'Size?', 'a_if_seen' => '40-45', 'evidence_ids' => []]],
            ],
            'reply_guidance' => [
                'dm_patterns' => ['Ask size then confirm'],
                'escalation_topics' => ['refund'],
            ],
            'operations' => [
                'delivery' => '58 wilayas',
                'returns' => '7 days',
                'hours' => '9-18',
            ],
            'evidence' => ['gaps' => []],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function thinProfile(): array
    {
        return [
            'version' => 2,
            'business' => [
                'display_name' => 'Dias Zone',
                'payment_signals' => [],
            ],
            'channel' => ['page_name' => 'Dias Zone'],
            'catalog_signals' => [],
            'audience' => ['faqs' => []],
            'reply_guidance' => [
                'dm_patterns' => [],
                'escalation_topics' => [],
            ],
            'operations' => [
                'delivery' => '',
                'returns' => '',
                'hours' => '',
            ],
            'evidence' => ['gaps' => ['Warranty unknown']],
        ];
    }
}
