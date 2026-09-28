<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Support\CurrentBusiness;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BusinessController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['business' => $this->toPublicArray(CurrentBusiness::require())]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email'],
            'wilaya' => ['nullable', 'string', 'max:80'],
            'city' => ['nullable', 'string', 'max:80'],
            'currency' => ['nullable', 'string', 'max:8'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'description' => ['nullable', 'string'],
        ]);

        $business = CurrentBusiness::require();
        $business->fill($data)->save();

        return response()->json(['business' => $this->toPublicArray($business)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function toPublicArray(Business $business): array
    {
        return [
            'id' => $business->id,
            'name' => $business->name,
            'letter' => $business->letter(),
            'currency' => $business->currency,
            'timezone' => $business->timezone,
            'wilaya' => $business->wilaya,
            'city' => $business->city,
            'phone' => $business->phone,
            'email' => $business->email,
            'description' => $business->description,
            'status' => $business->status,
            'onboarding_status' => $business->onboarding_status ?? 'onboarding',
        ];
    }
}
