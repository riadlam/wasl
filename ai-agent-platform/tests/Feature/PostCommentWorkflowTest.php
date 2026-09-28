<?php

namespace Tests\Feature;

use App\Jobs\Social\SendFixedCommentReplyJob;
use App\Jobs\Social\SendPrivateReplyJob;
use App\Jobs\Social\SendSocialMessageJob;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\SocialAccount;
use App\Models\Workflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PostCommentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_post_comment_workflow_with_posts(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $account = $this->connectAccount($business);

        $this->asShopUser($owner, $business)
            ->postJson('/api/workflows/use', [
                'template_key' => 'post_comment',
                'activate' => false,
                'posts' => [[
                    'account_id' => $account->id,
                    'platform_post_id' => 'ig_post_1',
                ]],
            ])
            ->assertStatus(422);

        $this->asShopUser($owner, $business)
            ->postJson('/api/workflows/use', [
                'template_key' => 'post_comment',
                'activate' => true,
                'name' => 'Post auto',
                'posts' => [[
                    'account_id' => $account->id,
                    'platform_post_id' => 'ig_post_1',
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('workflow.template_key', 'post_comment')
            ->assertJsonPath('workflow.kind', 'engagement')
            ->assertJsonPath('workflow.status', 'active')
            ->assertJsonPath('workflow.name', 'Post auto')
            ->assertJsonPath('workflow.posts.0.platform_post_id', 'ig_post_1');

        $this->assertDatabaseHas('workflow_posts', [
            'social_account_id' => $account->id,
            'platform_post_id' => 'ig_post_1',
        ]);
    }

    public function test_cannot_activate_two_workflows_for_same_post(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $account = $this->connectAccount($business);

        $first = $this->asShopUser($owner, $business)
            ->postJson('/api/workflows/use', [
                'template_key' => 'post_comment',
                'activate' => true,
                'posts' => [[
                    'account_id' => $account->id,
                    'platform_post_id' => 'ig_post_1',
                ]],
            ])
            ->assertOk()
            ->json('workflow');

        $this->asShopUser($owner, $business)
            ->postJson('/api/workflows/use', [
                'template_key' => 'post_comment',
                'activate' => true,
                'posts' => [[
                    'account_id' => $account->id,
                    'platform_post_id' => 'ig_post_1',
                ]],
            ])
            ->assertStatus(422);

        $this->assertSame('active', Workflow::query()->find($first['id'])->status);
    }

    public function test_fixed_comment_and_custom_dm_from_workflow(): void
    {
        Bus::fake([SendSocialMessageJob::class]);
        ['business' => $business] = $this->makeShop();
        $account = $this->connectAccount($business);
        $conversation = $this->makeCommentConversation($business, $account);

        $workflow = Workflow::query()->create([
            'business_id' => $business->id,
            'template_key' => 'post_comment',
            'kind' => 'engagement',
            'name' => 'Post auto',
            'status' => 'active',
            'config' => [
                'steps' => [
                    [
                        'type' => 'public_reply',
                        'enabled' => true,
                        'mode' => 'fixed',
                        'text' => 'Thanks from workflow',
                        'image_path' => null,
                    ],
                    [
                        'type' => 'private_dm',
                        'enabled' => true,
                        'mode' => 'fixed',
                        'text' => 'Custom DM from workflow',
                    ],
                ],
            ],
        ]);
        $workflow->posts()->create([
            'business_id' => $business->id,
            'social_account_id' => $account->id,
            'platform_post_id' => 'ig_post_1',
        ]);

        $inbound = Message::query()->create([
            'business_id' => $business->id,
            'conversation_id' => $conversation->id,
            'customer_id' => $conversation->customer_id,
            'direction' => 'inbound',
            'type' => 'comment',
            'sender_type' => 'customer',
            'sender_id' => $conversation->customer_id,
            'text' => 'Nice!',
            'status' => 'received',
            'metadata' => [
                'post_id' => 'ig_post_1',
                'id' => 'comment_99',
            ],
        ]);

        (new SendFixedCommentReplyJob($inbound->id, $workflow->id))
            ->handle(app(\App\Services\ConversationService::class));

        $outbound = Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', 'outbound')
            ->first();
        $this->assertNotNull($outbound);
        $this->assertSame('Thanks from workflow', $outbound->text);

        Http::fake([
            'https://api.social-api.ai/v1/inbox/comments/*' => Http::response(['success' => true]),
        ]);

        (new SendPrivateReplyJob($inbound->id, $workflow->id))->handle(
            app(\App\Services\ConversationService::class),
            app(\App\Services\SocialApi\SocialApiInboxService::class),
            app(\App\Services\Comments\CommentPrivateDmComposer::class),
        );

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/private-reply')
                && ($request['text'] ?? null) === 'Custom DM from workflow';
        });
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

    private function makeCommentConversation($business, SocialAccount $account): Conversation
    {
        $customer = Customer::query()->create([
            'business_id' => $business->id,
            'name' => 'Commenter',
            'platform' => 'instagram',
        ]);

        return Conversation::query()->create([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'social_account_id' => $account->id,
            'platform' => 'instagram',
            'type' => 'comment',
            'ai_enabled' => true,
            'status' => 'open',
        ]);
    }
}
