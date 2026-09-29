<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\BusinessUser;
use App\Models\User;
use App\Services\Wallet\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantController extends Controller
{
    public function __construct(private WalletService $wallets) {}

    public function index(): JsonResponse
    {
        $businesses = Business::query()
            ->withCount('users')
            ->latest()
            ->get();

        $ownerIds = BusinessUser::query()
            ->whereIn('business_id', $businesses->pluck('id'))
            ->where('role', 'owner')
            ->whereNull('disabled_at')
            ->orderBy('id')
            ->get(['business_id', 'user_id'])
            ->unique('business_id')
            ->keyBy('business_id');

        $owners = User::query()
            ->whereIn('id', $ownerIds->pluck('user_id')->filter()->unique())
            ->get(['id', 'name', 'email', 'wallet_balance_da'])
            ->keyBy('id');

        $payload = $businesses->map(function (Business $b) use ($ownerIds, $owners) {
            $ownerId = $ownerIds->get($b->id)?->user_id;
            $owner = $ownerId ? $owners->get($ownerId) : null;

            return [
                'id' => $b->id,
                'name' => $b->name,
                'slug' => $b->slug,
                'status' => $b->status,
                'users_count' => $b->users_count,
                'owner_user_id' => $owner?->id,
                'owner_name' => $owner?->name,
                'owner_email' => $owner?->email,
                'wallet_balance_da' => $owner ? (float) $owner->wallet_balance_da : null,
                'usd_to_da' => $this->wallets->usdToDaRate(),
                'created_at' => optional($b->created_at)?->toIso8601String(),
            ];
        })->values();

        return response()->json(['businesses' => $payload]);
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
