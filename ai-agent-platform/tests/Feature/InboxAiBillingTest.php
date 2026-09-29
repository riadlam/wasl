<?php

namespace Tests\Feature;

use App\AI\Agents\BusinessAgent;
use App\Models\AiTaskCharge;
use App\Models\Customer;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InboxAiBillingTest extends TestCase
{
    use RefreshDatabase;

    public function test_dm_reply_charges_owner_wallet_and_writes_ai_task_charge(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $owner->update(['wallet_balance_da' => 200]);
        config(['services.fal.key' => 'fal-test', 'ai_runtime.driver' => 'php']);

        $this->fakeFalWithApprover('Oui, on a des sneakers.', 0.02);

        $inbound = $this->simulatorMessage($business, 'Salam, sneakers?');
        $run = app(BusinessAgent::class)->run($inbound);

        $this->assertSame('completed', $run->status);

        $charge = AiTaskCharge::query()->latest('id')->first();
        $this->assertNotNull($charge);
        $this->assertSame(AiTaskCharge::TYPE_AGENT_DM_REPLY, $charge->task_type);
        $this->assertSame($business->id, $charge->business_id);
        $this->assertSame($owner->id, $charge->user_id);
        $this->assertSame(0.02, (float) $charge->cost_usd);
        $this->assertSame(5.0, (float) $charge->cost_da);
        $this->assertSame('dm', $charge->meta['surface'] ?? null);
        $this->assertSame(195.0, (float) $owner->fresh()->wallet_balance_da);
    }

    public function test_comment_reply_uses_comment_task_type(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $owner->update(['wallet_balance_da' => 200]);
        config(['services.fal.key' => 'fal-test', 'ai_runtime.driver' => 'php']);

        $this->fakeFalWithApprover('Merci pour le commentaire!', 0.01);

        $inbound = $this->simulatorMessage($business, 'Nice post!', 'comment');
        $run = app(BusinessAgent::class)->run($inbound);

        $this->assertSame('completed', $run->status);
        $charge = AiTaskCharge::query()->latest('id')->first();
        $this->assertSame(AiTaskCharge::TYPE_AGENT_COMMENT_REPLY, $charge->task_type);
        $this->assertSame('comment', $charge->meta['surface'] ?? null);
    }

    public function test_insufficient_wallet_skips_reply_without_fal_call(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $owner->update(['wallet_balance_da' => 0]);
        config(['services.fal.key' => 'fal-test', 'ai_runtime.driver' => 'php']);
        Http::fake();

        $inbound = $this->simulatorMessage($business, 'Salam');
        $run = app(BusinessAgent::class)->run($inbound);

        $this->assertSame('skipped_wallet', $run->status);
        $this->assertSame(0.0, (float) $owner->fresh()->wallet_balance_da);
        $this->assertDatabaseCount('ai_task_charges', 0);
        Http::assertNothingSent();
    }

    private function fakeFalWithApprover(string $reply, float $costUsd): void
    {
        Http::fake(function (Request $request) use ($reply, $costUsd) {
            $payload = $request->data();
            $system = (string) (($payload['messages'][0]['content'] ?? '') ?: '');
            if (str_contains($system, 'CustomerApprover')) {
                return Http::response([
                    'choices' => [['message' => ['content' => '{"decision":"approved","score":1,"reasons":[],"feedback":""}']]],
                    'usage' => [
                        'prompt_tokens' => 5,
                        'completion_tokens' => 5,
                        'cost' => 0,
                    ],
                ]);
            }

            return Http::response([
                'choices' => [['message' => ['content' => $reply]]],
                'usage' => [
                    'prompt_tokens' => 40,
                    'completion_tokens' => 12,
                    'cost' => $costUsd,
                ],
            ]);
        });
    }

    private function simulatorMessage($business, string $text, string $type = 'text'): Message
    {
        $customer = Customer::query()->create(['business_id' => $business->id, 'name' => 'Client']);
        $conversation = $business->conversations()->create([
            'social_account_id' => $business->simulatorAccount->id,
            'customer_id' => $customer->id,
            'platform' => 'simulator',
            'status' => 'open',
            'ai_enabled' => true,
        ]);

        return Message::query()->create([
            'business_id' => $business->id,
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'direction' => 'inbound',
            'type' => $type,
            'sender_type' => 'customer',
            'sender_id' => $customer->id,
            'text' => $text,
            'status' => 'received',
        ]);
    }
}
