<?php

namespace Tests\Feature;

use App\Jobs\Social\SendFixedDmReplyJob;
use App\Jobs\Social\SendSocialMessageJob;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\SocialAccount;
use App\Models\Workflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class DmKeywordWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_requires_complete_active_config(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();

        $this->asShopUser($owner, $business)
            ->postJson('/api/workflows/use', [
                'template_key' => 'dm_keyword',
                'activate' => false,
            ])
            ->assertStatus(422);

        $this->asShopUser($owner, $business)
            ->postJson('/api/workflows/use', [
                'template_key' => 'dm_keyword',
                'activate' => true,
                'name' => 'Price FAQ',
                'config' => [
                    'platforms' => ['instagram', 'whatsapp'],
                    'keywords' => ['price', 'prix'],
                    'steps' => [[
                        'type' => 'dm_reply',
                        'mode' => 'fixed',
                        'text' => 'Our prices start at 5000 DZD.',
                    ]],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('workflow.template_key', 'dm_keyword')
            ->assertJsonPath('workflow.kind', 'dm')
            ->assertJsonPath('workflow.status', 'active')
            ->assertJsonPath('workflow.name', 'Price FAQ')
            ->assertJsonPath('workflow.config.platforms.1', 'whatsapp')
            ->assertJsonPath('workflow.config.keywords.0', 'price');
    }

    public function test_save_requires_at_least_one_platform(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();

        $created = $this->asShopUser($owner, $business)
            ->postJson('/api/workflows/use', [
                'template_key' => 'dm_keyword',
                'activate' => true,
                'config' => [
                    'platforms' => ['instagram'],
                    'keywords' => ['price'],
                    'steps' => [[
                        'type' => 'dm_reply',
                        'mode' => 'fixed',
                        'text' => 'Hello',
                    ]],
                ],
            ])
            ->assertOk()
            ->json('workflow');

        $this->asShopUser($owner, $business)
            ->patchJson('/api/workflows/'.$created['id'], [
                'status' => 'active',
                'config' => [
                    'platforms' => [],
                    'keywords' => ['price'],
                    'steps' => [[
                        'type' => 'dm_reply',
                        'mode' => 'fixed',
                        'text' => 'Hello',
                    ]],
                ],
            ])
            ->assertStatus(422);
    }

    public function test_activate_requires_keywords(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();

        $this->asShopUser($owner, $business)
            ->postJson('/api/workflows/use', [
                'template_key' => 'dm_keyword',
                'activate' => true,
                'config' => [
                    'platforms' => ['instagram'],
                    'keywords' => [],
                    'steps' => [[
                        'type' => 'dm_reply',
                        'mode' => 'fixed',
                        'text' => 'Hello',
                    ]],
                ],
            ])
            ->assertStatus(422);
    }

    public function test_rejects_draft_status_on_update(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();

        $created = $this->asShopUser($owner, $business)
            ->postJson('/api/workflows/use', [
                'template_key' => 'dm_keyword',
                'activate' => true,
                'config' => [
                    'platforms' => ['instagram'],
                    'keywords' => ['price'],
                    'steps' => [[
                        'type' => 'dm_reply',
                        'mode' => 'fixed',
                        'text' => 'Hello',
                    ]],
                ],
            ])
            ->assertOk()
            ->json('workflow');

        $this->asShopUser($owner, $business)
            ->patchJson('/api/workflows/'.$created['id'], [
                'status' => 'draft',
            ])
            ->assertStatus(422);
    }

    public function test_fixed_dm_reply_job_sends_stored_text(): void
    {
        Bus::fake([SendSocialMessageJob::class]);
        ['business' => $business] = $this->makeShop();
        $account = SocialAccount::query()->create([
            'business_id' => $business->id,
            'provider' => 'socialapi',
            'socialapi_account_id' => 'acc_ig_1',
            'platform' => 'instagram',
            'name' => 'Shop IG',
            'status' => 'connected',
            'connected_at' => now(),
        ]);
        $customer = Customer::query()->create([
            'business_id' => $business->id,
            'name' => 'Buyer',
            'platform' => 'instagram',
        ]);
        $conversation = Conversation::query()->create([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'social_account_id' => $account->id,
            'platform' => 'instagram',
            'type' => 'dm',
            'ai_enabled' => true,
            'status' => 'open',
            'socialapi_conversation_id' => 'conv_1',
        ]);

        $workflow = Workflow::query()->create([
            'business_id' => $business->id,
            'template_key' => 'dm_keyword',
            'kind' => 'dm',
            'name' => 'Price FAQ',
            'status' => 'active',
            'config' => [
                'platform' => 'instagram',
                'match' => 'contains',
                'keywords' => ['price'],
                'steps' => [[
                    'type' => 'dm_reply',
                    'mode' => 'fixed',
                    'text' => 'Prices start at 5000.',
                    'image_path' => null,
                ]],
            ],
        ]);

        $inbound = Message::query()->create([
            'business_id' => $business->id,
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'direction' => 'inbound',
            'type' => 'text',
            'sender_type' => 'customer',
            'sender_id' => $customer->id,
            'text' => 'What is the price?',
            'status' => 'received',
        ]);

        (new SendFixedDmReplyJob($inbound->id, $workflow->id))
            ->handle(app(\App\Services\ConversationService::class));

        $outbound = Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', 'outbound')
            ->first();

        $this->assertNotNull($outbound);
        $this->assertSame('Prices start at 5000.', $outbound->text);
        Bus::assertDispatched(SendSocialMessageJob::class);
    }

    public function test_matcher_finds_keyword_workflow(): void
    {
        ['business' => $business] = $this->makeShop();

        Workflow::query()->create([
            'business_id' => $business->id,
            'template_key' => 'dm_keyword',
            'kind' => 'dm',
            'name' => 'Price FAQ',
            'status' => 'active',
            'config' => [
                'platforms' => ['instagram', 'facebook'],
                'platform' => 'instagram',
                'keywords' => ['prix'],
                'steps' => [[
                    'type' => 'dm_reply',
                    'mode' => 'fixed',
                    'text' => 'Bonjour',
                ]],
            ],
        ]);

        $matched = app(\App\Services\WorkflowService::class)
            ->findActiveDmKeywordWorkflow($business, 'facebook', 'Quel est le prix ?');
        $missedPlatform = app(\App\Services\WorkflowService::class)
            ->findActiveDmKeywordWorkflow($business, 'whatsapp', 'Quel est le prix ?');
        $missed = app(\App\Services\WorkflowService::class)
            ->findActiveDmKeywordWorkflow($business, 'instagram', 'Hello there');

        $this->assertNotNull($matched);
        $this->assertNull($missedPlatform);
        $this->assertNull($missed);
    }
}
