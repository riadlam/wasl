<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\BusinessUser;
use App\Models\SocialAccount;
use App\Models\User;
use App\Support\CurrentBusiness;
use App\Support\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TeamController extends Controller
{
    public function index(): JsonResponse
    {
        $business = CurrentBusiness::require();
        $members = $business->members()->with('user')->get()->map(fn (BusinessUser $m) => $this->present($m));

        return response()->json([
            'members' => $members,
            'catalog' => Permission::catalog(),
            'channels' => $this->shopChannels($business),
            'presets' => $this->presets(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $business = CurrentBusiness::require();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8'],
            'permissions' => ['array'],
            'permissions.*' => [Rule::in(Permission::values())],
            'channel_ids' => ['required', 'array', 'min:1'],
            'channel_ids.*' => ['integer'],
        ]);

        $channelIds = $this->validatedChannelIds($business, $data['channel_ids']);

        $user = User::query()->where('email', $data['email'])->first();
        if (! $user) {
            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'status' => 'active',
                'platform_role' => 'user',
            ]);
        }

        abort_if(
            $business->members()->where('user_id', $user->id)->exists(),
            422,
            'This person is already on the team.',
        );

        $member = BusinessUser::query()->create([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'role' => 'staff',
            'permissions' => array_values($data['permissions'] ?? []),
            'channel_ids' => $channelIds,
        ]);

        return response()->json(['member' => $this->present($member->load('user'))], 201);
    }

    public function update(Request $request, int $userId): JsonResponse
    {
        $business = CurrentBusiness::require();
        $member = $business->members()->where('user_id', $userId)->firstOrFail();
        abort_if($member->role === 'owner', 422, 'Cannot edit the shop owner this way.');

        $data = $request->validate([
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => [Rule::in(Permission::values())],
            'channel_ids' => ['sometimes', 'array', 'min:1'],
            'channel_ids.*' => ['integer'],
            'disabled' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('permissions', $data)) {
            $member->permissions = array_values($data['permissions']);
        }
        if (array_key_exists('channel_ids', $data)) {
            $member->channel_ids = $this->validatedChannelIds($business, $data['channel_ids']);
        }
        if (array_key_exists('disabled', $data)) {
            $member->disabled_at = $data['disabled'] ? now() : null;
        }
        $member->save();

        return response()->json(['member' => $this->present($member->load('user'))]);
    }

    public function destroy(int $userId): JsonResponse
    {
        $business = CurrentBusiness::require();
        $member = $business->members()->where('user_id', $userId)->firstOrFail();
        abort_if($member->role === 'owner', 422, 'Cannot remove the shop owner.');
        $member->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * @param  list<int|string>  $ids
     * @return list<int>
     */
    private function validatedChannelIds(Business $business, array $ids): array
    {
        $wanted = array_values(array_unique(array_map('intval', $ids)));
        if ($wanted === []) {
            throw ValidationException::withMessages([
                'channel_ids' => ['Assign at least one channel.'],
            ]);
        }

        $allowed = SocialAccount::query()
            ->where('business_id', $business->id)
            ->where('platform', '!=', 'simulator')
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', '!=', 'disconnected');
            })
            ->whereIn('id', $wanted)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (count($allowed) !== count($wanted)) {
            throw ValidationException::withMessages([
                'channel_ids' => ['One or more channels are not part of this shop.'],
            ]);
        }

        return $allowed;
    }

    /**
     * @return list<array{id: int, platform: string, name: ?string, username: ?string}>
     */
    private function shopChannels(Business $business): array
    {
        return $business->socialAccounts()
            ->where('platform', '!=', 'simulator')
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', '!=', 'disconnected');
            })
            ->orderBy('platform')
            ->orderBy('name')
            ->get()
            ->map(fn (SocialAccount $account) => [
                'id' => $account->id,
                'platform' => $account->platform,
                'name' => $account->name,
                'username' => $account->username,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{key: string, label: string, permissions: list<string>}>
     */
    private function presets(): array
    {
        return [
            [
                'key' => 'inbox',
                'label' => 'Inbox',
                'permissions' => [Permission::InboxView->value, Permission::InboxReply->value],
            ],
            [
                'key' => 'catalog',
                'label' => 'Catalog',
                'permissions' => [Permission::ProductsView->value],
            ],
            [
                'key' => 'shop_ops',
                'label' => 'Shop ops',
                'permissions' => [
                    Permission::OrdersView->value,
                    Permission::AgentsView->value,
                ],
            ],
        ];
    }

    private function present(BusinessUser $member): array
    {
        $channelIds = null;
        if ($member->role !== 'owner' && is_array($member->channel_ids)) {
            $channelIds = array_values(array_map('intval', $member->channel_ids));
        }

        return [
            'id' => $member->id,
            'user_id' => $member->user_id,
            'name' => $member->user?->name,
            'email' => $member->user?->email,
            'role' => $member->role,
            'permissions' => $member->role === 'owner' ? Permission::values() : ($member->permissions ?? []),
            'channel_ids' => $member->role === 'owner' ? null : $channelIds,
            'wallet' => 'shop',
            'disabled' => (bool) $member->disabled_at,
        ];
    }
}
