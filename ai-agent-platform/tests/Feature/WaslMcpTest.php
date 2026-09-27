<?php

namespace Tests\Feature;

use App\AI\Agents\McpToolPolicy;
use App\Mcp\McpContext;
use App\Mcp\WaslMcpServer;
use App\Models\Customer;
use App\Models\CustomerAiSetting;
use App\Models\McpToken;
use App\Models\McpToolCall;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WaslMcpTest extends TestCase
{
    use RefreshDatabase;

    public function test_endpoint_rejects_missing_and_revoked_tokens(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();

        $this->postJson('/api/mcp', $this->rpc('tools/list'))
            ->assertStatus(401)
            ->assertJsonPath('error.code', -32001);

        $this->withToken('wasl_notarealtoken')
            ->postJson('/api/mcp', $this->rpc('tools/list'))
            ->assertStatus(401);

        [$token, $plain] = McpToken::issue($business, $owner, 'Zapier');
        $token->update(['revoked_at' => now()]);

        $this->withToken($plain)
            ->postJson('/api/mcp', $this->rpc('tools/list'))
            ->assertStatus(401);
    }

    public function test_initialize_and_external_tool_list_hide_customer_and_mutating_tools(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        [, $plain] = McpToken::issue($business, $owner, 'Claude');

        $this->withToken($plain)
            ->postJson('/api/mcp', $this->rpc('initialize', ['protocolVersion' => '2025-03-26']))
            ->assertOk()
            ->assertJsonPath('result.protocolVersion', WaslMcpServer::PROTOCOL_VERSION)
            ->assertJsonPath('result.serverInfo.name', 'wasl');

        $names = collect($this->withToken($plain)
            ->postJson('/api/mcp', $this->rpc('tools/list'))
            ->assertOk()
            ->json('result.tools'))
            ->pluck('name')
            ->all();

        foreach (['search_products', 'get_product', 'list_delivery_zones', 'list_wilayas', 'get_delivery_price', 'list_orders', 'get_order', 'list_ai_campaigns'] as $expected) {
            $this->assertContains($expected, $names);
        }
        foreach (['create_order', 'cancel_order', 'update_customer', 'mark_lead', 'handoff_to_human', 'create_ai_campaign'] as $hidden) {
            $this->assertNotContains($hidden, $names);
        }

        $this->withToken($plain)
            ->postJson('/api/mcp', $this->rpc('tools/call', ['name' => 'create_order', 'arguments' => ['product_id' => 1]]))
            ->assertOk()
            ->assertJsonPath('error.code', -32602);
    }

    public function test_delivery_price_tool_returns_grounded_total(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        [, $plain] = McpToken::issue($business, $owner, 'Sheets');
        $product = Product::query()->forBusiness($business->id)->firstOrFail();

        $response = $this->withToken($plain)
            ->postJson('/api/mcp', $this->rpc('tools/call', [
                'name' => 'get_delivery_price',
                'arguments' => ['wilaya' => '05', 'product_id' => $product->id, 'quantity' => 2],
            ]))
            ->assertOk()
            ->assertJsonPath('result.isError', false);

        $data = $response->json('result.structuredContent');
        $this->assertEquals(600, $data['fee']);
        $this->assertEquals(17000, $data['subtotal']);
        $this->assertEquals(17600, $data['total']);
        $this->assertIsString($response->json('result.content.0.text'));

        $this->assertTrue(McpToolCall::query()
            ->where('business_id', $business->id)
            ->where('surface', McpContext::SURFACE_EXTERNAL)
            ->where('tool', 'get_delivery_price')
            ->where('status', 'ok')
            ->exists());
    }

    public function test_batch_and_notifications(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        [, $plain] = McpToken::issue($business, $owner, 'Batch');

        $this->withToken($plain)
            ->postJson('/api/mcp', ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'])
            ->assertStatus(202);

        $this->withToken($plain)
            ->postJson('/api/mcp', [$this->rpc('ping', [], 1), $this->rpc('nope', [], 2)])
            ->assertOk()
            ->assertJsonPath('0.id', 1)
            ->assertJsonPath('1.error.code', -32601);
    }

    public function test_token_only_reads_its_own_shop(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->makeShop();
        $otherOwner = \App\Models\User::factory()->create(['email' => 'rival@example.test']);
        $other = app(\App\Services\ShopProvisioner::class)->createShop($otherOwner, 'Rival', ['wilaya' => 'Alger']);
        Product::query()->create([
            'business_id' => $other->id,
            'name' => 'Rival secret jacket',
            'slug' => 'rival-jacket',
            'price' => 1000,
            'stock' => 1,
            'status' => 'active',
        ]);
        [, $plain] = McpToken::issue($business, $owner, 'Mine');

        $text = json_encode($this->withToken($plain)
            ->postJson('/api/mcp', $this->rpc('tools/call', ['name' => 'search_products', 'arguments' => ['limit' => 20]]))
            ->assertOk()
            ->json('result.structuredContent'));

        $this->assertStringContainsString('Air Max', $text);
        $this->assertStringNotContainsString('Rival secret', $text);
    }

    public function test_token_management_is_owner_only_and_shows_plain_once(): void
    {
        ['owner' => $owner, 'staff' => $staff, 'business' => $business] = $this->makeShop();

        $this->asShopUser($staff, $business)
            ->postJson('/api/mcp-tokens', ['name' => 'Nope'])
            ->assertForbidden();

        $created = $this->asShopUser($owner, $business)
            ->postJson('/api/mcp-tokens', ['name' => 'Claude desktop', 'scopes' => ['external']])
            ->assertCreated()
            ->json();
        $this->assertStringStartsWith('wasl_', $created['plain_token']);

        $listed = $this->asShopUser($owner, $business)->getJson('/api/mcp-tokens')->assertOk()->json();
        $this->assertStringNotContainsString($created['plain_token'], json_encode($listed));
        $this->assertCount(1, $listed['tokens']);

        $this->asShopUser($owner, $business)
            ->deleteJson('/api/mcp-tokens/'.$created['token']['id'])
            ->assertOk();

        $this->withToken($created['plain_token'])
            ->postJson('/api/mcp', $this->rpc('tools/list'))
            ->assertStatus(401);
    }

    public function test_customer_surface_is_scoped_to_the_current_customer(): void
    {
        ['business' => $business] = $this->makeShop();
        $me = Customer::query()->create(['business_id' => $business->id, 'name' => 'Me', 'phone' => '0550000001']);
        $someone = Customer::query()->create(['business_id' => $business->id, 'name' => 'Someone', 'phone' => '0550000002']);
        Order::query()->create([
            'business_id' => $business->id,
            'customer_id' => $someone->id,
            'order_number' => 'WS-9001',
            'total' => 9100,
        ]);
        Order::query()->create([
            'business_id' => $business->id,
            'customer_id' => $me->id,
            'order_number' => 'WS-9002',
            'total' => 9100,
        ]);

        $server = app(WaslMcpServer::class);
        $ctx = new McpContext(McpContext::SURFACE_CUSTOMER, [McpContext::SURFACE_CUSTOMER], customerId: $me->id);

        $theirs = $server->callTool($business, 'get_order', ['order_number' => 'WS-9001'], $ctx);
        $this->assertSame('Order not found', $theirs['error'] ?? null);

        $mine = $server->callTool($business, 'get_order', ['order_number' => 'WS-9002'], $ctx);
        $this->assertArrayNotHasKey('error', $mine);

        $customer = $server->callTool($business, 'get_customer', ['phone' => '0550000002'], $ctx);
        $this->assertStringNotContainsString('Someone', json_encode($customer));

        $listOrders = $server->callTool($business, 'list_orders', [], $ctx);
        $this->assertSame('tool.unknown', $listOrders['code'] ?? null);
    }

    public function test_create_order_is_gated_by_customer_ai_setting(): void
    {
        ['business' => $business] = $this->makeShop();
        $customer = Customer::query()->create(['business_id' => $business->id, 'name' => 'Buyer']);
        $product = Product::query()->forBusiness($business->id)->firstOrFail();

        $names = fn () => array_map(fn ($t) => $t->name(), app(McpToolPolicy::class)->for(McpContext::SURFACE_CUSTOMER, $business)->all());
        $this->assertContains('create_order', $names());
        $this->assertContains('handoff_to_human', $names());
        $this->assertNotContains('list_orders', $names());
        $this->assertNotContains('create_ai_campaign', $names());

        CustomerAiSetting::forBusiness($business)->update(['allow_order_creation' => false]);

        $this->assertNotContains('create_order', $names());
        $blocked = app(WaslMcpServer::class)->callTool($business, 'create_order', [
            'product_id' => $product->id,
            'phone' => '0550000001',
            'wilaya' => '05',
            'delivery_type' => 'home',
        ], new McpContext(McpContext::SURFACE_CUSTOMER, [McpContext::SURFACE_CUSTOMER], customerId: $customer->id));

        $this->assertSame('tool.disabled', $blocked['code'] ?? null);
        $this->assertSame(0, Order::query()->count());
    }

    public function test_campaign_surface_is_read_only(): void
    {
        ['business' => $business] = $this->makeShop();
        $names = array_map(fn ($t) => $t->name(), app(McpToolPolicy::class)->for(McpContext::SURFACE_CAMPAIGN, $business)->all());

        $this->assertContains('search_products', $names);
        $this->assertContains('get_shop_profile', $names);
        $this->assertNotContains('create_ai_campaign', $names);
        $this->assertNotContains('create_order', $names);
        $this->assertNotContains('update_customer', $names);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function rpc(string $method, array $params = [], int $id = 1): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params];
    }
}
