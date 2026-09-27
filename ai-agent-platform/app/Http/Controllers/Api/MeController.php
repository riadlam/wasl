<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Wallet\WalletService;
use App\Support\CurrentBusiness;
use App\Support\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function __construct(private WalletService $wallets) {}

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $business = CurrentBusiness::get();
        $membership = $business ? $user->membershipFor($business->id) : $user->memberships()->whereNull('disabled_at')->first();

        if (! $business && $membership) {
            $business = $membership->business;
        }

        $wallet = null;
        if ($business) {
            try {
                $wallet = $this->wallets->snapshotForBusiness($business, $user);
            } catch (\Throwable) {
                $wallet = null;
            }
        }

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'platform_role' => $user->platform_role,
            ],
            'business' => $business ? [
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
                'onboarding_training' => $business->onboarding_meta,
            ] : null,
            'wallet' => $wallet,
            'role' => $user->isSuperAdmin() && $request->hasSession() && $request->session()->get('impersonated_business_id')
                ? 'owner'
                : ($membership?->role),
            'permissions' => $user->permissionList($business?->id),
            'permission_catalog' => Permission::catalog(),
            'impersonating' => $request->hasSession() && (bool) $request->session()->get('impersonated_business_id'),
        ]);
    }
}
