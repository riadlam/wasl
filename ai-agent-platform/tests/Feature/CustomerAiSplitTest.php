<?php

namespace Tests\Feature;

use App\AI\Agents\BusinessAgent;
use App\AI\Skills\ReplyGroundingGuard;
use App\Models\Customer;
use App\Models\CustomerAiSetting;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CustomerAiSplitTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_default_to_inheriting_the_shop(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();

        $this->asShopUser($owner, $business)
            ->getJson('/api/customer-ai-settings')
            ->assertOk()
            ->assertJsonPath('settings.language', null)
            ->assertJsonPath('settings.llm_model', null)
            ->assertJsonPath('settings.response_length', $business->agentSettings->response_length ?: 'short')
            ->assertJsonPath('settings.emoji_policy', 'light')
            ->assertJsonStructure(['inherits' => ['language', 'tone'], 'llm_models']);
    }

    public function test_update_writes_customer_settings_and_reply_toggles_through(): void
    {
        ['owner' => $owner, 'staff' => $staff, 'business' => $business] = $this->makeShop();

        $this->asShopUser($staff, $business)
            ->putJson('/api/customer-ai-settings', ['language' => 'French'])
            ->assertForbidden();

        $this->asShopUser($owner, $business)
            ->putJson('/api/customer-ai-settings', ['llm_model' => 'not-a-model'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['llm_model']);

        $this->asShopUser($owner, $business)
            ->putJson('/api/customer-ai-settings', [
                'language' => 'French',
                'emoji_policy' => 'none',
                'max_reply_chars' => 300,
                'handoff_keywords' => ['avocat', ' Remboursement '],
                'reply_dms' => false,
                'allow_order_creation' => false,
            ])
            ->assertOk()
            ->assertJsonPath('settings.language', 'French')
            ->assertJsonPath('settings.emoji_policy', 'none')
            ->assertJsonPath('settings.reply_dms', false)
            ->assertJsonPath('settings.allow_order_creation', false);

        $business->refresh()->load(['agent', 'agentSettings']);
        $this->assertFalse((bool) $business->agent->auto_reply_dms);
        $this->assertFalse((bool) $business->agentSettings->allow_order_creation);
        $settings = CustomerAiSetting::forBusiness($business);
        $this->assertSame(300, (int) $settings->max_reply_chars);
        $this->assertSame('Remboursement', $settings->matchesHandoffKeyword('Je veux un REMBOURSEMENT svp'));
    }

    public function test_handoff_keyword_hands_off_without_calling_the_llm(): void
    {
        ['business' => $business] = $this->makeShop();
        CustomerAiSetting::forBusiness($business)->update(['handoff_keywords' => ['avocat']]);
        $inbound = $this->simulatorMessage($business, 'Je vais appeler mon avocat');
        config(['services.fal.key' => 'fal-test']);
        Http::fake();

        $run = app(BusinessAgent::class)->run($inbound);

        $this->assertSame('handed_off', $run->status);
        $this->assertSame('keyword:avocat', $run->metadata['rule'] ?? null);
        $this->assertFalse((bool) $inbound->conversation->fresh()->ai_enabled);
        Http::assertNothingSent();
    }

    public function test_customer_ai_uses_its_own_model_and_reply_limit(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $owner->update(['wallet_balance_da' => 200]);
        config([
            'services.fal.key' => 'fal-test',
            'ai_runtime.driver' => 'php',
            'ai_llm_models.models.test_cheap' => ['label' => 'Cheap', 'model' => 'vendor/cheap-model', 'price_usd' => 0.001],
        ]);
        CustomerAiSetting::forBusiness($business)->update(['llm_model' => 'test_cheap', 'max_reply_chars' => 120]);
        $inbound = $this->simulatorMessage($business, 'Salam, do you have sneakers?');
        $long = 'Yes we have the Air Max sneakers in stock. '.str_repeat('They are comfortable and light for daily wear. ', 8);

        Http::fake(function (Request $request) use ($long) {
            $payload = $request->data();
            $system = (string) (($payload['messages'][0]['content'] ?? '') ?: '');
            if (str_contains($system, 'CustomerApprover')) {
                return Http::response([
                    'choices' => [['message' => ['content' => '{"decision":"approved","score":1,"reasons":[],"feedback":""}']]],
                    'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 5, 'cost' => 0.0001],
                ]);
            }

            return Http::response([
                'choices' => [['message' => ['content' => $long]]],
                'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 30, 'cost' => 0.0001],
            ]);
        });

        $run = app(BusinessAgent::class)->run($inbound);

        $this->assertSame('vendor/cheap-model', $run->model);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'chat/completions') && ($r->data()['model'] ?? null) === 'vendor/cheap-model');
        $reply = (string) ($run->metadata['reply'] ?? '');
        $this->assertNotSame('', $reply);
        $this->assertLessThanOrEqual(120, mb_strlen($reply));
    }

    public function test_grounding_guard_normalises_digits_and_allows_sums(): void
    {
        $guard = new ReplyGroundingGuard();
        $sources = ['{"price":8500,"fee":600}'];

        $this->assertSame([], $guard->inventedNumbers('السعر ٨٥٠٠ دج', $sources));
        $this->assertSame([], $guard->inventedNumbers('Prix 8 500 DA, livraison 600', $sources));
        $this->assertSame([], $guard->inventedNumbers('Total 9100 DA', $sources));
        $this->assertSame([], $guard->inventedNumbers('8500.00 DA', $sources));
        $this->assertNotSame([], $guard->inventedNumbers('Promo 7000 DA', $sources));
    }

    private function simulatorMessage($business, string $text): Message
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
            'type' => 'text',
            'sender_type' => 'customer',
            'sender_id' => $customer->id,
            'text' => $text,
            'status' => 'received',
        ]);
    }
}
