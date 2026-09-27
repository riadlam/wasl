<?php

namespace App\Services;

use App\Models\Business;
use App\Models\DeliveryZone;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\SocialAccount;
use App\Support\CurrentBusiness;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class ProductService
{
    public const GALLERY_LIMIT = 3;

    public function __construct(private DeliveryService $delivery) {}

    public function query(?Business $business = null)
    {
        $business ??= CurrentBusiness::require();

        return Product::query()
            ->forBusiness($business->id)
            ->with([
                'variants.image',
                'galleryImages',
                'socialAccounts',
                'deliveryZones.wilayas',
                'digitalAsset',
            ]);
    }

    public function search(Business $business, string $query, int $limit = 8): array
    {
        $term = trim($query);
        $limit = max(1, min(20, $limit));

        $products = Product::query()
            ->forBusiness($business->id)
            ->with(['variants.image', 'galleryImages', 'deliveryZones', 'digitalAsset'])
            ->where('status', 'active');

        $this->applyCatalogSearch($products, $term);

        return $products->limit($limit)->get()->map(fn (Product $p) => $this->toToolArray($p))->all();
    }

    /**
     * SaaS-safe catalog lookup for agent queries.
     * Agents often paste long phrases (product + category + brand). Full-phrase LIKE misses
     * real SKUs, so we: (1) token-match, (2) broaden by dropping trailing/leading tokens,
     * (3) try significant single tokens, and return a retry hint when still empty.
     *
     * @return array{products: list<array<string, mixed>>, count: int, query_used: string, original_query: string, matched_via: string, hint?: string}
     */
    public function searchForAgent(Business $business, string $query, int $limit = 8): array
    {
        $original = trim($query);
        $limit = max(1, min(20, $limit));

        foreach ($this->catalogSearchAttempts($original) as $attempt) {
            $rows = $this->search($business, $attempt, $limit);
            if ($rows === []) {
                continue;
            }

            return [
                'products' => $rows,
                'count' => count($rows),
                'query_used' => $attempt,
                'original_query' => $original,
                'matched_via' => $attempt === $original || $original === '' ? 'direct' : 'broadened',
            ];
        }

        $activeCount = Product::query()
            ->forBusiness($business->id)
            ->where('status', 'active')
            ->count();

        return [
            'products' => [],
            'count' => 0,
            'query_used' => $original,
            'original_query' => $original,
            'matched_via' => 'none',
            'hint' => $activeCount > 0
                ? 'No keyword match. Retry search_products with a shorter product keyword from the customer, or call search_products with an empty query to list active catalog items, then list_categories.'
                : 'Active catalog is empty for this shop. Check Identity and recent posts before denying.',
            'active_catalog_count' => $activeCount,
        ];
    }

    /**
     * @return list<string>
     */
    public function catalogSearchAttempts(string $query): array
    {
        $term = trim($query);
        if ($term === '') {
            return [''];
        }

        $tokens = preg_split('/\s+/u', mb_strtolower($term)) ?: [];
        $tokens = array_values(array_unique(array_filter(
            $tokens,
            fn (string $token) => mb_strlen($token) >= 2
        )));

        $attempts = [$term];

        // Drop trailing tokens first (agents often append category/brand/game after the SKU words).
        for ($keep = count($tokens) - 1; $keep >= 1; $keep--) {
            $attempts[] = implode(' ', array_slice($tokens, 0, $keep));
        }

        // Drop leading tokens (prefix noise).
        for ($start = 1; $start < count($tokens); $start++) {
            $attempts[] = implode(' ', array_slice($tokens, $start));
        }

        // Significant single tokens, longest first.
        $byLength = $tokens;
        usort($byLength, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));
        foreach ($byLength as $token) {
            if (mb_strlen($token) >= 3) {
                $attempts[] = $token;
            }
        }

        return array_values(array_unique(array_filter($attempts, fn (string $a) => $a !== '')));
    }

    /**
     * Match catalog by full phrase OR by individual tokens (any shop / any language keywords).
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\Product>  $query
     */
    public function applyCatalogSearch($query, string $term): void
    {
        $term = trim($term);
        if ($term === '') {
            return;
        }

        $tokens = preg_split('/\s+/u', mb_strtolower($term)) ?: [];
        $tokens = array_values(array_unique(array_filter(
            $tokens,
            fn (string $token) => mb_strlen($token) >= 2
        )));

        $query->where(function ($outer) use ($term, $tokens) {
            $outer->where(function ($inner) use ($term) {
                $this->matchCatalogFields($inner, $term);
            });

            foreach ($tokens as $token) {
                if (mb_strtolower($token) === mb_strtolower($term)) {
                    continue;
                }
                $outer->orWhere(function ($inner) use ($token) {
                    $this->matchCatalogFields($inner, $token);
                });
            }
        });

        if ($tokens !== []) {
            $driver = $query->getConnection()->getDriverName();
            $scoreParts = [];
            $bindings = [];
            foreach ($tokens as $token) {
                $like = '%'.$token.'%';
                if ($driver === 'sqlite') {
                    $scoreParts[] = '(CASE WHEN lower(name) LIKE ? OR lower(COALESCE(sku, \'\')) LIKE ? OR lower(COALESCE(description, \'\')) LIKE ? OR lower(COALESCE(category, \'\')) LIKE ? THEN 1 ELSE 0 END)';
                } else {
                    $scoreParts[] = '(CASE WHEN LOWER(name) LIKE ? OR LOWER(COALESCE(sku, \'\')) LIKE ? OR LOWER(COALESCE(description, \'\')) LIKE ? OR LOWER(COALESCE(category, \'\')) LIKE ? THEN 1 ELSE 0 END)';
                }
                array_push($bindings, $like, $like, $like, $like);
            }
            $scoreSql = implode(' + ', $scoreParts);
            $query->select('products.*')
                ->selectRaw("({$scoreSql}) as search_score", $bindings)
                ->orderByDesc('search_score')
                ->orderBy('id');
        }
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\Product>|\Illuminate\Database\Query\Builder  $inner
     */
    private function matchCatalogFields($inner, string $needle): void
    {
        $like = '%'.$needle.'%';
        $inner->where('name', 'like', $like)
            ->orWhere('sku', 'like', $like)
            ->orWhere('description', 'like', $like)
            ->orWhere('category', 'like', $like);
    }

    public function get(Business $business, int $productId): ?array
    {
        $product = $this->query($business)->find($productId);

        return $product ? $this->toToolArray($product) : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?Business $business = null): Product
    {
        $business ??= CurrentBusiness::require();

        $product = Product::query()->create([
            'business_id' => $business->id,
            'name' => $data['name'],
            'slug' => $data['slug'] ?? Str::slug($data['name']).'-'.Str::lower(Str::random(4)),
            'description' => $data['description'] ?? null,
            'sku' => $data['sku'] ?? null,
            'price' => $data['price'],
            'compare_price' => $data['compare_price'] ?? null,
            'stock' => $data['stock'] ?? 0,
            'status' => $data['status'] ?? 'active',
            'type' => $data['type'] ?? 'physical',
            'channel_scope' => $data['channel_scope'] ?? 'all',
            'category' => $data['category'] ?? null,
            'metadata' => $this->metadataFromInput($data, null),
        ]);

        $this->syncRelations($product, $data, $business);

        return $this->fresh($product);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Product $product, array $data, ?Business $business = null): Product
    {
        $business ??= CurrentBusiness::require();
        $fill = array_intersect_key($data, array_flip([
            'name', 'description', 'sku', 'price', 'compare_price', 'stock', 'status', 'type', 'channel_scope', 'category',
        ]));
        $product->fill($fill);
        if (array_key_exists('fulfillment_fields', $data) || array_key_exists('metadata', $data)) {
            $product->metadata = $this->metadataFromInput($data, $product->metadata);
        }
        $product->save();
        $this->syncRelations($product, $data, $business);

        return $this->fresh($product);
    }

    public function addImage(Product $product, UploadedFile $file, bool $main = false, ?int $variantId = null): ProductImage
    {
        if ($variantId) {
            return $this->addVariantImage($product, $file, $variantId);
        }

        if ($product->galleryImages()->count() >= self::GALLERY_LIMIT) {
            throw new RuntimeException('A product can have at most 3 photos.');
        }

        $path = $file->store('products/'.$product->business_id.'/'.$product->id, 'public');
        $hasMain = $product->galleryImages()->where('is_main', true)->exists();
        $sort = (int) $product->galleryImages()->max('sort_order') + 1;

        if ($main || ! $hasMain) {
            $product->galleryImages()->update(['is_main' => false]);
            $main = true;
        }

        return $product->images()->create([
            'path' => $path,
            'sort_order' => $sort,
            'is_main' => $main,
        ]);
    }

    public function setMainImage(Product $product, int $imageId): ProductImage
    {
        $image = $product->galleryImages()->whereKey($imageId)->firstOrFail();
        $product->galleryImages()->update(['is_main' => false]);
        $image->update(['is_main' => true]);

        return $image->fresh();
    }

    public function deleteImage(Product $product, int $imageId): void
    {
        $image = $product->images()->whereKey($imageId)->firstOrFail();
        Storage::disk('public')->delete($image->path);
        $wasMain = $image->is_main && ! $image->variant_id;
        $image->delete();
        if ($wasMain) {
            $next = $product->galleryImages()->orderBy('sort_order')->orderBy('id')->first();
            $next?->update(['is_main' => true]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Product $product): array
    {
        $product->loadMissing(['variants.image', 'galleryImages', 'socialAccounts', 'deliveryZones.wilayas', 'digitalAsset']);
        $gallery = $product->galleryImages;
        $main = $gallery->firstWhere('is_main', true) ?? $gallery->first();

        return [
            'id' => $product->id,
            'name' => $product->name,
            'slug' => $product->slug,
            'description' => $product->description,
            'sku' => $product->sku,
            'price' => (float) $product->price,
            'compare_price' => $product->compare_price !== null ? (float) $product->compare_price : null,
            'stock' => (int) $product->stock,
            'status' => $product->status,
            'type' => $product->type ?: 'physical',
            'channel_scope' => $product->channel_scope ?: 'all',
            'category' => $product->category,
            'main_image_url' => $main?->url(),
            'images' => $gallery->map(fn (ProductImage $image) => [
                'id' => $image->id,
                'url' => $image->url(),
                'is_main' => $image->is_main,
                'sort_order' => $image->sort_order,
            ])->all(),
            'variants' => $product->variants->map(fn (ProductVariant $variant) => [
                'id' => $variant->id,
                'name' => $variant->name,
                'sku' => $variant->sku,
                'price' => $variant->price !== null ? (float) $variant->price : null,
                'stock' => (int) $variant->stock,
                'attributes' => $variant->attributes ?: [],
                'image_url' => $variant->image?->url(),
                'image_id' => $variant->image?->id,
            ])->all(),
            'social_account_ids' => $product->socialAccounts->pluck('id')->all(),
            'channels' => $product->socialAccounts->map(fn ($account) => [
                'id' => $account->id,
                'platform' => $account->platform,
                'name' => $account->name,
                'username' => $account->username,
            ])->all(),
            'delivery_zone_ids' => $product->deliveryZones->pluck('id')->all(),
            'delivery_zones' => $product->deliveryZones->map(fn (DeliveryZone $zone) => $this->delivery->toArray($zone))->all(),
            'digital_asset' => $product->digitalAsset ? [
                'delivery_note' => $product->digitalAsset->delivery_note,
                'access_url' => $product->digitalAsset->access_url,
            ] : null,
            'fulfillment_fields' => $this->fulfillmentFields($product),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toToolArray(Product $product): array
    {
        $product->loadMissing(['variants.image', 'galleryImages', 'deliveryZones', 'digitalAsset', 'business']);
        $gallery = $product->galleryImages;
        $main = $gallery->firstWhere('is_main', true) ?? $gallery->first();
        $zones = $product->isDigital()
            ? collect()
            : ($product->deliveryZones->isNotEmpty()
                ? $product->deliveryZones
                : $this->delivery->list($product->business));

        return [
            'id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'type' => $product->type ?: 'physical',
            'price' => (float) $product->price,
            'currency' => 'DZD',
            'stock' => (int) $product->stock,
            'status' => $product->status,
            'category' => $product->category,
            'description' => $product->description,
            'channel_scope' => $product->channel_scope ?: 'all',
            'main_image_url' => $main?->url(),
            'metadata' => $this->toolMetadata($product),
            'variants' => $product->variants->map(fn (ProductVariant $variant) => [
                'id' => $variant->id,
                'name' => $variant->name,
                'sku' => $variant->sku,
                'price' => $variant->price !== null ? (float) $variant->price : (float) $product->price,
                'stock' => (int) $variant->stock,
                'image_url' => $variant->image?->url(),
            ])->all(),
            'delivery_zones' => $zones->map(fn (DeliveryZone $zone) => [
                'name' => $zone->name,
                'fee' => (float) $zone->fee,
                'days' => $zone->days,
            ])->all(),
            'digital' => $product->isDigital() ? [
                'delivery_note' => $product->digitalAsset?->delivery_note,
                'fulfillment_fields' => $this->fulfillmentFields($product),
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function toolMetadata(Product $product): array
    {
        $meta = is_array($product->metadata) ? $product->metadata : [];
        $fields = $this->fulfillmentFields($product);
        if ($fields !== []) {
            $meta['fulfillment_fields'] = $fields;
        }

        return $meta;
    }

    /**
     * @return list<string>
     */
    private function fulfillmentFields(Product $product): array
    {
        $meta = is_array($product->metadata) ? $product->metadata : [];
        $fields = $meta['fulfillment_fields'] ?? [];
        if (! is_array($fields)) {
            return [];
        }

        $clean = [];
        foreach ($fields as $field) {
            if (is_string($field) && trim($field) !== '') {
                $clean[] = trim($field);
            }
        }

        return array_values(array_unique($clean));
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $existing
     * @return array<string, mixed>|null
     */
    private function metadataFromInput(array $data, mixed $existing): ?array
    {
        $meta = is_array($existing) ? $existing : [];
        if (array_key_exists('fulfillment_fields', $data)) {
            $raw = $data['fulfillment_fields'];
            $clean = [];
            if (is_array($raw)) {
                foreach ($raw as $field) {
                    if (is_string($field) && trim($field) !== '') {
                        $clean[] = trim($field);
                    }
                }
            }
            if ($clean === []) {
                unset($meta['fulfillment_fields']);
            } else {
                $meta['fulfillment_fields'] = array_values(array_unique($clean));
            }
        }

        return $meta === [] ? null : $meta;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncRelations(Product $product, array $data, Business $business): void
    {
        if (array_key_exists('variants', $data) && is_array($data['variants'])) {
            $this->syncVariants($product, $data['variants']);
        }

        if (array_key_exists('social_account_ids', $data)) {
            $scope = $data['channel_scope'] ?? $product->channel_scope;
            if ($scope === 'selected') {
                $ids = $this->ownedAccountIds($business, $data['social_account_ids'] ?? []);
                $product->socialAccounts()->sync($ids);
            } else {
                $product->socialAccounts()->sync([]);
            }
        } elseif (($data['channel_scope'] ?? null) === 'all') {
            $product->socialAccounts()->sync([]);
        }

        if (array_key_exists('delivery_zone_ids', $data)) {
            if ($product->isDigital()) {
                $product->deliveryZones()->sync([]);
            } else {
                $ids = DeliveryZone::query()
                    ->forBusiness($business->id)
                    ->whereIn('id', array_map('intval', $data['delivery_zone_ids'] ?? []))
                    ->pluck('id')
                    ->all();
                $product->deliveryZones()->sync($ids);
            }
        }

        if (array_key_exists('digital_asset', $data)) {
            $asset = is_array($data['digital_asset']) ? $data['digital_asset'] : [];
            if ($product->isDigital()) {
                $product->digitalAsset()->updateOrCreate(
                    ['product_id' => $product->id],
                    [
                        'delivery_note' => $asset['delivery_note'] ?? null,
                        'access_url' => $asset['access_url'] ?? null,
                    ],
                );
            } else {
                $product->digitalAsset()?->delete();
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function syncVariants(Product $product, array $rows): void
    {
        $keep = [];
        foreach ($rows as $row) {
            if (! is_array($row) || trim((string) ($row['name'] ?? '')) === '') {
                continue;
            }
            $payload = [
                'name' => trim((string) $row['name']),
                'sku' => $row['sku'] ?? null,
                'price' => $row['price'] ?? null,
                'stock' => $row['stock'] ?? 0,
                'attributes' => is_array($row['attributes'] ?? null) ? $row['attributes'] : [],
            ];
            if (! empty($row['id'])) {
                $variant = $product->variants()->whereKey($row['id'])->first();
                if ($variant) {
                    $variant->fill($payload)->save();
                    $keep[] = $variant->id;
                    continue;
                }
            }
            $keep[] = $product->variants()->create($payload)->id;
        }
        $product->variants()->whereNotIn('id', $keep)->delete();
    }

    /**
     * @param  mixed  $ids
     * @return list<int>
     */
    private function ownedAccountIds(Business $business, mixed $ids): array
    {
        if (! is_array($ids)) {
            return [];
        }

        return SocialAccount::query()
            ->forBusiness($business->id)
            ->whereIn('id', array_map('intval', $ids))
            ->pluck('id')
            ->all();
    }

    private function addVariantImage(Product $product, UploadedFile $file, int $variantId): ProductImage
    {
        $variant = $product->variants()->whereKey($variantId)->firstOrFail();
        $path = $file->store('products/'.$product->business_id.'/'.$product->id.'/variants', 'public');
        $existing = $product->images()->where('variant_id', $variant->id)->first();
        if ($existing) {
            Storage::disk('public')->delete($existing->path);
            $existing->update(['path' => $path, 'is_main' => false]);

            return $existing->fresh();
        }

        return $product->images()->create([
            'variant_id' => $variant->id,
            'path' => $path,
            'sort_order' => 0,
            'is_main' => false,
        ]);
    }

    private function fresh(Product $product): Product
    {
        return $this->query()->findOrFail($product->id);
    }
}
