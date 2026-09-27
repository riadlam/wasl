<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AgentAsset;
use App\Models\SocialAccount;
use App\Services\OnboardingService;
use App\Services\SocialApi\SocialApiAccountService;
use App\Support\CurrentBusiness;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Throwable;

class SocialAccountController extends Controller
{
    public function __construct(
        private SocialApiAccountService $accounts,
        private OnboardingService $onboarding,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $business = CurrentBusiness::require();
        $accounts = $business->socialAccounts()->latest()->get();

        $user = $request->user();
        if ($user instanceof \App\Models\User) {
            $scope = $user->scopedSocialAccountIds($business->id);
            if (is_array($scope)) {
                $allowed = array_fill_keys($scope, true);
                $accounts = $accounts->filter(fn (SocialAccount $account) => isset($allowed[$account->id]))->values();
            }
        }

        return response()->json([
            'accounts' => $accounts->map(function (SocialAccount $account) {
                $account->loadMissing('logoAsset');

                return $account->toChannelApiArray();
            })->values(),
            'redirect_uri' => config('services.socialapi.redirect_uri'),
            'configured' => (string) config('services.socialapi.key') !== '',
        ]);
    }

    public function connect(Request $request): JsonResponse
    {
        $data = $request->validate([
            'platform' => ['required', Rule::in([
                'instagram', 'facebook', 'whatsapp', 'threads', 'tiktok',
                'linkedin', 'twitter', 'youtube', 'google', 'pinterest',
                'telegram', 'bluesky', 'zalo',
            ])],
        ]);

        $business = CurrentBusiness::require();

        if ($data['platform'] === 'facebook' && $this->hasConnectedFacebook($business->id)) {
            return response()->json([
                'message' => 'This shop already has a Facebook Page connected. Disconnect it before linking another.',
            ], 422);
        }

        try {
            $result = $this->accounts->connectUrl($business, $data['platform'], $request->user()->id);
        } catch (Throwable $e) {
            return response()->json([
                'message' => $this->friendlyError($e, 'Could not start channel connect. Check that channel connect is configured and APP_URL uses HTTPS.'),
            ], 422);
        }

        return response()->json([
            'auth_url' => $result['auth_url'] ?? null,
            'state' => $result['state'] ?? null,
            'metadata' => $result['metadata'] ?? null,
            'message' => $result['message'] ?? null,
            'platform' => $data['platform'],
        ]);
    }

    public function pending(string $connectionId): JsonResponse
    {
        try {
            $pending = $this->accounts->fetchPending($connectionId);
        } catch (Throwable $e) {
            return response()->json([
                'message' => $this->friendlyError($e, 'Pending connection expired or was not found. Start connect again.'),
            ], 422);
        }

        // SocialAPI sometimes nests the payload under data.
        if (isset($pending['data']) && is_array($pending['data']) && ! isset($pending['pages']) && ! isset($pending['profiles'])) {
            $pending = array_merge($pending, $pending['data']);
        }

        return response()->json([
            'pending' => $pending,
            'pages' => $pending['pages'] ?? [],
            'profiles' => $pending['profiles'] ?? [],
        ]);
    }

    public function selectPending(Request $request): JsonResponse
    {
        $data = $request->validate([
            'connection_id' => ['required', 'string'],
            'page_ids' => ['nullable', 'array', 'max:1'],
            'page_ids.*' => ['string'],
            'page_id' => ['nullable', 'string'],
            'platform_account_id' => ['nullable', 'string'],
            'profile_id' => ['nullable', 'string'],
            'login_id' => ['nullable', 'string'],
        ]);

        $pageIds = $data['page_ids'] ?? (! empty($data['page_id']) ? [$data['page_id']] : []);
        $pageIds = array_values(array_unique(array_filter(array_map('strval', $pageIds))));

        if (count($pageIds) > 1) {
            return response()->json([
                'message' => 'Select exactly one Facebook Page. Each channel links a single Page.',
            ], 422);
        }

        $business = CurrentBusiness::require();

        if ($pageIds !== [] && $this->hasConnectedFacebook($business->id)) {
            return response()->json([
                'message' => 'This shop already has a Facebook Page connected. Disconnect it before linking another.',
            ], 422);
        }

        $payload = array_filter([
            'page_ids' => $pageIds !== [] ? $pageIds : null,
            'platform_account_id' => $data['platform_account_id'] ?? $data['profile_id'] ?? null,
        ], fn ($value) => $value !== null && $value !== []);

        try {
            $result = $this->accounts->selectPending($data['connection_id'], $payload);
        } catch (Throwable $e) {
            return response()->json([
                'message' => $this->friendlyError($e, 'Could not finish the Page / profile selection.'),
            ], 422);
        }

        $saved = $this->accounts->persistAccountsAfterSelect(
            $business,
            $result,
            $pageIds,
            $data['login_id'] ?? null,
        );

        if ($saved !== []) {
            $this->onboarding->onChannelConnected($business, $saved[0]);
            $this->onboarding->ensureProfilesForAccounts($business, $saved);
        }

        return response()->json([
            'account' => $result,
            'accounts' => collect($saved)->map(fn (SocialAccount $account) => [
                'id' => $account->id,
                'platform' => $account->platform,
                'name' => $account->name,
                'username' => $account->username,
                'socialapi_account_id' => $account->socialapi_account_id,
                'status' => $account->status,
            ])->values(),
            'count' => count($saved),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $account = SocialAccount::query()
            ->forBusiness(CurrentBusiness::id())
            ->where('provider', 'socialapi')
            ->findOrFail($id);

        $this->accounts->disconnect($account);

        return response()->json(['ok' => true]);
    }

    /**
     * Upload a page logo for AI creatives (overrides SocialAPI avatar when set).
     */
    public function uploadLogo(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
        ]);

        $business = CurrentBusiness::require();
        $account = SocialAccount::query()
            ->forBusiness($business->id)
            ->where('provider', 'socialapi')
            ->findOrFail($id);

        $file = $data['file'];
        $path = $file->store('channel-logos/'.$business->id, 'public');

        $asset = AgentAsset::query()->create([
            'business_id' => $business->id,
            'agent_id' => $business->agent?->id,
            'disk' => 'public',
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime' => $file->getClientMimeType() ?: $file->getMimeType(),
            'size' => $file->getSize() ?: 0,
        ]);

        $previousId = $account->logo_asset_id;
        $account->update(['logo_asset_id' => $asset->id]);

        if ($previousId && $previousId !== $asset->id) {
            $old = AgentAsset::query()->forBusiness($business->id)->find($previousId);
            if ($old) {
                Storage::disk($old->disk ?: 'public')->delete($old->path);
                $old->delete();
            }
        }

        $account->load('logoAsset');

        return response()->json([
            'account' => $account->toChannelApiArray(),
        ]);
    }

    public function clearLogo(int $id): JsonResponse
    {
        $business = CurrentBusiness::require();
        $account = SocialAccount::query()
            ->forBusiness($business->id)
            ->where('provider', 'socialapi')
            ->findOrFail($id);

        $previousId = $account->logo_asset_id;
        $account->update(['logo_asset_id' => null]);

        if ($previousId) {
            $old = AgentAsset::query()->forBusiness($business->id)->find($previousId);
            if ($old) {
                Storage::disk($old->disk ?: 'public')->delete($old->path);
                $old->delete();
            }
        }

        $account->load('logoAsset');

        return response()->json([
            'account' => $account->toChannelApiArray(),
        ]);
    }

    /**
     * Re-fetch profile picture from SocialAPI into avatar_url (does not clear custom logo).
     */
    public function refreshAvatar(int $id): JsonResponse
    {
        $business = CurrentBusiness::require();
        $account = SocialAccount::query()
            ->forBusiness($business->id)
            ->where('provider', 'socialapi')
            ->findOrFail($id);

        try {
            $remote = $this->accounts->fetchAccount(
                (string) $account->socialapi_account_id,
                $business->socialapi_brand_id ? (string) $business->socialapi_brand_id : null,
            );
        } catch (Throwable $e) {
            report($e);
            try {
                $msg = $this->friendlyError($e, 'Could not refresh the page picture from SocialAPI.');
            } catch (Throwable) {
                $msg = 'Could not refresh the page picture from SocialAPI.';
            }

            return response()->json(['message' => $msg], 422);
        }

        if (isset($remote['data']) && is_array($remote['data'])) {
            $remote = array_merge($remote, $remote['data']);
        }

        if ($remote === []) {
            return response()->json([
                'message' => 'SocialAPI has no picture for this page yet. Click the avatar to upload a logo instead.',
            ], 422);
        }

        $picture = $this->firstUrl(
            $remote['profile_picture_url'] ?? null,
            $remote['avatar_url'] ?? null,
            $remote['picture'] ?? null,
        );
        if ($picture !== null) {
            $account->update(['avatar_url' => $picture]);
        }

        $account->load('logoAsset');

        return response()->json([
            'account' => $account->fresh(['logoAsset'])?->toChannelApiArray() ?? $account->toChannelApiArray(),
            'refreshed_from_api' => $picture !== null,
        ]);
    }

    /**
     * SocialAPI sometimes returns picture as string URL, sometimes as nested array.
     */
    private function firstUrl(mixed ...$candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (is_string($candidate)) {
                $candidate = trim($candidate);
                if ($candidate !== '' && str_starts_with($candidate, 'http')) {
                    return $candidate;
                }
            }
            if (is_array($candidate)) {
                foreach (['url', 'src', 'href', 'profile_picture_url', 'avatar_url'] as $key) {
                    if (! empty($candidate[$key]) && is_string($candidate[$key])) {
                        $url = trim($candidate[$key]);
                        if ($url !== '' && str_starts_with($url, 'http')) {
                            return $url;
                        }
                    }
                }
                foreach ($candidate as $nested) {
                    $found = $this->firstUrl($nested);
                    if ($found !== null) {
                        return $found;
                    }
                }
            }
        }

        return null;
    }

    private function friendlyError(Throwable $e, string $fallback): string
    {
        $message = $e->getMessage();
        if (str_contains($message, 'SocialAPI request failed:')) {
            $body = trim(str_replace('SocialAPI request failed:', '', $message));
            $json = json_decode($body, true);
            if (is_array($json)) {
                $detail = $json['message'] ?? $json['error'] ?? $json['errors'] ?? null;

                return $this->stringifyApiDetail($detail, $fallback);
            }
            if ($body !== '') {
                return $body;
            }
        }

        return $fallback;
    }

    private function stringifyApiDetail(mixed $detail, string $fallback): string
    {
        if (is_string($detail) && trim($detail) !== '') {
            return trim($detail);
        }
        if (is_numeric($detail)) {
            return (string) $detail;
        }
        if (is_array($detail)) {
            $flat = [];
            array_walk_recursive($detail, function ($v) use (&$flat) {
                if (is_string($v) && trim($v) !== '') {
                    $flat[] = trim($v);
                } elseif (is_numeric($v)) {
                    $flat[] = (string) $v;
                }
            });
            if ($flat !== []) {
                return implode(' ', array_unique($flat));
            }
        }

        return $fallback;
    }

    private function hasConnectedFacebook(int $businessId): bool
    {
        return SocialAccount::query()
            ->where('business_id', $businessId)
            ->where('provider', 'socialapi')
            ->where('platform', 'facebook')
            ->where('status', '!=', 'disconnected')
            ->exists();
    }
}
