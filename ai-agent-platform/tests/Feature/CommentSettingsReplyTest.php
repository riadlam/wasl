<?php

namespace Tests\Feature;

use App\Jobs\AI\ProcessIncomingMessageJob;
use App\Jobs\Social\SendPrivateReplyJob;
use App\Models\Message;
use App\Models\SocialAccount;
use App\Services\PostCommentWorkflowRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class CommentSettingsReplyTest extends TestCase
{
    use RefreshDatabase;

    public function test_comment_dispatches_agent_without_workflow_when_settings_on(): void
    {
        Bus::fake([ProcessIncomingMessageJob::class, SendPrivateReplyJob::class]);

        ['business' => $business] = $this->makeShop();
        $business->agent?->update([
            'ai_enabled' => true,
            'auto_reply_comments' => true,
        ]);
        $account = SocialAccount::query()->create([
            'business_id' => $business->id,
            'provider' => 'socialapi',
            'socialapi_account_id' => 'acc_fb',
            'platform' => 'facebook',
            'name' => 'Shop',
            'status' => 'connected',
            'connected_at' => now(),
        ]);

        $conversation = \App\Models\Conversation::query()->create([
            'business_id' => $business->id,
            'social_account_id' => $account->id,
            'customer_id' => \App\Models\Customer::query()->create([
                'business_id' => $business->id,
                'platform' => 'facebook',
                'external_id' => 'u1',
                'name' => 'Buyer',
            ])->id,
            'platform' => 'facebook',
            'external_thread_id' => 't1',
            'ai_enabled' => true,
            'status' => 'open',
        ]);

        $inbound = Message::query()->create([
            'business_id' => $business->id,
            'conversation_id' => $conversation->id,
            'customer_id' => $conversation->customer_id,
            'direction' => 'inbound',
            'type' => 'comment',
            'sender_type' => 'customer',
            'sender_id' => $conversation->customer_id,
            'text' => 'مزال متوفر؟',
            'status' => 'received',
            'metadata' => [
                'post_id' => 'post_abc',
                'id' => 'comment_abc',
            ],
        ]);

        app(PostCommentWorkflowRunner::class)->dispatchForInbound(
            $inbound,
            $business->fresh('agent'),
            (int) $account->id,
            'post_abc',
            true,
            true,
            true,
            false,
            false,
        );

        Bus::assertDispatched(ProcessIncomingMessageJob::class);
        Bus::assertDispatched(SendPrivateReplyJob::class);
    }

    public function test_comment_skipped_when_auto_reply_comments_off(): void
    {
        Bus::fake([ProcessIncomingMessageJob::class, SendPrivateReplyJob::class]);

        ['business' => $business] = $this->makeShop();
        $business->agent?->update([
            'ai_enabled' => true,
            'auto_reply_comments' => false,
        ]);
        $account = SocialAccount::query()->create([
            'business_id' => $business->id,
            'provider' => 'socialapi',
            'socialapi_account_id' => 'acc_fb2',
            'platform' => 'facebook',
            'name' => 'Shop',
            'status' => 'connected',
            'connected_at' => now(),
        ]);

        $conversation = \App\Models\Conversation::query()->create([
            'business_id' => $business->id,
            'social_account_id' => $account->id,
            'customer_id' => \App\Models\Customer::query()->create([
                'business_id' => $business->id,
                'platform' => 'facebook',
                'external_id' => 'u2',
                'name' => 'Buyer',
            ])->id,
            'platform' => 'facebook',
            'external_thread_id' => 't2',
            'ai_enabled' => true,
            'status' => 'open',
        ]);

        $inbound = Message::query()->create([
            'business_id' => $business->id,
            'conversation_id' => $conversation->id,
            'customer_id' => $conversation->customer_id,
            'direction' => 'inbound',
            'type' => 'comment',
            'sender_type' => 'customer',
            'sender_id' => $conversation->customer_id,
            'text' => 'hi',
            'status' => 'received',
            'metadata' => ['post_id' => 'post_x', 'id' => 'c_x'],
        ]);

        app(PostCommentWorkflowRunner::class)->dispatchForInbound(
            $inbound,
            $business->fresh('agent'),
            (int) $account->id,
            'post_x',
            true,
            true,
            true,
            false,
            false,
        );

        Bus::assertNotDispatched(ProcessIncomingMessageJob::class);
        Bus::assertNotDispatched(SendPrivateReplyJob::class);
    }
}
