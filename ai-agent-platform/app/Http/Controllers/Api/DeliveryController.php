<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DeliveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class DeliveryController extends Controller
{
    public function __construct(private DeliveryService $delivery) {}

    public function wilayas(): JsonResponse
    {
        return response()->json(['wilayas' => $this->delivery->wilayas()]);
    }

    public function index(): JsonResponse
    {
        return response()->json([
            'zones' => $this->delivery->list()->map(fn ($zone) => $this->delivery->toArray($zone))->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, true);

        try {
            $zone = $this->delivery->save($data);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['zone' => $this->delivery->toArray($zone)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $data = $this->validated($request, false);
        $data['id'] = $id;

        try {
            $zone = $this->delivery->save($data);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['zone' => $this->delivery->toArray($zone)]);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->delivery->delete($id);

        return response()->json(['ok' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:80'],
            'fee' => [$creating ? 'required' : 'sometimes', 'numeric', 'min:0'],
            'days' => ['nullable', 'string', 'max:40'],
            'wilaya_ids' => [$creating ? 'required' : 'sometimes', 'array', 'min:1'],
            'wilaya_ids.*' => ['integer', 'exists:wilayas,id'],
        ]);
    }
}
