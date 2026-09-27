<?php

namespace App\Mcp\Tools;

use App\Mcp\McpContext;
use App\Models\Business;
use App\Models\Product;
use App\Services\ProductService;

class SearchProducts extends BaseTool
{
    public function __construct(private ProductService $products) {}

    public function name(): string
    {
        return 'search_products';
    }

    public function description(): string
    {
        return 'Search this shop\'s active catalog (any business). Matches keywords individually and auto-broadens long agent queries (drops extra brand/category words). Empty query lists active products. If count=0, read hint and retry shorter keywords or empty query + list_categories — one empty long query is not proof of unavailability. Returns price, stock, type, variants, fulfillment hints.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'Product keywords from the customer. Prefer short product words; empty lists the active catalog.'],
                'category' => ['type' => 'string', 'description' => 'Exact category from list_categories'],
                'min_price' => ['type' => 'number'],
                'max_price' => ['type' => 'number'],
                'in_stock' => ['type' => 'boolean', 'description' => 'Only products with stock above 0'],
                'limit' => ['type' => 'integer', 'description' => '1-20, default 8'],
                'sort' => ['type' => 'string', 'enum' => ['relevance', 'price_asc', 'price_desc', 'newest']],
            ],
        ];
    }

    public function scopes(): array
    {
        return self::READ_ALL;
    }

    public function handle(Business $business, array $arguments, McpContext $context): array
    {
        $term = trim((string) ($arguments['query'] ?? ''));
        $limit = max(1, min(20, (int) ($arguments['limit'] ?? 8)));
        $hasExtraFilters = ($this->cleanString($arguments['category'] ?? null) !== null)
            || is_numeric($arguments['min_price'] ?? null)
            || is_numeric($arguments['max_price'] ?? null)
            || filter_var($arguments['in_stock'] ?? false, FILTER_VALIDATE_BOOLEAN);

        // Unfiltered keyword search: use SaaS-safe broaden/fallback path.
        if (! $hasExtraFilters) {
            $payload = $this->products->searchForAgent($business, $term, $limit);
            $sort = (string) ($arguments['sort'] ?? 'relevance');
            if ($sort !== 'relevance' && ($payload['products'] ?? []) !== []) {
                $payload['products'] = $this->sortRows($payload['products'], $sort);
            }

            return $payload;
        }

        $query = Product::query()
            ->forBusiness($business->id)
            ->with(['variants.image', 'galleryImages', 'deliveryZones', 'digitalAsset'])
            ->where('status', 'active');

        if ($term !== '') {
            $this->products->applyCatalogSearch($query, $term);
        }

        if (($category = $this->cleanString($arguments['category'] ?? null)) !== null) {
            $query->where('category', $category);
        }
        if (is_numeric($arguments['min_price'] ?? null)) {
            $query->where('price', '>=', (float) $arguments['min_price']);
        }
        if (is_numeric($arguments['max_price'] ?? null)) {
            $query->where('price', '<=', (float) $arguments['max_price']);
        }
        if (filter_var($arguments['in_stock'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $query->where(function ($q) {
                $q->where('stock', '>', 0)
                    ->orWhere('type', 'digital')
                    ->orWhereHas('variants', fn ($v) => $v->where('stock', '>', 0));
            });
        }

        $sort = (string) ($arguments['sort'] ?? ($term !== '' ? 'relevance' : 'newest'));
        match ($sort) {
            'price_asc' => $query->reorder()->orderBy('price'),
            'price_desc' => $query->reorder()->orderByDesc('price'),
            'newest' => $query->reorder()->orderByDesc('id'),
            default => null,
        };

        if ($sort === 'relevance' && $term === '') {
            $query->orderBy('id');
        }

        $rows = $query->limit($limit)->get()->map(fn (Product $p) => $this->products->toToolArray($p))->all();

        return [
            'products' => $rows,
            'count' => count($rows),
            'query_used' => $term,
            'original_query' => $term,
            'matched_via' => $rows === [] ? 'none' : 'direct',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function sortRows(array $rows, string $sort): array
    {
        usort($rows, function (array $a, array $b) use ($sort) {
            return match ($sort) {
                'price_asc' => ((float) ($a['price'] ?? 0)) <=> ((float) ($b['price'] ?? 0)),
                'price_desc' => ((float) ($b['price'] ?? 0)) <=> ((float) ($a['price'] ?? 0)),
                'newest' => ((int) ($b['id'] ?? 0)) <=> ((int) ($a['id'] ?? 0)),
                default => 0,
            };
        });

        return $rows;
    }
}
