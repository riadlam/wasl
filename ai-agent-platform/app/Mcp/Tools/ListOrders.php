<?php

namespace App\Mcp\Tools;

use App\Mcp\McpContext;
use App\Models\Business;
use App\Models\Order;
use App\Services\OrderService;

class ListOrders extends BaseTool
{
    public function __construct(private OrderService $orders) {}

    public function name(): string
    {
        return 'list_orders';
    }

    public function description(): string
    {
        return 'List recent shop orders with status counts. Filter by status (pending, shipped, delivered, cancelled) or search by order number, phone, wilaya or customer name.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'status' => ['type' => 'string', 'enum' => ['pending', 'shipped', 'delivered', 'cancelled']],
                'search' => ['type' => 'string'],
                'limit' => ['type' => 'integer', 'description' => '1-50, default 20'],
            ],
        ];
    }

    public function scopes(): array
    {
        return [McpContext::SURFACE_OWNER, McpContext::SURFACE_EXTERNAL];
    }

    public function handle(Business $business, array $arguments, McpContext $context): array
    {
        $limit = max(1, min(50, (int) ($arguments['limit'] ?? 20)));
        $query = Order::query()->forBusiness($business->id)->with('items');
        $status = (string) ($arguments['status'] ?? '');
        if (in_array($status, ['pending', 'shipped', 'delivered', 'cancelled'], true)) {
            $query->where('status', $status);
        }
        $term = trim((string) ($arguments['search'] ?? ''));
        if ($term !== '') {
            $query->where(function ($inner) use ($term) {
                $inner->where('order_number', 'like', '%'.$term.'%')
                    ->orWhere('phone', 'like', '%'.$term.'%')
                    ->orWhere('wilaya', 'like', '%'.$term.'%')
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', '%'.$term.'%'));
            });
        }

        $base = Order::query()->forBusiness($business->id);
        $counts = [];
        foreach (['pending', 'shipped', 'delivered', 'cancelled'] as $s) {
            $counts[$s] = (clone $base)->where('status', $s)->count();
        }

        return [
            'orders' => $query->latest()->limit($limit)->get()
                ->map(fn (Order $o) => $this->orders->toToolArray($o) + ['created_at' => $o->created_at?->toIso8601String()])
                ->all(),
            'counts' => $counts,
        ];
    }
}
