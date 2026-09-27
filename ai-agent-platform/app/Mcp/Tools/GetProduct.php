<?php

namespace App\Mcp\Tools;

use App\Mcp\McpContext;
use App\Models\Business;
use App\Services\ProductService;

class GetProduct extends BaseTool
{
    public function __construct(private ProductService $products) {}

    public function name(): string
    {
        return 'get_product';
    }

    public function description(): string
    {
        return 'Get one product by id, including price, stock, type, variants, and delivery zones.';
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

        return $product ? ['product' => $product] : ['error' => 'Product not found'];
    }
}
