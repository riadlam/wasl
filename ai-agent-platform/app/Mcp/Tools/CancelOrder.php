<?php

namespace App\Mcp\Tools;

use App\Mcp\McpContext;
use App\Models\Business;
use App\Models\Order;
use App\Services\OrderService;

class CancelOrder extends BaseTool
{
    public function __construct(private OrderService $orders) {}

    public function name(): string
    {
        return 'cancel_order';
    }

    public function description(): string
    {
        return 'Cancel an order by order number if it has not shipped.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'order_number' => ['type' => 'string'],
            ],
            'required' => ['order_number'],
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
        $number = (string) ($arguments['order_number'] ?? '');
        if ($context->isCustomer()) {
            $owned = $context->customerId && Order::query()
                ->forBusiness($business->id)
                ->where('customer_id', $context->customerId)
                ->where('order_number', $number)
                ->exists();
            if (! $owned) {
                return ['ok' => false, 'error' => 'Order not found.'];
            }
        }

        return $this->orders->cancel($business, $number);
    }
}
