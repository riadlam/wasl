<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AgentAsset;
use App\Models\SocialAccount;
use App\Services\OnboardingService;
use App\Services\SocialApi\SocialApiAccountService;
use App\Support\CurrentBusiness;
use App\Support\MerchantSafeMessage;
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
            report($e);

            return response()->json([
                'message' => 'Could not start channel connect. Try again or contact Wasl support.',
            ], 422);
        }

        $metadata = isset($result['metadata']) && is_array($result['metadata'])
            ? $this->publicConnectMetadata($result['metadata'])
            : null;

        return response()->json([
            'auth_url' => is_string($result['auth_url'] ?? null) && $result['auth_url'] !== ''
                ? $result['auth_url']
                : null,
            'state' => is_string($result['state'] ?? null) ? $result['state'] : null,
            'platform' => $data['platform'],
            'metadata' => $metadata,
            'message' => is_string($result['message'] ?? null) ? $result['message'] : null,
        ]);
    }

    /**
     * Complete WhatsApp Embedded Signup after Meta popup returns code + WABA ids.
     */
    public function completeWhatsApp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string'],
            'state' => ['required', 'string'],
            'waba_id' => ['required', 'string'],
            'phone_number_id' => ['required', 'string'],
        ]);

        $business = CurrentBusiness::require();

        try {
            $saved = $this->accounts->completeWhatsAppEmbedded(
                $business,
                $data['code'],
                $data['state'],
                $data['waba_id'],
                $data['phone_number_id'],
            );
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Could not finish WhatsApp connect. Try again or contact Wasl support.',
            ], 422);
        }

        $this->onboarding->onChannelConnected($business, $saved);
        $this->onboarding->ensureProfilesForAccounts($business, [$saved]);

        $saved->loadMissing('logoAsset');

        return response()->json([
            'account' => $saved->toChannelApiArray(),
            'ok' => true,
        ]);
    }

    public function pending(string $connectionId): JsonResponse
    {
        try {
            $pending = $this->accounts->fetchPending($connectionId);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Pending connection expired or was not found. Start connect again.',
            ], 422);
        }

        // Provider sometimes nests the payload under data.
        if (isset($pending['data']) && is_array($pending['data']) && ! isset($pending['pages']) && ! isset($pending['profiles'])) {
            $pending = array_merge($pending, $pending['data']);
        }

        return response()->json([
            'pages' => $this->publicPages($pending['pages'] ?? []),
            'profiles' => $this->publicProfiles($pending['profiles'] ?? []),
            'login_id' => isset($pending['login_id']) && is_string($pending['login_id'])
                ? $pending['login_id']
                : null,
            'platform' => isset($pending['platform']) && is_string($pending['platform'])
                ? $pending['platform']
                : null,
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
            report($e);

            return response()->json([
                'message' => 'Could not finish the Page / profile selection.',
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
            'accounts' => collect($saved)->map(function (SocialAccount $account) {
                $account->loadMissing('logoAsset');

                return $account->toChannelApiArray();
            })->values(),
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

            return response()->json(['message' => 'Could not refresh the page picture.'], 422);
        }

        if (isset($remote['data']) && is_array($remote['data'])) {
            $remote = array_merge($remote, $remote['data']);
        }

        if ($remote === []) {
            return response()->json([
                'message' => 'No page picture found yet. Click the avatar to upload a logo instead.',
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

    /**
     * @param  list<mixed>  $pages
     * @return list<array{platform_page_id: string, name: ?string, assignable: bool}>
     */
    private function publicPages(array $pages): array
    {
        $out = [];
        foreach ($pages as $page) {
            if (! is_array($page)) {
                continue;
            }
            $id = (string) ($page['platform_page_id'] ?? $page['id'] ?? $page['page_id'] ?? '');
            if ($id === '') {
                continue;
            }
            $out[] = [
                'platform_page_id' => $id,
                'name' => isset($page['name']) && is_string($page['name']) ? $page['name'] : null,
                'assignable' => array_key_exists('assignable', $page) ? (bool) $page['assignable'] : true,
            ];
        }

        return $out;
    }

    /**
     * @param  list<mixed>  $profiles
     * @return list<array{platform_account_id: string, display_name: ?string}>
     */
    private function publicProfiles(array $profiles): array
    {
        $out = [];
        foreach ($profiles as $profile) {
            if (! is_array($profile)) {
                continue;
            }
            $id = (string) ($profile['platform_account_id'] ?? $profile['id'] ?? $profile['profile_id'] ?? '');
            if ($id === '') {
                continue;
            }
            $out[] = [
                'platform_account_id' => $id,
                'display_name' => isset($profile['display_name']) && is_string($profile['display_name'])
                    ? $profile['display_name']
                    : (isset($profile['name']) && is_string($profile['name']) ? $profile['name'] : null),
            ];
        }

        return $out;
    }

    private function friendlyError(Throwable $e, string $fallback): string
    {
        return MerchantSafeMessage::of($e->getMessage(), $fallback);
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

    /**
     * Only expose Meta Embedded Signup fields the browser needs (never secrets).
     *
     * @param  array<string, mixed>  $metadata
     * @return array{app_id?: string, config_id?: string, solution_id?: string}
     */
    private function publicConnectMetadata(array $metadata): array
    {
        $out = [];
        foreach (['app_id', 'config_id', 'solution_id'] as $key) {
            if (isset($metadata[$key]) && (is_string($metadata[$key]) || is_numeric($metadata[$key]))) {
                $value = trim((string) $metadata[$key]);
                if ($value !== '') {
                    $out[$key] = $value;
                }
            }
        }

        return $out;
    }
}
