<?php

namespace App\Mcp\Tools;

use App\Mcp\McpContext;
use App\Models\Business;
use App\Models\Order;
use App\Services\OrderService;

class GetOrder extends BaseTool
{
    public function __construct(private OrderService $orders) {}

    public function name(): string
    {
        return 'get_order';
    }

    public function description(): string
    {
        return 'Look up an order by order number, or the latest order for this customer (includes phone, address, wilaya, digital_fulfillment IDs from prior purchases). Use latest-order fields to CONFIRM returning-customer details. ALWAYS call this before telling the client an order is topped-up/shipped/done. Only status shipped or delivered (set by the shop owner on the Orders dashboard) means fulfillment is complete. pending = still verifying.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'order_number' => ['type' => 'string'],
            ],
        ];
    }

    public function scopes(): array
    {
        return [McpContext::SURFACE_OWNER, McpContext::SURFACE_CUSTOMER, McpContext::SURFACE_EXTERNAL];
    }

    public function handle(Business $business, array $arguments, McpContext $context): array
    {
        $number = trim((string) ($arguments['order_number'] ?? ''));
        if ($number !== '') {
            if ($context->isCustomer() && ! $this->belongsToCustomer($business, $number, $context->customerId)) {
                return ['error' => 'Order not found'];
            }
            $order = $this->orders->get($business, $number);

            return $order ? ['order' => $order] : ['error' => 'Order not found'];
        }

        if ($context->customerId) {
            $order = $this->orders->latestForCustomer($business, $context->customerId);

            return $order ? ['order' => $order] : ['error' => 'No orders for this customer'];
        }

        return ['error' => 'Need an order number or a known customer'];
    }

    private function belongsToCustomer(Business $business, string $number, ?int $customerId): bool
    {
        if (! $customerId) {
            return false;
        }

        return Order::query()
            ->forBusiness($business->id)
            ->where('customer_id', $customerId)
            ->where(fn ($q) => $q->where('order_number', $number)->orWhere('id', ctype_digit($number) ? (int) $number : 0))
            ->exists();
    }
}
