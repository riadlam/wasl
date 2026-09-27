<?php

namespace Tests;

use App\Models\Business;
use App\Models\Product;
use App\Models\User;
use App\Models\Wilaya;
use App\Services\ShopProvisioner;
use App\Support\Permission;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /**
     * @return array{admin: User, owner: User, staff: User, business: Business}
     */
    protected function makeShop(): array
    {
        $admin = User::factory()->create([
            'email' => 'admin@example.test',
            'platform_role' => 'super_admin',
        ]);

        $owner = User::factory()->create([
            'name' => 'Amira',
            'email' => 'owner@example.test',
        ]);

        $staff = User::factory()->create([
            'name' => 'Sara',
            'email' => 'staff@example.test',
        ]);

        $business = app(ShopProvisioner::class)->createShop($owner, 'Maison Test', [
            'wilaya' => 'Oran',
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

        Product::query()->create([
            'business_id' => $business->id,
            'name' => 'Air Max sneakers',
            'slug' => 'air-max',
            'price' => 8500,
            'stock' => 4,
            'status' => 'active',
        ]);

        $zone = $business->deliveryZones()->create([
            'name' => 'Est',
            'fee' => 600,
            'days' => '2-4 days',
        ]);
        $zone->wilayas()->attach(Wilaya::query()->where('code', '05')->value('id'));

        return compact('admin', 'owner', 'staff', 'business');
    }

    protected function asShopUser(User $user, Business $business)
    {
        return $this->actingAs($user)->withSession(['current_business_id' => $business->id]);
    }
}
