<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantController extends Controller
{
    public function index(): JsonResponse
    {
        $businesses = Business::query()
            ->withCount('users')
            ->latest()
            ->get();

        return response()->json(['businesses' => $businesses]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:active,suspended'],
        ]);
        $business = Business::query()->findOrFail($id);
        $business->update($data);

        return response()->json(['business' => $business]);
    }

    public function impersonate(Request $request, int $id): JsonResponse
    {
        $business = Business::query()->findOrFail($id);
        $request->session()->put('impersonated_business_id', $business->id);
        $request->session()->put('current_business_id', $business->id);

        return response()->json([
            'ok' => true,
            'redirect' => '/space',
            'business' => $business,
        ]);
    }

    public function stopImpersonation(Request $request): JsonResponse
    {
        $request->session()->forget(['impersonated_business_id', 'current_business_id']);

        return response()->json(['ok' => true, 'redirect' => '/admin']);
    }
}
