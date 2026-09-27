<?php

namespace App\Mcp\Tools;

use App\Mcp\McpContext;
use App\Models\Business;
use App\Services\OrderService;

class CreateOrder extends BaseTool
{
    public function __construct(private OrderService $orders) {}

    public function name(): string
    {
        return 'create_order';
    }

    public function description(): string
    {
        return 'Capture a confirmed order AFTER the client accepted an open offer and ALL required destination/fulfillment fields are collected (phone + digital IDs, or phone + wilaya/delivery for physical). Catalog: product_id (+ variant_id if needed). Post/manual digital: product_name + unit_price (no product_id). Always need phone. PHYSICAL ONLY: wilaya + delivery_type (home|stopdesk). DIGITAL: digital_fulfillment only — omit wilaya/commune/address. Pass ai_notes with a short owner summary (game ID, zone, address). Do NOT request payment numbers before this tool succeeds.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'product_id' => ['type' => 'integer', 'description' => 'Catalog product when matched. Omit for post-only offers.'],
                'variant_id' => ['type' => 'integer', 'description' => 'Required when the catalog product has variants.'],
                'product_name' => ['type' => 'string', 'description' => 'Offer/product title when no catalog product_id (post/manual).'],
                'offer_title' => ['type' => 'string', 'description' => 'Alias for product_name.'],
                'unit_price' => ['type' => 'number', 'description' => 'Unit price for post/manual offers (required without product_id).'],
                'quantity' => ['type' => 'integer', 'description' => 'Units the client wants. Defaults to 1.'],
                'phone' => ['type' => 'string', 'description' => 'Client mobile number they clearly gave.'],
                'wilaya' => ['type' => 'string', 'description' => 'PHYSICAL ONLY. Delivery wilaya. Omit for digital/top-up/pass.'],
                'commune' => ['type' => 'string', 'description' => 'PHYSICAL ONLY. Commune/baladia. Omit for digital.'],
                'address' => ['type' => 'string', 'description' => 'PHYSICAL ONLY. Street address for home delivery. Omit for digital.'],
                'delivery_type' => [
                    'type' => 'string',
                    'enum' => ['home', 'stopdesk'],
                    'description' => 'PHYSICAL ONLY: home or stopdesk. Omit for digital — not required and not stored.',
                ],
                'product_type' => [
                    'type' => 'string',
                    'enum' => ['physical', 'digital'],
                    'description' => 'Set digital for post/game offers when no catalog product_id. Defaults to digital without product_id.',
                ],
                'digital_fulfillment' => [
                    'type' => 'object',
                    'description' => 'DIGITAL: fulfillment key/values (account IDs, game, notes). Do not send wilaya with this.',
                    'additionalProperties' => true,
                ],
                'offer_source' => ['type' => 'string', 'description' => 'Where the offer came from (e.g. recent_post, catalog, identity).'],
                'payment_method' => ['type' => 'string', 'description' => 'Optional preferred method name only — do NOT put Flexy/BaridiMob numbers here. Payment details come AFTER create_order.'],
                'ai_notes' => [
                    'type' => 'string',
                    'description' => 'Short owner-facing notes for the Orders inbox (game ID, zone, address, anything needed to fulfill). Prefer filling this when creating the order.',
                ],
                'notes' => ['type' => 'string', 'description' => 'Alias for ai_notes.'],
            ],
            'required' => [],
        ];
    }

    public function scopes(): array
    {
        return [McpContext::SURFACE_CUSTOMER];
    }

    public function mutates(): bool
    {
        return true;
    }

    public function handle(Business $business, array $arguments, McpContext $context): array
    {
        $customer = $this->currentCustomer($business, $context);
        if (! $customer) {
            return ['ok' => false, 'error' => 'Customer is missing'];
        }

        $arguments['conversation_id'] = $context->conversationId;

        return $this->orders->create($business, $customer, $arguments);
    }
}
