<?php

namespace App\Mcp\Tools;

use App\Mcp\McpContext;
use App\Models\Business;
use App\Services\ProductService;

class GetProductStock extends BaseTool
{
    public function __construct(private ProductService $products) {}

    public function name(): string
    {
        return 'get_product_stock';
    }

    public function description(): string
    {
        return 'Get current stock for a product id, including variants when they exist.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'product_id' => ['type' => 'integer'],
            ],
            'required' => ['product_id'],
        ];
    }

    public function scopes(): array
    {
        return self::READ_ALL;
    }

    public function handle(Business $business, array $arguments, McpContext $context): array
    {
        $product = $this->products->get($business, (int) ($arguments['product_id'] ?? 0));
        if (! $product) {
            return ['error' => 'Product not found'];
        }

        return [
            'product_id' => $product['id'],
            'name' => $product['name'],
            'stock' => $product['stock'],
            'variants' => $product['variants'] ?? [],
        ];
    }
}
