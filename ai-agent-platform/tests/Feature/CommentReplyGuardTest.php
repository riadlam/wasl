<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\SocialAccount;
use App\Services\Comments\CommentReplyGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommentReplyGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_detects_page_self_comment_by_author_id_and_name(): void
    {
        ['business' => $business] = $this->makeShop();
        $account = SocialAccount::query()->create([
            'business_id' => $business->id,
            'provider' => 'socialapi',
            'socialapi_account_id' => 'acc_1',
            'platform' => 'facebook',
            'platform_account_id' => '899626169897322',
            'name' => 'Dias Zone',
            'status' => 'connected',
            'connected_at' => now(),
        ]);

        $guard = app(CommentReplyGuard::class);
        $payload = [
            'author' => ['id' => '899626169897322', 'name' => 'Dias Zone'],
            'post_id' => 'post_1',
            'platform_post_id' => '899626169897322_122143',
            'content' => ['text' => 'our own reply'],
        ];

        $this->assertTrue($guard->isPageSelfComment($account, $payload));
        $this->assertFalse($guard->shouldProcessInboundComment(
            $business,
            $account,
            $payload,
            'our own reply',
            'post_1',
            'sapi_cmt_1',
        ));
    }

    public function test_blocks_text_echo_of_recent_outbound_reply(): void
    {
        ['business' => $business] = $this->makeShop();
        $account = SocialAccount::query()->create([
            'business_id' => $business->id,
            'provider' => 'socialapi',
            'socialapi_account_id' => 'acc_2',
            'platform' => 'facebook',
            'platform_account_id' => 'page_other',
            'name' => 'Shop',
            'status' => 'connected',
            'connected_at' => now(),
        ]);
        $customer = \App\Models\Customer::query()->create([
            'business_id' => $business->id,
            'platform' => 'facebook',
            'external_id' => 'u1',
            'name' => 'Buyer',
        ]);
        $conversation = \App\Models\Conversation::query()->create([
            'business_id' => $business->id,
            'social_account_id' => $account->id,
            'customer_id' => $customer->id,
            'platform' => 'facebook',
            'ai_enabled' => true,
            'status' => 'open',
        ]);

        $text = 'أهلاً بك! نعم، عرض الـ Weekly Diamond Pass مازال متوفر';
        Message::query()->create([
            'business_id' => $business->id,
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'direction' => 'outbound',
            'type' => 'comment',
            'sender_type' => 'agent',
            'text' => $text,
            'ai_generated' => true,
            'status' => 'sent',
            'metadata' => ['kind' => 'comment_reply', 'post_id' => 'post_abc'],
        ]);

        $guard = app(CommentReplyGuard::class);
        $this->assertTrue($guard->matchesRecentOutboundReply($business, 'post_abc', $text));
        $this->assertFalse($guard->shouldProcessInboundComment(
            $business,
            $account,
            ['author' => ['id' => 'customer_9', 'name' => 'Someone'], 'post_id' => 'post_abc'],
            $text,
            'post_abc',
            'cmt_echo',
        ));
    }

    public function test_one_reply_per_comment_id(): void
    {
        ['business' => $business] = $this->makeShop();
        $guard = app(CommentReplyGuard::class);
        $this->assertFalse($guard->alreadyRepliedToComment($business->id, 'cmt_1'));
        $guard->markReplied($business->id, 'cmt_1');
        $this->assertTrue($guard->alreadyRepliedToComment($business->id, 'cmt_1'));
    }
}
