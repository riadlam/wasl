<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WalletLedger;
use App\Services\Wallet\WalletService;
use App\Support\CurrentBusiness;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    public function __construct(private WalletService $wallets) {}

    public function show(Request $request): JsonResponse
    {
        $business = CurrentBusiness::require();
        $viewer = $request->user();

        return response()->json([
            'wallet' => $this->wallets->snapshotForBusiness($business, $viewer),
        ]);
    }

    public function ledger(Request $request): JsonResponse
    {
        $business = CurrentBusiness::require();
        $viewer = $request->user();
        $owner = $this->wallets->ownerForBusiness($business);

        abort_unless(
            $viewer->isSuperAdmin() || $viewer->isShopOwner($business->id),
            403,
            'Only the shop owner can view wallet ledger.',
        );

        $rows = WalletLedger::query()
            ->where('user_id', $owner->id)
            ->where(function ($q) use ($business) {
                $q->whereNull('business_id')->orWhere('business_id', $business->id);
            })
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (WalletLedger $row) => [
                'id' => $row->id,
                'direction' => $row->direction,
                'amount_da' => $row->amount_da,
                'usd_amount' => $row->usd_amount,
                'reason' => $row->reason,
                'actor_user_id' => $row->actor_user_id,
                'balance_after' => $row->balance_after,
                'created_at' => optional($row->created_at)?->toIso8601String(),
            ])
            ->values();

        return response()->json([
            'wallet' => $this->wallets->snapshotForBusiness($business, $viewer),
            'ledger' => $rows,
        ]);
    }

    public function topup(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isSuperAdmin(), 403, 'Only super admins can top up wallets.');

        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'amount_da' => ['required_without:tokens', 'nullable', 'numeric', 'min:0.01', 'max:1000000'],
            'tokens' => ['required_without:amount_da', 'nullable', 'numeric', 'min:0.01', 'max:1000000'],
            'business_id' => ['nullable', 'integer', 'exists:businesses,id'],
            'reason' => ['nullable', 'string', 'max:64'],
            'note' => ['nullable', 'string', 'max:240'],
        ]);

        $owner = User::query()->findOrFail($data['user_id']);
        $business = ! empty($data['business_id'])
            ? \App\Models\Business::query()->find($data['business_id'])
            : null;

        $amountDa = (float) ($data['amount_da'] ?? $data['tokens']);
        $entry = $this->wallets->credit(
            $owner,
            $amountDa,
            $data['reason'] ?? 'topup',
            $business,
            $request->user(),
            null,
            array_filter(['note' => $data['note'] ?? null]),
        );

        $fresh = $owner->fresh();
        $balance = $this->wallets->balance($fresh);

        return response()->json([
            'ok' => true,
            'wallet' => [
                'balance_da' => $balance,
                'balance' => $balance,
                'owner_user_id' => $owner->id,
                'currency' => 'DZD',
                'usd_to_da' => $this->wallets->usdToDaRate(),
            ],
            'ledger' => [
                'id' => $entry->id,
                'direction' => $entry->direction,
                'amount_da' => $entry->amount_da,
                'balance_after' => $entry->balance_after,
            ],
        ]);
    }
}
