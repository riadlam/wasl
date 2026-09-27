<?php

namespace Tests\Feature;

use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderCaptureTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_order_refuses_without_phone(): void
    {
        ['business' => $business] = $this->makeShop();
        $customer = $business->customers()->create([
            'name' => 'No Phone',
            'wilaya' => 'Batna',
        ]);
        $product = $business->products()->first();

        $result = app(OrderService::class)->create($business, $customer, [
            'product_id' => $product->id,
            'quantity' => 1,
            'wilaya' => 'Batna',
            'delivery_type' => 'home',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('phone', $result['missing']);
        $this->assertSame(0, $business->orders()->count());
    }

    public function test_create_order_refuses_without_delivery_type(): void
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
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('delivery_type', $result['missing']);
        $this->assertSame(0, $business->orders()->count());
    }

    public function test_create_order_deducts_variant_stock_and_stores_conversation(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $customer = $business->customers()->create([
            'name' => 'Lina',
            'phone' => '0555777111',
            'wilaya' => 'Batna',
            'lifecycle' => 'lead',
            'lead_status' => 'hot',
        ]);
        $conversation = $business->conversations()->create([
            'social_account_id' => $business->simulatorAccount->id,
            'customer_id' => $customer->id,
            'platform' => 'simulator',
            'status' => 'open',
        ]);
        $product = $business->products()->first();
        $variant = $product->variants()->create([
            'name' => 'Black',
            'sku' => 'AM-BK',
            'stock' => 3,
            'price' => 8500,
        ]);

        $result = app(OrderService::class)->create($business, $customer, [
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'quantity' => 2,
            'phone' => '0555777111',
            'wilaya' => 'Batna',
            'delivery_type' => 'home',
            'conversation_id' => $conversation->id,
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame(2, $result['order']['items'][0]['quantity']);
        $this->assertSame('home', $result['order']['delivery_type']);
        $this->assertSame(1, $variant->fresh()->stock);
        $this->assertSame(4, $product->fresh()->stock);
        $this->assertDatabaseHas('orders', [
            'customer_id' => $customer->id,
            'conversation_id' => $conversation->id,
            'delivery_type' => 'home',
            'phone' => '0555777111',
        ]);
        $this->assertSame('customer', $customer->fresh()->lifecycle);
        $this->assertSame('converted', $customer->fresh()->lead_status);

        $list = $this->asShopUser($owner, $business)
            ->getJson('/api/orders')
            ->assertOk()
            ->assertJsonPath('counts.pending', 1)
            ->assertJsonPath('orders.0.conversation_id', $conversation->id)
            ->assertJsonPath('orders.0.delivery_type', 'home')
            ->json();

        $this->assertSame($conversation->id, $list['orders'][0]['conversation_id']);
        $this->assertSame('open', $list['orders'][0]['conversation_status']);
        $this->assertSame('Lina', $list['orders'][0]['name']);
    }

    public function test_cancel_restores_variant_stock(): void
    {
        ['business' => $business] = $this->makeShop();
        $customer = $business->customers()->create([
            'name' => 'Lina',
            'phone' => '0555777111',
            'wilaya' => 'Batna',
        ]);
        $product = $business->products()->first();
        $variant = $product->variants()->create([
            'name' => 'Black',
            'stock' => 3,
            'price' => 8500,
        ]);

        $created = app(OrderService::class)->create($business, $customer, [
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'quantity' => 2,
            'phone' => '0555777111',
            'wilaya' => 'Batna',
            'delivery_type' => 'stopdesk',
        ]);

        $this->assertTrue($created['ok']);
        $this->assertSame(1, $variant->fresh()->stock);

        $cancelled = app(OrderService::class)->cancel($business, $created['order']['order_number']);

        $this->assertTrue($cancelled['ok']);
        $this->assertSame(3, $variant->fresh()->stock);
        $this->assertSame(4, $product->fresh()->stock);
        $this->assertSame('cancelled', $cancelled['order']['status']);
    }

    public function test_create_order_refuses_when_variant_required(): void
    {
        ['business' => $business] = $this->makeShop();
        $customer = $business->customers()->create([
            'name' => 'Lina',
            'phone' => '0555777111',
            'wilaya' => 'Batna',
        ]);
        $product = $business->products()->first();
        $product->variants()->create(['name' => 'Black', 'stock' => 3]);

        $result = app(OrderService::class)->create($business, $customer, [
            'product_id' => $product->id,
            'quantity' => 1,
            'phone' => '0555777111',
            'wilaya' => 'Batna',
            'delivery_type' => 'home',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('variant_id', $result['missing']);
    }

    public function test_create_digital_catalog_order_stores_fulfillment_metadata(): void
    {
        ['business' => $business] = $this->makeShop();
        $customer = $business->customers()->create([
            'name' => 'Karim',
            'phone' => '0555111222',
            'lifecycle' => 'lead',
        ]);
        $product = $business->products()->create([
            'name' => 'Weekly Pass',
            'slug' => 'weekly-pass',
            'type' => 'digital',
            'price' => 450,
            'stock' => 100,
            'status' => 'active',
            'metadata' => ['fulfillment_fields' => ['player_id', 'zone_id']],
        ]);

        $missing = app(OrderService::class)->create($business, $customer, [
            'product_id' => $product->id,
            'quantity' => 1,
            'phone' => '0555111222',
        ]);
        $this->assertFalse($missing['ok']);
        $this->assertSame('player_id', $missing['missing']);

        $result = app(OrderService::class)->create($business, $customer, [
            'product_id' => $product->id,
            'quantity' => 1,
            'phone' => '0555111222',
            'digital_fulfillment' => [
                'player_id' => '123456789',
                'zone_id' => '2001',
            ],
            'payment_method' => 'EDAHABIA',
            'offer_source' => 'catalog',
        ]);

        $this->assertTrue($result['ok']);
        $this->assertNull($result['order']['delivery_type']);
        $this->assertNull($result['order']['wilaya'] ?? null);
        $this->assertSame('123456789', $result['order']['metadata']['digital_fulfillment']['player_id']);
        $this->assertSame('2001', $result['order']['metadata']['digital_fulfillment']['zone_id']);
        $this->assertSame('EDAHABIA', $result['order']['metadata']['payment_method']);
        $this->assertSame('player_id: 123456789 · zone_id: 2001', $result['order']['ai_notes']);
        $this->assertSame(99, $product->fresh()->stock);
        $this->assertSame('customer', $customer->fresh()->lifecycle);
        $this->assertSame('converted', $customer->fresh()->lead_status);
    }

    public function test_create_order_stores_explicit_ai_notes(): void
    {
        ['business' => $business] = $this->makeShop();
        $customer = $business->customers()->create([
            'name' => 'Notes Client',
            'phone' => '0555444333',
            'lifecycle' => 'lead',
        ]);
        $product = $business->products()->create([
            'name' => 'Elite',
            'slug' => 'elite-notes',
            'type' => 'digital',
            'price' => 320,
            'stock' => 10,
            'status' => 'active',
            'metadata' => ['fulfillment_fields' => ['player_id']],
        ]);

        $result = app(OrderService::class)->create($business, $customer, [
            'product_id' => $product->id,
            'quantity' => 1,
            'phone' => '0555444333',
            'digital_fulfillment' => ['player_id' => '999'],
            'ai_notes' => 'MLBB player_id 999 — Weekly Elite x1',
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame('MLBB player_id 999 — Weekly Elite x1', $result['order']['ai_notes']);
        $this->assertDatabaseHas('orders', [
            'id' => $result['order']['id'],
            'ai_notes' => 'MLBB player_id 999 — Weekly Elite x1',
        ]);
    }

    public function test_digital_order_succeeds_without_wilaya_or_commune(): void
    {
        ['business' => $business] = $this->makeShop();
        $customer = $business->customers()->create([
            'name' => 'No Address',
            'phone' => '0555000111',
            'wilaya' => null,
            'commune' => null,
        ]);
        $product = $business->products()->create([
            'name' => 'Diamond Pack',
            'slug' => 'diamond-pack',
            'type' => 'digital',
            'price' => 800,
            'stock' => 10,
            'status' => 'active',
        ]);

        $result = app(OrderService::class)->create($business, $customer, [
            'product_id' => $product->id,
            'quantity' => 1,
            'phone' => '0555000111',
            'digital_fulfillment' => ['game_id' => '111', 'server_id' => '222'],
            // Agent may wrongly send these — tool must ignore for digital.
            'wilaya' => 'Biskra',
            'commune' => 'Biskra',
            'delivery_type' => 'home',
        ]);

        $this->assertTrue($result['ok']);
        $this->assertArrayNotHasKey('missing', $result);
        $this->assertNull($result['order']['wilaya']);
        $this->assertNull($result['order']['delivery_type']);
        $this->assertDatabaseHas('orders', [
            'id' => $result['order']['id'],
            'wilaya' => null,
            'commune' => null,
            'phone' => '0555000111',
        ]);
    }

    public function test_create_post_offer_order_without_product_id(): void
    {
        ['business' => $business] = $this->makeShop();
        $customer = $business->customers()->create([
            'name' => 'Sara',
            'phone' => '0666333444',
        ]);

        $result = app(OrderService::class)->create($business, $customer, [
            'product_name' => 'Weekly Pass promo',
            'unit_price' => 450,
            'quantity' => 1,
            'phone' => '0666333444',
            'digital_fulfillment' => [
                'player_id' => '987654321',
                'zone_id' => '5001',
            ],
            'offer_source' => 'recent_post',
            'payment_method' => 'EDAHABIA',
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame('Weekly Pass promo', $result['order']['items'][0]['name']);
        $this->assertSame(450.0, $result['order']['items'][0]['unit_price']);
        $this->assertSame('recent_post', $result['order']['metadata']['offer_source']);
        $this->assertTrue($result['order']['metadata']['post_offer']);
        $this->assertDatabaseHas('order_items', [
            'product_name' => 'Weekly Pass promo',
            'product_id' => null,
        ]);
    }

    public function test_create_order_missing_product_name_without_catalog_id(): void
    {
        ['business' => $business] = $this->makeShop();
        $customer = $business->customers()->create([
            'name' => 'No Offer',
            'phone' => '0777000111',
        ]);

        $result = app(OrderService::class)->create($business, $customer, [
            'unit_price' => 100,
            'phone' => '0777000111',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('product_name', $result['missing']);
    }

    public function test_product_tool_array_exposes_type_and_fulfillment_fields(): void
    {
        ['business' => $business] = $this->makeShop();
        $product = $business->products()->create([
            'name' => 'Game Topup',
            'slug' => 'game-topup',
            'type' => 'digital',
            'price' => 200,
            'stock' => 50,
            'status' => 'active',
            'metadata' => ['fulfillment_fields' => ['player_id', 'zone_id']],
        ]);

        $row = app(\App\Services\ProductService::class)->toToolArray($product);

        $this->assertSame('digital', $row['type']);
        $this->assertSame(['player_id', 'zone_id'], $row['metadata']['fulfillment_fields']);
        $this->assertSame(['player_id', 'zone_id'], $row['digital']['fulfillment_fields']);
    }

    public function test_owner_can_mark_order_shipped_via_api(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $customer = $business->customers()->create([
            'name' => 'Karim',
            'phone' => '0555111222',
            'wilaya' => 'Biskra',
        ]);
        $product = $business->products()->first();

        $created = app(OrderService::class)->create($business, $customer, [
            'product_id' => $product->id,
            'quantity' => 1,
            'phone' => '0555111222',
            'wilaya' => 'Biskra',
            'delivery_type' => 'home',
        ]);
        $this->assertTrue($created['ok']);
        $orderId = (int) $created['order']['id'];

        $this->asShopUser($owner, $business)
            ->patchJson("/api/orders/{$orderId}/status", ['status' => 'shipped'])
            ->assertOk()
            ->assertJsonPath('order.status', 'shipped');

        $this->assertSame('shipped', $business->orders()->find($orderId)?->status);
    }

    public function test_known_checkout_includes_prior_digital_fulfillment(): void
    {
        ['business' => $business] = $this->makeShop();
        $customer = $business->customers()->create([
            'name' => 'Karim',
            'phone' => '0555111222',
            'wilaya' => 'Biskra',
        ]);
        $product = $business->products()->create([
            'name' => 'Weekly Elite',
            'slug' => 'weekly-elite',
            'type' => 'digital',
            'price' => 450,
            'stock' => 100,
            'status' => 'active',
        ]);

        $created = app(OrderService::class)->create($business, $customer, [
            'product_id' => $product->id,
            'quantity' => 1,
            'phone' => '0555111222',
            'digital_fulfillment' => ['player_id' => '36728629', 'zone_id' => '1234'],
            'ai_notes' => 'MLBB weekly for 36728629',
        ]);
        $this->assertTrue($created['ok']);

        $known = app(\App\Services\CustomerService::class)->knownCheckout($customer->fresh());
        $this->assertSame('0555111222', $known['phone']);
        $this->assertSame('Biskra', $known['wilaya']);
        $this->assertSame('36728629', $known['digital_fulfillment']['player_id'] ?? null);
        $this->assertSame('1234', $known['digital_fulfillment']['zone_id'] ?? null);
        $this->assertNotEmpty($known['prior_order_number']);
    }
}
