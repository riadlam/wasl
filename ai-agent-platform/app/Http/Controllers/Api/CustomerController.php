<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CustomerService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function __construct(private CustomerService $customers) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'in:10,25,50'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        return response()->json($this->customers->list(
            $data['search'] ?? null,
            (int) ($data['per_page'] ?? 25),
            (int) ($data['page'] ?? 1),
        ));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'wilaya' => ['nullable', 'string', 'max:80'],
            'commune' => ['nullable', 'string', 'max:120'],
        ]);

        try {
            $customer = $this->customers->updateDetails($id, $data);
        } catch (ModelNotFoundException) {
            return response()->json(['message' => 'Contact not found.'], 404);
        }

        return response()->json(['customer' => $customer]);
    }
}
