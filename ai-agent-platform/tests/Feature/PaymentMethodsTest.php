<?php

namespace Tests\Feature;

use App\Models\BusinessPaymentMethod;
use App\Services\OrderService;
use App\Services\PaymentMethodService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentMethodsTest extends TestCase
{
    use RefreshDatabase;

    public function test_put_payment_methods_requires_unique_priorities_and_fields(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();

        $this->asShopUser($owner, $business)
            ->putJson('/api/payment-methods', [
                'methods' => [
                    [
                        'method' => 'flexy',
                        'enabled' => true,
                        'priority' => 1,
                        'phone' => '0555111222',
                    ],
                    [
                        'method' => 'baridimob',
                        'enabled' => true,
                        'priority' => 1,
                        'phone' => '0666333444',
                    ],
                    [
                        'method' => 'ccp',
                        'enabled' => false,
                        'priority' => null,
                    ],
                ],
            ])
            ->assertStatus(422);

        $this->asShopUser($owner, $business)
            ->putJson('/api/payment-methods', [
                'methods' => [
                    [
                        'method' => 'flexy',
                        'enabled' => true,
                        'priority' => 1,
                        'phone' => '0555111222',
                    ],
                    [
                        'method' => 'baridimob',
                        'enabled' => false,
                        'priority' => null,
                    ],
                    [
                        'method' => 'ccp',
                        'enabled' => true,
                        'priority' => 2,
                        'ccp_cle' => '12',
                        'ccp_number' => '0012345678',
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('methods.0.enabled', true)
            ->assertJsonPath('methods.0.priority', 1)
            ->assertJsonPath('methods.2.enabled', true)
            ->assertJsonPath('methods.2.ccp_cle', '12');

        $agent = app(PaymentMethodService::class)->listForAgent($business);
        $this->assertCount(2, $agent);
        $this->assertSame('flexy', $agent[0]['method']);
        $this->assertTrue($agent[0]['recommended']);
        $this->assertSame('ccp', $agent[1]['method']);
    }

    public function test_ccp_requires_cle_and_number_when_enabled(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();

        $this->asShopUser($owner, $business)
            ->putJson('/api/payment-methods', [
                'methods' => [
                    ['method' => 'flexy', 'enabled' => false],
                    ['method' => 'baridimob', 'enabled' => false],
                    ['method' => 'ccp', 'enabled' => true, 'priority' => 1, 'ccp_cle' => '12'],
                ],
            ])
            ->assertStatus(422);
    }

    public function test_create_order_includes_payment_methods_summary(): void
    {
        ['business' => $business] = $this->makeShop();
        app(PaymentMethodService::class)->saveAll([
            [
                'method' => 'flexy',
                'enabled' => true,
                'priority' => 1,
                'phone' => '0555000111',
            ],
            [
                'method' => 'baridimob',
                'enabled' => true,
                'priority' => 2,
                'phone' => '0666000222',
            ],
            [
                'method' => 'ccp',
                'enabled' => false,
            ],
        ], $business);

        $customer = $business->customers()->create([
            'name' => 'Pay Client',
            'phone' => '0777000333',
        ]);
        $product = $business->products()->create([
            'name' => 'Digital Pack',
            'slug' => 'digital-pack',
            'type' => 'digital',
            'price' => 500,
            'stock' => 10,
            'status' => 'active',
        ]);

        $result = app(OrderService::class)->create($business, $customer, [
            'product_id' => $product->id,
            'quantity' => 1,
            'phone' => '0777000333',
            'digital_fulfillment' => ['game_id' => '1'],
        ]);

        $this->assertTrue($result['ok']);
        $this->assertNotEmpty($result['payment_methods']);
        $this->assertSame('flexy', $result['payment_recommended']['method']);
        $this->assertStringContainsString('priority-1', $result['next_step']);
    }

    public function test_list_payment_methods_mcp_tool(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        app(PaymentMethodService::class)->saveAll([
            [
                'method' => 'flexy',
                'enabled' => true,
                'priority' => 2,
                'phone' => '0555111000',
            ],
            [
                'method' => 'baridimob',
                'enabled' => true,
                'priority' => 1,
                'phone' => '0666222000',
            ],
            [
                'method' => 'ccp',
                'enabled' => false,
            ],
        ], $business);

        $tool = app(\App\Mcp\Tools\ListPaymentMethods::class);
        $ctx = new \App\Mcp\McpContext(\App\Mcp\McpContext::SURFACE_CUSTOMER, []);
        $out = $tool->handle($business, [], $ctx);

        $this->assertSame(2, $out['count']);
        $this->assertSame('baridimob', $out['recommended']['method']);
        $this->assertSame('baridimob', $out['methods'][0]['method']);
        $this->assertSame('flexy', $out['methods'][1]['method']);
        $this->assertDatabaseCount('business_payment_methods', 3);
        $this->assertSame(1, BusinessPaymentMethod::query()->where('business_id', $business->id)->where('enabled', true)->where('priority', 1)->count());
    }
}
