<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Services\OrderService;
use App\Services\ProductService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AgentPipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_products_tool_uses_service(): void
    {
        ['business' => $business] = $this->makeShop();

        $results = app(ProductService::class)->search($business, 'Air Max');

        $this->assertSame('Air Max sneakers', $results[0]['name']);
        $this->assertSame(8500.0, $results[0]['price']);
    }

    public function test_catalog_search_matches_tokenized_agent_query(): void
    {
        ['business' => $business] = $this->makeShop();
        $business->products()->create([
            'name' => 'monthly pass',
            'slug' => 'monthly-pass',
            'type' => 'digital',
            'price' => 1300,
            'stock' => 50,
            'status' => 'active',
        ]);
        $business->products()->create([
            'name' => 'Weekly Diamond Pass',
            'slug' => 'weekly-diamond-pass',
            'type' => 'digital',
            'price' => 401,
            'stock' => 50,
            'status' => 'active',
        ]);

        // Agent often appends category/brand — must still hit the real SKU for any shop.
        $payload = app(ProductService::class)->searchForAgent(
            $business,
            'Monthly Pass Mobile Legends'
        );

        $this->assertGreaterThan(0, $payload['count']);
        $this->assertSame('monthly pass', $payload['products'][0]['name']);
        $this->assertSame(1300.0, $payload['products'][0]['price']);
    }

    public function test_catalog_search_broadens_trailing_qualifiers_for_any_sku(): void
    {
        ['business' => $business] = $this->makeShop();
        $business->products()->create([
            'name' => 'Gold Card 1000',
            'slug' => 'gold-card-1000',
            'type' => 'digital',
            'price' => 2200,
            'stock' => 20,
            'status' => 'active',
        ]);

        $payload = app(ProductService::class)->searchForAgent(
            $business,
            'Gold Card 1000 special promo bundle offer'
        );

        $this->assertGreaterThan(0, $payload['count']);
        $this->assertSame('Gold Card 1000', $payload['products'][0]['name']);
        $this->assertContains($payload['matched_via'], ['direct', 'broadened']);
    }

    public function test_catalog_search_empty_returns_retry_hint_when_shop_has_skus(): void
    {
        ['business' => $business] = $this->makeShop();

        $payload = app(ProductService::class)->searchForAgent($business, 'zzzz-not-a-real-sku-qqq');

        $this->assertSame(0, $payload['count']);
        $this->assertSame('none', $payload['matched_via']);
        $this->assertNotEmpty($payload['hint'] ?? '');
        $this->assertGreaterThan(0, $payload['active_catalog_count']);
    }

    public function test_delivery_price_lookup(): void
    {
        ['business' => $business] = $this->makeShop();

        $result = app(\App\Services\DeliveryService::class)->lookup($business, 'Batna');

        $this->assertSame(600.0, $result['fee']);
        $this->assertSame('Batna', $result['wilaya']);
    }

    public function test_complaint_rule_disables_ai_without_llm(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();

        Http::fake();

        $simulate = $this->asShopUser($owner, $business)
            ->postJson('/api/conversations/simulate', [
                'name' => 'Ahmed',
                'phone' => '0555000111',
                'wilaya' => 'Oran',
                'text' => 'بغيت رهد complaint',
            ])
            ->assertOk();

        $inboundId = collect($simulate->json('conversation.messages'))->firstWhere('from', 'them')['id'] ?? null;
        $this->assertNotNull($inboundId);

        $this->asShopUser($owner, $business)
            ->getJson('/api/conversations/'.$simulate->json('conversation.id'))
            ->assertOk()
            ->assertJsonPath('conversation.human', true)
            ->assertJsonPath('conversation.ai_enabled', false);

        Http::assertNothingSent();
        $this->assertDatabaseHas('messages', [
            'direction' => 'outbound',
            'ai_generated' => true,
        ]);
    }

    public function test_simulate_inbound_runs_agent_with_faked_fal(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();

        Http::fake([
            'https://fal.run/*' => Http::sequence()
                ->push([
                    'choices' => [[
                        'message' => [
                            'role' => 'assistant',
                            'tool_calls' => [[
                                'id' => 'call_1',
                                'type' => 'function',
                                'function' => [
                                    'name' => 'search_products',
                                    'arguments' => '{"query":"sneakers"}',
                                ],
                            ]],
                        ],
                    ]],
                    'usage' => ['prompt_tokens' => 11, 'completion_tokens' => 6],
                ])
                ->push([
                    'choices' => [[
                        'message' => [
                            'role' => 'assistant',
                            'content' => 'نعم كاين، السعر 8500 دج وباقي 4 حبات.',
                        ],
                    ]],
                    'usage' => ['prompt_tokens' => 40, 'completion_tokens' => 18],
                ]),
        ]);

        $simulate = $this->asShopUser($owner, $business)
            ->postJson('/api/conversations/simulate', [
                'name' => 'Ahmed',
                'phone' => '0555999888',
                'wilaya' => 'Oran',
                'text' => 'سلام شحال هذي؟',
            ])
            ->assertOk();

        $inboundId = collect($simulate->json('conversation.messages'))->firstWhere('from', 'them')['id'] ?? null;
        $this->assertNotNull($inboundId);

        $show = $this->asShopUser($owner, $business)
            ->getJson('/api/conversations/'.$simulate->json('conversation.id'))
            ->assertOk();

        $texts = collect($show->json('conversation.messages'))->pluck('text')->all();
        $this->assertContains('نعم كاين، السعر 8500 دج وباقي 4 حبات.', $texts);

        $this->assertDatabaseHas('agent_tool_calls', ['tool' => 'search_products']);
        $this->assertTrue(Message::query()->where('ai_generated', true)->exists());
    }

    public function test_create_order_uses_order_service(): void
    {
        ['business' => $business] = $this->makeShop();
        $customer = $business->customers()->create([
            'name' => 'Lina',
            'phone' => '0555777111',
            'wilaya' => 'Batna',
        ]);
        $product = $business->products()->first();

        $result = app(OrderService::class)->create($business, $customer, [
            'product_id' => $product->id,
            'quantity' => 1,
            'wilaya' => 'Batna',
            'delivery_type' => 'home',
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame(9100.0, $result['order']['total']);
    }
}
