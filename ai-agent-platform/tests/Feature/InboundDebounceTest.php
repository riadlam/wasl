<?php

namespace Tests\Feature;

use App\AI\Agents\BusinessAgent;
use App\Jobs\AI\ProcessIncomingMessageJob;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class InboundDebounceTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatch_for_delays_and_stamps_latest_message(): void
    {
        config(['ai_runtime.inbound_debounce_seconds' => 3]);
        Bus::fake();

        ['business' => $business] = $this->makeShop();
        $conversation = $this->makeConversation($business->id);
        $message = $this->makeInbound($business->id, $conversation->id, 'ok');

        ProcessIncomingMessageJob::dispatchFor($message);

        Bus::assertDispatched(ProcessIncomingMessageJob::class, function (ProcessIncomingMessageJob $job) use ($message) {
            return $job->messageId === $message->id && $job->delay !== null;
        });
        $this->assertSame($message->id, (int) Cache::get(ProcessIncomingMessageJob::latestKey($conversation->id)));
    }

    public function test_older_job_is_superseded_by_newer_inbound(): void
    {
        config(['ai_runtime.inbound_debounce_seconds' => 3]);

        ['business' => $business] = $this->makeShop();
        $conversation = $this->makeConversation($business->id);
        $first = $this->makeInbound($business->id, $conversation->id, 'ok');
        $second = $this->makeInbound($business->id, $conversation->id, 'thank you');

        Cache::put(ProcessIncomingMessageJob::latestKey($conversation->id), $second->id, now()->addMinutes(5));
        Cache::put(ProcessIncomingMessageJob::atKey($conversation->id), now()->subSeconds(5)->getTimestamp(), now()->addMinutes(5));

        $agent = Mockery::mock(BusinessAgent::class);
        $agent->shouldReceive('run')->never();
        $this->app->instance(BusinessAgent::class, $agent);

        (new ProcessIncomingMessageJob($first->id))->handle();
    }

    public function test_latest_job_runs_agent_after_quiet_window(): void
    {
        config(['ai_runtime.inbound_debounce_seconds' => 3]);

        ['business' => $business] = $this->makeShop();
        $conversation = $this->makeConversation($business->id);
        $first = $this->makeInbound($business->id, $conversation->id, 'ok');
        $second = $this->makeInbound($business->id, $conversation->id, 'thank you');

        Cache::put(ProcessIncomingMessageJob::latestKey($conversation->id), $second->id, now()->addMinutes(5));
        Cache::put(ProcessIncomingMessageJob::atKey($conversation->id), now()->subSeconds(5)->getTimestamp(), now()->addMinutes(5));

        $agent = Mockery::mock(BusinessAgent::class);
        $agent->shouldReceive('run')
            ->once()
            ->with(Mockery::on(fn ($message) => $message instanceof Message && (int) $message->id === (int) $second->id));
        $this->app->instance(BusinessAgent::class, $agent);

        (new ProcessIncomingMessageJob($first->id))->handle();
        (new ProcessIncomingMessageJob($second->id))->handle();
    }

    public function test_debounce_zero_runs_immediately_without_supersede(): void
    {
        config(['ai_runtime.inbound_debounce_seconds' => 0]);
        Bus::fake();

        ['business' => $business] = $this->makeShop();
        $conversation = $this->makeConversation($business->id);
        $message = $this->makeInbound($business->id, $conversation->id, 'hello');

        ProcessIncomingMessageJob::dispatchFor($message);

        Bus::assertDispatched(ProcessIncomingMessageJob::class, function (ProcessIncomingMessageJob $job) use ($message) {
            return $job->messageId === $message->id;
        });
    }

    private function makeConversation(int $businessId): Conversation
    {
        $account = \App\Models\SocialAccount::query()->create([
            'business_id' => $businessId,
            'platform' => 'facebook',
            'name' => 'Debounce Page',
            'status' => 'connected',
            'connected_at' => now(),
        ]);
        $customer = \App\Models\Customer::query()->create([
            'business_id' => $businessId,
            'name' => 'Debounce Client',
            'phone' => '0555000111',
        ]);

        return Conversation::query()->create([
            'business_id' => $businessId,
            'customer_id' => $customer->id,
            'social_account_id' => $account->id,
            'platform' => 'facebook',
            'status' => 'open',
            'ai_enabled' => true,
        ]);
    }

    private function makeInbound(int $businessId, int $conversationId, string $text): Message
    {
        return Message::query()->create([
            'business_id' => $businessId,
            'conversation_id' => $conversationId,
            'direction' => 'inbound',
            'type' => 'text',
            'sender_type' => 'customer',
            'text' => $text,
            'status' => 'received',
        ]);
    }
}
