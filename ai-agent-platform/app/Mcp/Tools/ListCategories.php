<?php

namespace App\Mcp\Tools;

use App\Mcp\McpContext;
use App\Models\Business;
use App\Models\Product;

class ListCategories extends BaseTool
{
    public function name(): string
    {
        return 'list_categories';
    }

    public function description(): string
    {
        return 'List catalog categories with active product counts and price range. Use to understand what the shop sells before searching.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass];
    }

    public function scopes(): array
    {
        return self::READ_ALL;
    }

    public function handle(Business $business, array $arguments, McpContext $context): array
    {
        $rows = Product::query()
            ->forBusiness($business->id)
            ->where('status', 'active')
            ->selectRaw('category, count(*) as products, min(price) as min_price, max(price) as max_price')
            ->groupBy('category')
            ->orderBy('category')
            ->get()
            ->map(fn ($row) => [
                'category' => $row->category ?: 'uncategorized',
                'products' => (int) $row->products,
                'min_price' => (float) $row->min_price,
                'max_price' => (float) $row->max_price,
            ])
            ->values()
            ->all();

        return ['categories' => $rows, 'currency' => $business->currency ?: 'DZD'];
    }
}
