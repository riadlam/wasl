<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PaymentMethodService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class PaymentMethodController extends Controller
{
    public function __construct(private PaymentMethodService $payments) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'methods' => $this->payments->listForUi(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'methods' => ['required', 'array', 'size:3'],
            'methods.*.method' => ['required', 'string', 'in:flexy,baridimob,ccp'],
            'methods.*.enabled' => ['required', 'boolean'],
            'methods.*.priority' => ['nullable', 'integer', 'min:1', 'max:3'],
            'methods.*.phone' => ['nullable', 'string', 'max:40'],
            'methods.*.ccp_cle' => ['nullable', 'string', 'max:16'],
            'methods.*.ccp_number' => ['nullable', 'string', 'max:64'],
        ]);

        try {
            $methods = $this->payments->saveAll($data['methods']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['methods' => $methods]);
    }
}
