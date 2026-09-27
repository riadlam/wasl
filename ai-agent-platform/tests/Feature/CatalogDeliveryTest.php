<?php

namespace Tests\Feature;

use App\Models\Wilaya;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_wilayas_catalog_has_fifty_eight(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();

        $this->asShopUser($owner, $business)
            ->getJson('/api/wilayas')
            ->assertOk()
            ->assertJsonCount(58, 'wilayas');
    }

    public function test_owner_can_create_named_zone(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $alger = Wilaya::query()->where('code', '16')->firstOrFail();

        $this->asShopUser($owner, $business)
            ->postJson('/api/delivery-zones', [
                'name' => 'Nord',
                'fee' => 500,
                'days' => '2-3 days',
                'wilaya_ids' => [$alger->id],
            ])
            ->assertCreated()
            ->assertJsonPath('zone.name', 'Nord')
            ->assertJsonPath('zone.fee', 500);
    }

    public function test_owner_can_create_product_with_variant_and_type(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();

        $response = $this->asShopUser($owner, $business)
            ->postJson('/api/products', [
                'name' => 'Hat',
                'price' => 1200,
                'stock' => 6,
                'type' => 'physical',
                'channel_scope' => 'all',
                'variants' => [
                    ['name' => 'Black', 'sku' => 'HAT-BK', 'stock' => 3],
                ],
            ])
            ->assertCreated();

        $response->assertJsonPath('product.type', 'physical')
            ->assertJsonPath('product.variants.0.name', 'Black');
    }

    public function test_digital_product_has_no_delivery_fee(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();

        $product = $this->asShopUser($owner, $business)
            ->postJson('/api/products', [
                'name' => 'Lookbook',
                'price' => 1500,
                'stock' => 99,
                'type' => 'digital',
                'digital_asset' => ['delivery_note' => 'Send the link.'],
            ])
            ->assertCreated()
            ->json('product');

        $fee = app(\App\Services\DeliveryService::class)->lookup(
            $business,
            'Batna',
            \App\Models\Product::query()->find($product['id']),
        );

        $this->assertSame(0.0, $fee['fee']);
        $this->assertTrue($fee['digital']);
    }

    public function test_gallery_is_capped_at_three_images(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        \Illuminate\Support\Facades\Storage::fake('public');

        $product = $this->asShopUser($owner, $business)
            ->postJson('/api/products', [
                'name' => 'Hat',
                'price' => 1200,
            ])
            ->assertCreated()
            ->json('product');

        for ($i = 0; $i < 3; $i++) {
            $this->asShopUser($owner, $business)
                ->post('/api/products/'.$product['id'].'/images', [
                    'image' => \Illuminate\Http\UploadedFile::fake()->image("hat-{$i}.jpg"),
                ])
                ->assertCreated();
        }

        $this->asShopUser($owner, $business)
            ->post('/api/products/'.$product['id'].'/images', [
                'image' => \Illuminate\Http\UploadedFile::fake()->image('hat-4.jpg'),
            ])
            ->assertStatus(422);
    }

    public function test_variant_image_does_not_count_toward_gallery(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        \Illuminate\Support\Facades\Storage::fake('public');

        $product = $this->asShopUser($owner, $business)
            ->postJson('/api/products', [
                'name' => 'Hat',
                'price' => 1200,
                'variants' => [['name' => 'Black', 'stock' => 2]],
            ])
            ->assertCreated()
            ->json('product');

        $variantId = $product['variants'][0]['id'];

        $this->asShopUser($owner, $business)
            ->post('/api/products/'.$product['id'].'/images', [
                'image' => \Illuminate\Http\UploadedFile::fake()->image('black.jpg'),
                'variant_id' => $variantId,
            ])
            ->assertCreated()
            ->assertJsonPath('product.images', []);

        $url = $this->asShopUser($owner, $business)
            ->getJson('/api/products/'.$product['id'])
            ->assertOk()
            ->json('product.variants.0.image_url');

        $this->assertIsString($url);
        $this->assertNotSame('', $url);
    }
}
