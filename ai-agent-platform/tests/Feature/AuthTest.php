<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_from_workspace(): void
    {
        $this->get('/space')->assertRedirect('/login');
    }

    public function test_owner_can_open_workspace(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();

        $this->asShopUser($owner, $business)->get('/space')->assertOk();
    }

    public function test_register_creates_owner_and_shop(): void
    {
        $this->post('/register', [
            'name' => 'Nadir',
            'email' => 'nadir@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'shop_name' => 'Boutique Nadir',
        ])->assertRedirect('/space');

        $this->assertDatabaseHas('users', ['email' => 'nadir@example.test']);
        $this->assertDatabaseHas('businesses', ['name' => 'Boutique Nadir']);
        $this->assertDatabaseHas('business_users', ['role' => 'owner']);
    }
}
