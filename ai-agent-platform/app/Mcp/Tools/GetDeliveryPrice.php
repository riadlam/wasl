<?php

namespace App\Mcp\Tools;

use App\Mcp\McpContext;
use App\Models\Business;
use App\Models\Product;
use App\Services\DeliveryService;

class GetDeliveryPrice extends BaseTool
{
    public function __construct(private DeliveryService $delivery) {}

    public function name(): string
    {
        return 'get_delivery_price';
    }

    public function description(): string
    {
        return 'Quote delivery for an Algerian wilaya (name or code). Pass product_id and quantity to get subtotal and total; the product may only ship to some zones. Digital products have no delivery fee.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'wilaya' => ['type' => 'string', 'description' => 'Wilaya name or code'],
                'product_id' => ['type' => 'integer', 'description' => 'Optional product to apply its supported zones'],
                'quantity' => ['type' => 'integer', 'description' => 'Units, default 1'],
                'delivery_type' => ['type' => 'string', 'enum' => ['home', 'stopdesk']],
            ],
            'required' => ['wilaya'],
        ];
    }

    public function scopes(): array
    {
        return self::READ_ALL;
    }

    public function handle(Business $business, array $arguments, McpContext $context): array
    {
        $product = null;
        if (! empty($arguments['product_id'])) {
            $product = Product::query()->forBusiness($business->id)->find((int) $arguments['product_id']);
            if (! $product) {
                return ['error' => 'Product not found'];
            }
        }

        $result = $this->delivery->lookup($business, (string) ($arguments['wilaya'] ?? ''), $product);
        if (! $result) {
            return ['error' => 'No delivery fee found for that wilaya', 'covered' => false];
        }

        if (! empty($arguments['delivery_type'])) {
            $result['delivery_type'] = (string) $arguments['delivery_type'];
        }
        if ($product) {
            $qty = max(1, (int) ($arguments['quantity'] ?? 1));
            $subtotal = round((float) $product->price * $qty, 2);
            $result['product_id'] = $product->id;
            $result['quantity'] = $qty;
            $result['unit_price'] = (float) $product->price;
            $result['subtotal'] = $subtotal;
            $result['total'] = round($subtotal + (float) $result['fee'], 2);
        }

        return $result;
    }
}
