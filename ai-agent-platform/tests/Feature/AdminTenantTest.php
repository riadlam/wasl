<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminTenantTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_list_businesses(): void
    {
        ['admin' => $admin] = $this->makeShop();

        $this->actingAs($admin)
            ->getJson('/api/admin/businesses')
            ->assertOk()
            ->assertJsonCount(1, 'businesses');
    }

    public function test_owner_cannot_list_tenants(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();

        $this->asShopUser($owner, $business)
            ->getJson('/api/admin/businesses')
            ->assertForbidden();
    }
}
