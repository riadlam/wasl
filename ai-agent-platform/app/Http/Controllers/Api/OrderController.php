<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\CurrentBusiness;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(private OrderService $orders) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'in:pending,cancelled,shipped,delivered'],
        ]);

        return response()->json($this->orders->list(
            $data['search'] ?? null,
            $data['status'] ?? null,
        ));
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:pending,shipped,delivered,cancelled'],
        ]);

        $result = $this->orders->updateStatus(
            CurrentBusiness::require(),
            $id,
            $data['status'],
        );

        if (empty($result['ok'])) {
            return response()->json([
                'message' => $result['error'] ?? 'Could not update order status.',
            ], 422);
        }

        return response()->json(['order' => $result['order']]);
    }
}
