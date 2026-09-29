<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiTaskCharge;
use App\Models\Business;
use App\Models\BusinessUser;
use App\Models\User;
use App\Services\Wallet\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiUsageController extends Controller
{
    public function __construct(private WalletService $wallets) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'business_id' => ['nullable', 'integer', 'exists:businesses,id'],
            'task_type' => ['nullable', 'string', 'max:32'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = AiTaskCharge::query()
            ->with([
                'business:id,name,slug',
                'user:id,name,email',
                'actor:id,name,email',
            ])
            ->latest('id');

        if (! empty($data['business_id'])) {
            $query->where('business_id', (int) $data['business_id']);
        }
        if (! empty($data['task_type'])) {
            $query->where('task_type', $data['task_type']);
        }

        $perPage = (int) ($data['per_page'] ?? 50);
        $paginator = $query->paginate($perPage);

        $charges = collect($paginator->items())->map(fn (AiTaskCharge $row) => [
            'id' => $row->id,
            'business_id' => $row->business_id,
            'business_name' => $row->business?->name,
            'business_slug' => $row->business?->slug,
            'task_type' => $row->task_type,
            'model_key' => $row->model_key,
            'provider_model' => $row->provider_model,
            'cost_usd' => (float) $row->cost_usd,
            'cost_da' => (float) $row->cost_da,
            'usd_to_da' => (int) $row->usd_to_da,
            'status' => $row->status,
            'owner_user_id' => $row->user_id,
            'owner_email' => $row->user?->email,
            'actor_user_id' => $row->actor_user_id,
            'actor_email' => $row->actor?->email,
            'reference_type' => $row->reference_type,
            'reference_id' => $row->reference_id,
            'meta' => $row->meta,
            'created_at' => optional($row->created_at)?->toIso8601String(),
        ])->values();

        return response()->json([
            'charges' => $charges,
            'task_types' => AiTaskCharge::taskTypes(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function balances(): JsonResponse
    {
        $businesses = Business::query()
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'status']);

        $ownerIds = BusinessUser::query()
            ->whereIn('business_id', $businesses->pluck('id'))
            ->where('role', 'owner')
            ->whereNull('disabled_at')
            ->orderBy('id')
            ->get(['business_id', 'user_id'])
            ->unique('business_id')
            ->keyBy('business_id');

        $users = User::query()
            ->whereIn('id', $ownerIds->pluck('user_id')->filter()->unique())
            ->get(['id', 'name', 'email', 'wallet_balance_da'])
            ->keyBy('id');

        $rows = $businesses->map(function (Business $b) use ($ownerIds, $users) {
            $ownerId = $ownerIds->get($b->id)?->user_id;
            $owner = $ownerId ? $users->get($ownerId) : null;

            return [
                'business_id' => $b->id,
                'name' => $b->name,
                'slug' => $b->slug,
                'status' => $b->status,
                'owner_user_id' => $owner?->id,
                'owner_name' => $owner?->name,
                'owner_email' => $owner?->email,
                'balance_da' => $owner ? (float) $owner->wallet_balance_da : null,
                'currency' => 'DZD',
                'usd_to_da' => $this->wallets->usdToDaRate(),
            ];
        })->values();

        return response()->json(['balances' => $rows]);
    }
}
