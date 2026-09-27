<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_matches_name_phone_wilaya_and_email(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();

        $amina = $business->customers()->create([
            'name' => 'Amina Bensaid',
            'phone' => '0555123001',
            'wilaya' => 'Batna',
            'commune' => 'Ain Touta',
            'email' => 'amina@shop.test',
        ]);
        $business->customers()->create([
            'name' => 'Karim Touati',
            'phone' => '0666988777',
            'wilaya' => 'Oran',
            'email' => 'karim@shop.test',
        ]);

        $conversation = $business->conversations()->create([
            'social_account_id' => $business->simulatorAccount->id,
            'customer_id' => $amina->id,
            'platform' => 'simulator',
            'status' => 'open',
            'last_message_at' => now(),
        ]);

        $this->asShopUser($owner, $business)
            ->getJson('/api/customers?search=amina')
            ->assertOk()
            ->assertJsonCount(1, 'customers')
            ->assertJsonPath('customers.0.name', 'Amina Bensaid')
            ->assertJsonPath('customers.0.conversation_id', $conversation->id)
            ->assertJsonPath('customers.0.conversation_status', 'open')
            ->assertJsonPath('meta.total', 1);

        $this->asShopUser($owner, $business)
            ->getJson('/api/customers?search=0666988777')
            ->assertOk()
            ->assertJsonPath('customers.0.name', 'Karim Touati');

        $this->asShopUser($owner, $business)
            ->getJson('/api/customers?search=Batna')
            ->assertOk()
            ->assertJsonPath('customers.0.name', 'Amina Bensaid');

        $this->asShopUser($owner, $business)
            ->getJson('/api/customers?search=amina@shop.test')
            ->assertOk()
            ->assertJsonPath('customers.0.email', 'amina@shop.test');
    }

    public function test_per_page_is_respected(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();

        for ($i = 1; $i <= 12; $i++) {
            $business->customers()->create([
                'name' => "Customer {$i}",
                'phone' => '05550000'.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'wilaya' => 'Alger',
            ]);
        }

        $this->asShopUser($owner, $business)
            ->getJson('/api/customers?per_page=10')
            ->assertOk()
            ->assertJsonCount(10, 'customers')
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.total', 12);

        $this->asShopUser($owner, $business)
            ->getJson('/api/customers?per_page=10&page=2')
            ->assertOk()
            ->assertJsonCount(2, 'customers')
            ->assertJsonPath('meta.current_page', 2);

        $this->asShopUser($owner, $business)
            ->getJson('/api/customers')
            ->assertOk()
            ->assertJsonCount(12, 'customers')
            ->assertJsonPath('meta.per_page', 25);
    }

    public function test_can_update_contact_phone_email_and_location(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();

        $customer = $business->customers()->create([
            'name' => 'Sara Edit',
            'phone' => null,
            'email' => null,
            'wilaya' => null,
            'commune' => null,
        ]);

        $this->asShopUser($owner, $business)
            ->patchJson('/api/customers/'.$customer->id, [
                'phone' => '0777123456',
                'email' => 'sara@shop.test',
                'wilaya' => 'Oran',
                'commune' => 'Es Senia',
            ])
            ->assertOk()
            ->assertJsonPath('customer.phone', '0777123456')
            ->assertJsonPath('customer.email', 'sara@shop.test')
            ->assertJsonPath('customer.wilaya', 'Oran')
            ->assertJsonPath('customer.commune', 'Es Senia');

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'phone' => '0777123456',
            'email' => 'sara@shop.test',
            'wilaya' => 'Oran',
            'commune' => 'Es Senia',
        ]);
    }
}
