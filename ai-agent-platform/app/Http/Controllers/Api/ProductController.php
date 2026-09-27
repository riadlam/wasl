<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\ProductService;
use App\Support\CurrentBusiness;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class ProductController extends Controller
{
    public function __construct(private ProductService $products) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'products' => $this->products->query()->latest()->get()->map(
                fn (Product $product) => $this->products->toArray($product)
            )->all(),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $product = $this->products->query()->findOrFail($id);

        return response()->json(['product' => $this->products->toArray($product)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, true);

        return response()->json(['product' => $this->products->toArray($this->products->create($data))], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $product = Product::query()->forBusiness(CurrentBusiness::id())->findOrFail($id);
        $data = $this->validated($request, false);

        return response()->json(['product' => $this->products->toArray($this->products->update($product, $data))]);
    }

    public function destroy(int $id): JsonResponse
    {
        Product::query()->forBusiness(CurrentBusiness::id())->findOrFail($id)->delete();

        return response()->json(['ok' => true]);
    }

    public function storeImage(Request $request, int $id): JsonResponse
    {
        $product = Product::query()->forBusiness(CurrentBusiness::id())->findOrFail($id);
        $request->validate([
            'image' => ['required', 'image', 'max:8192'],
            'is_main' => ['sometimes', 'boolean'],
            'variant_id' => ['sometimes', 'nullable', 'integer'],
        ]);
        $variantId = $request->filled('variant_id') ? (int) $request->input('variant_id') : null;
        try {
            $image = $this->products->addImage(
                $product,
                $request->file('image'),
                (bool) $request->boolean('is_main'),
                $variantId,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        $fresh = $this->products->query()->findOrFail($product->id);

        return response()->json([
            'image' => [
                'id' => $image->id,
                'url' => $image->url(),
                'is_main' => $image->is_main,
            ],
            'product' => $this->products->toArray($fresh),
        ], 201);
    }

    public function setMainImage(int $id, int $imageId): JsonResponse
    {
        $product = Product::query()->forBusiness(CurrentBusiness::id())->findOrFail($id);
        $this->products->setMainImage($product, $imageId);
        $fresh = $this->products->query()->findOrFail($product->id);

        return response()->json(['product' => $this->products->toArray($fresh)]);
    }

    public function destroyImage(int $id, int $imageId): JsonResponse
    {
        $product = Product::query()->forBusiness(CurrentBusiness::id())->findOrFail($id);
        $this->products->deleteImage($product, $imageId);
        $fresh = $this->products->query()->findOrFail($product->id);

        return response()->json(['product' => $this->products->toArray($fresh)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:160'],
            'description' => ['nullable', 'string'],
            'sku' => ['nullable', 'string', 'max:80'],
            'price' => [$creating ? 'required' : 'sometimes', 'numeric', 'min:0'],
            'compare_price' => ['nullable', 'numeric', 'min:0'],
            'stock' => ['nullable', 'integer', 'min:0'],
            'status' => ['nullable', 'in:active,draft,archived'],
            'type' => ['nullable', 'in:physical,digital'],
            'channel_scope' => ['nullable', 'in:all,selected'],
            'category' => ['nullable', 'string', 'max:80'],
            'social_account_ids' => ['sometimes', 'array'],
            'social_account_ids.*' => ['integer'],
            'delivery_zone_ids' => ['sometimes', 'array'],
            'delivery_zone_ids.*' => ['integer'],
            'variants' => ['sometimes', 'array'],
            'variants.*.id' => ['nullable', 'integer'],
            'variants.*.name' => ['required_with:variants', 'string', 'max:120'],
            'variants.*.sku' => ['nullable', 'string', 'max:80'],
            'variants.*.price' => ['nullable', 'numeric', 'min:0'],
            'variants.*.stock' => ['nullable', 'integer', 'min:0'],
            'variants.*.attributes' => ['nullable', 'array'],
            'digital_asset' => ['sometimes', 'nullable', 'array'],
            'digital_asset.delivery_note' => ['nullable', 'string', 'max:240'],
            'digital_asset.access_url' => ['nullable', 'string', 'max:500'],
            'fulfillment_fields' => ['sometimes', 'nullable', 'array', 'max:12'],
            'fulfillment_fields.*' => ['string', 'max:40'],
        ]);
    }
}
