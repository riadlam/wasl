<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\KnowledgeDocument;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\Wilaya;
use App\Services\ShopProvisioner;
use App\Support\Permission;
use Illuminate\Database\Seeder;

class DemoShopSeeder extends Seeder
{
    public function run(): void
    {
        $provisioner = app(ShopProvisioner::class);

        $admin = User::query()->create([
            'name' => 'Wasl Admin',
            'email' => 'admin@wasl.test',
            'password' => 'password',
            'status' => 'active',
            'platform_role' => 'super_admin',
        ]);

        $owner = User::query()->create([
            'name' => 'Amira',
            'email' => 'amira@wasl.test',
            'password' => 'password',
            'phone' => '0555010101',
            'status' => 'active',
            'platform_role' => 'user',
        ]);

        $staff = User::query()->create([
            'name' => 'Sara',
            'email' => 'sara@wasl.test',
            'password' => 'password',
            'status' => 'active',
            'platform_role' => 'user',
        ]);

        $business = $provisioner->createShop($owner, 'Maison Amira', [
            'wilaya' => 'Oran',
            'city' => 'Oran',
            'phone' => '041000000',
            'email' => 'amira@wasl.test',
            'description' => 'Ready-to-wear and sneakers for Algerian shops.',
        ]);

        $business->members()->create([
            'user_id' => $staff->id,
            'role' => 'staff',
            'permissions' => [
                Permission::InboxView->value,
                Permission::InboxReply->value,
                Permission::InboxSimulate->value,
                Permission::InboxHandoff->value,
                Permission::ContactsView->value,
            ],
        ]);

        $sneakers = Product::query()->create([
            'business_id' => $business->id,
            'name' => 'Air Max sneakers',
            'slug' => 'air-max-sneakers',
            'description' => 'White sneakers, popular on Instagram.',
            'sku' => 'AMX-01',
            'price' => 8500,
            'stock' => 4,
            'status' => 'active',
            'type' => 'physical',
            'channel_scope' => 'all',
            'category' => 'shoes',
        ]);
        $sneakers->variants()->create([
            'name' => '42',
            'sku' => 'AMX-01-42',
            'stock' => 2,
            'attributes' => ['size' => '42'],
        ]);
        $sneakers->variants()->create([
            'name' => '43',
            'sku' => 'AMX-01-43',
            'stock' => 2,
            'attributes' => ['size' => '43'],
        ]);

        Product::query()->create([
            'business_id' => $business->id,
            'name' => 'Beige set',
            'slug' => 'beige-set',
            'description' => 'Two-piece beige set.',
            'sku' => 'BGE-02',
            'price' => 4500,
            'stock' => 12,
            'status' => 'active',
            'type' => 'physical',
            'channel_scope' => 'all',
            'category' => 'sets',
        ]);

        $guide = Product::query()->create([
            'business_id' => $business->id,
            'name' => 'Lookbook PDF',
            'slug' => 'lookbook-pdf',
            'description' => 'Season lookbook sent after the order is confirmed.',
            'sku' => 'DIG-01',
            'price' => 1500,
            'stock' => 99,
            'status' => 'active',
            'type' => 'digital',
            'channel_scope' => 'all',
            'category' => 'digital',
        ]);
        $guide->digitalAsset()->create([
            'delivery_note' => 'Send the lookbook link after the customer confirms.',
            'access_url' => 'https://wasl.test/lookbook',
        ]);

        $nord = $business->deliveryZones()->create([
            'name' => 'Nord',
            'fee' => 500,
            'days' => '2-3 days',
        ]);
        $nord->wilayas()->sync($this->wilayaIds(['16', '09', '35', '42', '15']));

        $ouest = $business->deliveryZones()->create([
            'name' => 'Ouest',
            'fee' => 650,
            'days' => '2-4 days',
        ]);
        $ouest->wilayas()->sync($this->wilayaIds(['31', '13', '27', '22', '46']));

        KnowledgeDocument::query()->create([
            'business_id' => $business->id,
            'title' => 'Cash on delivery',
            'type' => 'payment',
            'content' => 'We accept cash on delivery (COD) in all 58 wilayas. No prepayment needed.',
            'status' => 'active',
        ]);

        KnowledgeDocument::query()->create([
            'business_id' => $business->id,
            'title' => 'Returns',
            'type' => 'return_policy',
            'content' => 'You can return unused items within 48 hours of delivery. Delivery fee is not refunded.',
            'status' => 'active',
        ]);

        KnowledgeDocument::query()->create([
            'business_id' => $business->id,
            'title' => 'Hours',
            'type' => 'about',
            'content' => 'Inbox is watched 9:00–21:00 Africa/Algiers.',
            'status' => 'active',
        ]);

        $customer = Customer::query()->create([
            'business_id' => $business->id,
            'name' => 'Karim M.',
            'phone' => '0555449090',
            'wilaya' => 'Alger',
            'language' => 'Darija',
        ]);

        $order = Order::query()->create([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'order_number' => 'ORD-2026-00192',
            'status' => 'shipped',
            'subtotal' => 4500,
            'delivery_fee' => 500,
            'total' => 5000,
            'currency' => 'DZD',
            'wilaya' => 'Alger',
            'phone' => $customer->phone,
            'source' => 'agent',
            'carrier' => 'Yalidine',
            'tracking' => 'YAL-99821',
        ]);

        $order->items()->create([
            'product_name' => 'Beige set',
            'quantity' => 1,
            'unit_price' => 4500,
            'total' => 4500,
        ]);

        unset($admin);
    }

    /**
     * @param  list<string>  $codes
     * @return list<int>
     */
    private function wilayaIds(array $codes): array
    {
        return Wilaya::query()->whereIn('code', $codes)->pluck('id')->all();
    }
}
