<?php

namespace App\Services\SocialApi;

use App\Models\Business;
use App\Models\SocialAccount;
use App\Services\OnboardingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

class SocialApiAccountService
{
    public function __construct(
        private SocialApiClient $client,
        private OnboardingService $onboarding,
    ) {}

    /**
     * Ensure this shop has its own SocialAPI brand (isolation boundary).
     * Recreates the brand when the stored id was deleted remotely (common after disconnect).
     *
     * @see https://docs.social-api.ai/guides/concepts
     */
    public function ensureBrand(Business $business): string
    {
        $existing = is_string($business->socialapi_brand_id) ? trim($business->socialapi_brand_id) : '';
        if ($existing !== '' && $this->remoteBrandExists($existing)) {
            return $existing;
        }

        return $this->createBrand($business, $existing !== '' ? $existing : null);
    }

    /**
     * @return array<string, mixed>
     */
    public function connectUrl(Business $business, string $platform, int $userId): array
    {
        $brandId = $this->ensureBrand($business);

        try {
            return $this->postConnect($business, $platform, $userId, $brandId);
        } catch (RuntimeException $e) {
            if (! $this->isBrandMissingError($e)) {
                throw $e;
            }

            // Stale brand slipped past list check (or was deleted mid-request) — recreate once.
            $brandId = $this->createBrand($business, $brandId);

            return $this->postConnect($business, $platform, $userId, $brandId);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function postConnect(Business $business, string $platform, int $userId, string $brandId): array
    {
        return $this->client->post('/accounts/connect', [
            'platform' => $platform,
            'redirect_uri' => config('services.socialapi.redirect_uri'),
            'brand_id' => $brandId,
            'state' => Crypt::encryptString(json_encode([
                'business_id' => $business->id,
                'user_id' => $userId,
                'platform' => $platform,
                'brand_id' => $brandId,
            ])),
        ]);
    }

    private function createBrand(Business $business, ?string $previousBrandId = null): string
    {
        $created = $this->client->post('/brands', [
            'name' => $business->name ?: ('Shop '.$business->id),
        ]);

        $brandId = (string) ($created['id'] ?? $created['data']['id'] ?? '');
        if ($brandId === '') {
            throw new RuntimeException('SocialAPI did not return a brand id.');
        }

        $business->update(['socialapi_brand_id' => $brandId]);

        if ($previousBrandId !== null && $previousBrandId !== '' && $previousBrandId !== $brandId) {
            SocialAccount::query()
                ->where('business_id', $business->id)
                ->where('socialapi_brand_id', $previousBrandId)
                ->update(['socialapi_brand_id' => $brandId]);
        }

        return $brandId;
    }

    private function remoteBrandExists(string $brandId): bool
    {
        try {
            $list = $this->client->get('/brands');
        } catch (\Throwable) {
            // Transient list failure: keep the stored id and let connect fail loudly if needed.
            return true;
        }

        $rows = $list['data'] ?? $list['brands'] ?? [];
        if (! is_array($rows)) {
            return false;
        }

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = (string) ($row['id'] ?? '');
            if ($id !== '' && $id === $brandId) {
                return true;
            }
        }

        return false;
    }

    private function isBrandMissingError(RuntimeException $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'brand not found')
            || str_contains($message, 'brand.not_found')
            || (str_contains($message, 'resource.not_found') && str_contains($message, 'brand'));
    }

    public function decodeState(string $state): array
    {
        $decoded = json_decode(Crypt::decryptString($state), true);

        return is_array($decoded) ? $decoded : [];
    }

    public function fetchAccount(string $accountId, ?string $brandId = null): array
    {
        $accountId = trim($accountId);
        if ($accountId === '') {
            return [];
        }

        // SocialAPI has no GET /accounts/{id} (returns http.405). Use pages + list instead.
        $fromPages = $this->fetchAccountFromPages($accountId);
        if ($fromPages !== []) {
            return $fromPages;
        }

        return $this->fetchAccountFromList($accountId, $brandId);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchAccountFromPages(string $accountId): array
    {
        try {
            $result = $this->client->get('/accounts/'.$accountId.'/pages');
        } catch (\Throwable) {
            return [];
        }

        $pages = $result['data'] ?? $result['pages'] ?? [];
        if (! is_array($pages) || $pages === []) {
            return [];
        }

        $best = null;
        foreach ($pages as $page) {
            if (! is_array($page)) {
                continue;
            }
            if (! empty($page['is_default'])) {
                $best = $page;
                break;
            }
            $best ??= $page;
        }

        if (! is_array($best)) {
            return [];
        }

        return [
            'id' => $accountId,
            'name' => $best['name'] ?? null,
            'username' => $best['username'] ?? $best['page_id'] ?? null,
            'profile_picture_url' => $best['profile_picture_url']
                ?? $best['picture_url']
                ?? $best['avatar_url']
                ?? null,
            'page' => $best,
            'pages' => $pages,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchAccountFromList(string $accountId, ?string $brandId): array
    {
        $query = [];
        if (is_string($brandId) && $brandId !== '') {
            $query['brand_id'] = $brandId;
        }

        try {
            $result = $this->client->get('/accounts', $query);
        } catch (\Throwable) {
            return [];
        }

        $rows = $result['data'] ?? $result['accounts'] ?? [];
        if (! is_array($rows)) {
            return [];
        }

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = (string) ($row['id'] ?? $row['account_id'] ?? '');
            if ($id === $accountId) {
                return $row;
            }
        }

        return [];
    }

    /**
     * Attach a remote SocialAPI account to this shop only when brand matches.
     *
     * @throws RuntimeException when brand isolation would be violated
     */
    public function upsertFromRemote(Business $business, array $remote): SocialAccount
    {
        $accountId = $remote['id'] ?? $remote['account_id'] ?? null;
        if (! $accountId) {
            throw new RuntimeException('Remote account is missing an id.');
        }

        $remoteBrand = isset($remote['brand_id']) ? (string) $remote['brand_id'] : '';
        $shopBrand = is_string($business->socialapi_brand_id) ? $business->socialapi_brand_id : '';

        if ($shopBrand === '') {
            throw new RuntimeException('Shop has no SocialAPI brand; connect a channel first.');
        }

        if ($remoteBrand !== '' && $remoteBrand !== $shopBrand) {
            throw new RuntimeException('This SocialAPI account belongs to another brand.');
        }

        $platform = $this->resolvePlatform($remote);
        $name = $this->resolveAccountName($remote);

        $account = SocialAccount::query()->updateOrCreate(
            [
                'business_id' => $business->id,
                'socialapi_account_id' => $accountId,
            ],
            [
                'provider' => 'socialapi',
                'socialapi_brand_id' => $shopBrand,
                'platform' => $platform,
                'platform_account_id' => $remote['platform_user_id'] ?? $remote['platform_account_id'] ?? null,
                'name' => $name,
                'username' => $remote['username'] ?? null,
                'avatar_url' => $this->pictureUrl($remote),
                'status' => 'connected',
                'metadata' => $remote,
                'connected_at' => now(),
                'disconnected_at' => null,
            ],
        );

        if ($platform === 'facebook') {
            $this->enrichFacebookPageName($account);
        }

        return $account->fresh() ?? $account;
    }

    /**
     * SocialAPI sometimes omits platform on the first webhook; infer from payload / page links.
     *
     * @param  array<string, mixed>  $remote
     */
    public function resolvePlatform(array $remote, ?string $fallback = null): string
    {
        $candidates = [
            $remote['platform'] ?? null,
            $remote['page']['platform'] ?? null,
            is_array($remote['pages'][0] ?? null) ? ($remote['pages'][0]['platform'] ?? null) : null,
            $fallback,
        ];

        foreach ($candidates as $candidate) {
            $platform = strtolower(trim((string) $candidate));
            if ($platform !== '' && $platform !== 'unknown') {
                return $platform;
            }
        }

        $haystack = strtolower(implode(' ', array_filter([
            (string) ($remote['profile_url'] ?? ''),
            (string) ($remote['page']['link'] ?? ''),
            (string) (is_array($remote['pages'][0] ?? null) ? ($remote['pages'][0]['link'] ?? '') : ''),
            (string) ($remote['page']['category'] ?? ''),
        ])));

        if (str_contains($haystack, 'facebook.com') || str_contains($haystack, 'fb.com')) {
            return 'facebook';
        }
        if (str_contains($haystack, 'instagram.com')) {
            return 'instagram';
        }
        if (str_contains($haystack, 'tiktok.com')) {
            return 'tiktok';
        }

        return $fallback && strtolower($fallback) !== 'unknown' ? strtolower($fallback) : 'unknown';
    }

    /**
     * Facebook Account.name is often the personal login; page_name / Pages API hold the Page title.
     *
     * @param  array<string, mixed>  $remote
     */
    public function resolveAccountName(array $remote): ?string
    {
        foreach (['page_name', 'display_name'] as $key) {
            $value = trim((string) ($remote[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        $platform = strtolower((string) ($remote['platform'] ?? ''));
        if ($platform === 'facebook') {
            $fromPages = $this->fetchDefaultPageName((string) ($remote['id'] ?? $remote['account_id'] ?? ''));
            if ($fromPages !== null) {
                return $fromPages;
            }
        }

        foreach (['name', 'username'] as $key) {
            $value = trim((string) ($remote[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    public function enrichFacebookPageName(SocialAccount $account): void
    {
        if (strtolower((string) $account->platform) !== 'facebook') {
            return;
        }

        $accountId = (string) ($account->socialapi_account_id ?? '');
        $pageName = $this->fetchDefaultPageName($accountId);
        if ($pageName === null) {
            $meta = is_array($account->metadata) ? $account->metadata : [];
            $pageName = trim((string) ($meta['page_name'] ?? ''));
            $pageName = $pageName !== '' ? $pageName : null;
        }

        if ($pageName === null || $pageName === (string) $account->name) {
            return;
        }

        $meta = is_array($account->metadata) ? $account->metadata : [];
        $meta['page_name'] = $pageName;

        $account->update([
            'name' => $pageName,
            'metadata' => $meta,
        ]);
    }

    private function fetchDefaultPageName(string $accountId): ?string
    {
        if ($accountId === '') {
            return null;
        }

        try {
            $result = $this->client->get('/accounts/'.$accountId.'/pages');
        } catch (\Throwable) {
            return null;
        }

        $pages = $result['data'] ?? $result['pages'] ?? [];
        if (! is_array($pages) || $pages === []) {
            return null;
        }

        $default = null;
        $first = null;
        foreach ($pages as $page) {
            if (! is_array($page)) {
                continue;
            }
            $name = trim((string) ($page['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $first ??= $name;
            if (! empty($page['is_default'])) {
                $default = $name;
                break;
            }
        }

        return $default ?? $first;
    }

    public function fetchPending(string $connectionId): array
    {
        return $this->client->get('/accounts/pending/'.$connectionId);
    }

    public function selectPending(string $connectionId, array $payload): array
    {
        return $this->client->post('/accounts/pending/'.$connectionId.'/select', $payload);
    }

    /**
     * After Facebook/Google pending select, resolve the connected SocialAPI account
     * for this shop's brand (one Facebook Page → one account_id → one local row).
     *
     * @param  list<string>  $selectedPageIds
     * @return list<SocialAccount>
     */
    public function persistAccountsAfterSelect(
        Business $business,
        array $selectResult,
        array $selectedPageIds = [],
        ?string $loginId = null,
    ): array {
        $brandId = $this->ensureBrand($business);
        $accountIds = $this->extractAccountIds($selectResult);

        // Single-page Facebook: select may return one envelope — resolve via login pages if needed.
        if ($selectedPageIds !== [] && $accountIds === []) {
            $loginId = $loginId
                ?: (isset($selectResult['login_id']) ? (string) $selectResult['login_id'] : null)
                ?: (isset($selectResult['data']['login_id']) ? (string) $selectResult['data']['login_id'] : null);

            if ($loginId) {
                $accountIds = $this->assignedAccountIdsForPages(
                    $loginId,
                    array_slice($selectedPageIds, 0, 1),
                    $brandId,
                );
            }
        }

        $accountIds = array_values(array_unique(array_filter($accountIds)));
        if ($accountIds === [] && (! empty($selectResult['id']) || ! empty($selectResult['account_id']))) {
            $accountIds[] = (string) ($selectResult['id'] ?? $selectResult['account_id']);
        }

        // Hard cap: one local account per select (one Page / channel).
        $accountIds = array_slice($accountIds, 0, 1);

        $pageNameHint = $this->pageNameForSelectedPages($loginId, $selectedPageIds, $brandId);
        if ($pageNameHint === null) {
            $pageNameHint = trim((string) ($selectResult['page_name'] ?? $selectResult['data']['page_name'] ?? ''));
            $pageNameHint = $pageNameHint !== '' ? $pageNameHint : null;
        }

        $saved = [];
        foreach ($accountIds as $accountId) {
            try {
                $remote = $this->fetchAccount($accountId, $brandId);
            } catch (\Throwable) {
                $remote = [];
            }

            if ($remote === []) {
                $remote = array_merge(
                    is_array($selectResult['data'] ?? null) ? $selectResult['data'] : $selectResult,
                    ['id' => $accountId, 'brand_id' => $brandId],
                );
            }

            if (empty($remote['id']) && empty($remote['account_id'])) {
                $remote['id'] = $accountId;
            }

            if (empty($remote['brand_id'])) {
                $remote['brand_id'] = $brandId;
            }

            if ($pageNameHint !== null) {
                $remote['page_name'] = $pageNameHint;
            }

            try {
                $saved[] = $this->upsertFromRemote($business, $remote);
            } catch (\Throwable) {
                // Skip foreign-brand or invalid rows
            }
        }

        return $saved;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAccountsForBrand(string $brandId): array
    {
        $result = $this->client->get('/accounts');
        $rows = [];
        if (isset($result['data']) && is_array($result['data'])) {
            $rows = $result['data'];
        } elseif (isset($result['accounts']) && is_array($result['accounts'])) {
            $rows = $result['accounts'];
        } elseif (array_is_list($result)) {
            $rows = $result;
        }

        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $rowBrand = isset($row['brand_id']) ? (string) $row['brand_id'] : '';
            if ($rowBrand !== '' && $rowBrand === $brandId) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $pageIds
     * @return list<string> SocialAPI account ids
     */
    public function assignedAccountIdsForPages(string $loginId, array $pageIds, string $brandId): array
    {
        $ids = [];
        foreach ($this->matchingLoginPages($loginId, $pageIds, $brandId) as $page) {
            $accId = (string) ($page['assigned_account_id'] ?? $page['account_id'] ?? '');
            if ($accId !== '') {
                $ids[] = $accId;
            }
        }

        return $ids;
    }

    /**
     * @param  list<string>  $pageIds
     */
    private function pageNameForSelectedPages(?string $loginId, array $pageIds, string $brandId): ?string
    {
        if (! is_string($loginId) || $loginId === '' || $pageIds === []) {
            return null;
        }

        foreach ($this->matchingLoginPages($loginId, $pageIds, $brandId) as $page) {
            $name = trim((string) ($page['name'] ?? ''));
            if ($name !== '') {
                return $name;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $pageIds
     * @return list<array<string, mixed>>
     */
    private function matchingLoginPages(string $loginId, array $pageIds, string $brandId): array
    {
        try {
            $result = $this->client->get('/platforms/facebook/logins/'.rawurlencode($loginId).'/pages');
        } catch (\Throwable) {
            return [];
        }

        $pages = $result['pages'] ?? $result['data'] ?? [];
        if (! is_array($pages)) {
            return [];
        }

        $wanted = array_fill_keys(array_map('strval', $pageIds), true);
        $matched = [];
        foreach ($pages as $page) {
            if (! is_array($page)) {
                continue;
            }
            $pageId = (string) ($page['platform_page_id'] ?? '');
            if ($pageId === '' || ! isset($wanted[$pageId])) {
                continue;
            }
            $assignedBrand = (string) ($page['assigned_brand_id'] ?? '');
            if ($assignedBrand !== '' && $assignedBrand !== $brandId) {
                continue;
            }
            $matched[] = $page;
        }

        return $matched;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return list<string>
     */
    public function extractAccountIds(array $result): array
    {
        $ids = [];
        $root = $result;
        if (isset($result['data']) && is_array($result['data']) && ! isset($result['account_id']) && ! isset($result['id'])) {
            $root = $result['data'];
        }

        foreach (['id', 'account_id'] as $key) {
            if (! empty($root[$key]) && is_string($root[$key])) {
                $ids[] = $root[$key];
            }
        }

        foreach (['accounts', 'connected_accounts', 'data'] as $listKey) {
            $list = $root[$listKey] ?? null;
            if (! is_array($list) || ! array_is_list($list)) {
                continue;
            }
            foreach ($list as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $id = $row['id'] ?? $row['account_id'] ?? null;
                if (is_string($id) && $id !== '') {
                    $ids[] = $id;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    public function disconnect(SocialAccount $account): void
    {
        if ($account->socialapi_account_id) {
            try {
                $this->client->delete('/accounts/'.$account->socialapi_account_id);
            } catch (\Throwable) {
                // still mark local row disconnected
            }
        }

        $account->update([
            'status' => 'disconnected',
            'disconnected_at' => now(),
        ]);

        if ($account->business) {
            $this->onboarding->onChannelDisconnected($account->business);
        }
    }

    public function markConnected(array $data): ?SocialAccount
    {
        $accountId = $data['account_id'] ?? null;
        if (! $accountId) {
            return null;
        }

        $account = SocialAccount::query()->where('socialapi_account_id', $accountId)->first();
        if ($account) {
            $business = $account->business;
            $remoteBrand = isset($data['brand_id']) ? (string) $data['brand_id'] : '';
            if ($business && $remoteBrand !== '' && $business->socialapi_brand_id && $remoteBrand !== $business->socialapi_brand_id) {
                return null;
            }

            $merged = array_merge($account->metadata ?? [], $data);
            $platform = $this->resolvePlatform($merged, $account->platform);
            $resolved = $this->resolveAccountName(array_merge($merged, [
                'id' => $accountId,
                'platform' => $platform,
            ]));

            $account->update([
                'status' => 'connected',
                'platform' => $platform,
                'name' => $resolved ?? $account->name,
                'username' => $data['username'] ?? $account->username,
                'avatar_url' => $this->pictureUrl($data) ?? $account->avatar_url,
                'connected_at' => isset($data['connected_at']) ? Carbon::parse($data['connected_at']) : now(),
                'disconnected_at' => null,
                'metadata' => $merged,
            ]);

            $fresh = $account->fresh();
            if ($fresh) {
                $this->enrichFacebookPageName($fresh);
            }

            return $fresh ?? $account;
        }

        // New account from webhook: only map via shop-owned brand id.
        $brandId = isset($data['brand_id']) ? (string) $data['brand_id'] : '';
        if ($brandId === '') {
            return null;
        }

        $business = Business::query()->where('socialapi_brand_id', $brandId)->first();
        if (! $business) {
            return null;
        }

        return $this->upsertFromRemote($business, [
            'id' => $accountId,
            'brand_id' => $brandId,
            'platform' => $this->resolvePlatform($data),
            'platform_user_id' => $data['platform_user_id'] ?? null,
            'page_name' => $data['page_name'] ?? null,
            'display_name' => $data['display_name'] ?? null,
            'username' => $data['username'] ?? null,
            'profile_picture_url' => $data['profile_picture_url'] ?? null,
            'profile_url' => $data['profile_url'] ?? null,
            'page' => $data['page'] ?? null,
            'pages' => $data['pages'] ?? null,
        ]);
    }

    public function markDisconnected(array $data): ?SocialAccount
    {
        $account = SocialAccount::query()->where('socialapi_account_id', $data['account_id'] ?? '')->first();
        if (! $account) {
            return null;
        }

        $account->update([
            'status' => ! empty($data['reconnect_required']) ? 'reconnect_required' : 'disconnected',
            'disconnected_at' => now(),
        ]);

        if ($account->business && empty($data['reconnect_required'])) {
            $this->onboarding->onChannelDisconnected($account->business);
        }

        return $account->fresh();
    }

    /**
     * SocialAPI may return profile_picture_url as a string or nested array.
     *
     * @param  array<string, mixed>  $payload
     */
    private function pictureUrl(array $payload): ?string
    {
        foreach (['profile_picture_url', 'avatar_url', 'picture'] as $key) {
            $value = $payload[$key] ?? null;
            if (is_string($value)) {
                $value = trim($value);
                if ($value !== '' && str_starts_with($value, 'http')) {
                    return $value;
                }
            }
            if (is_array($value)) {
                foreach (['url', 'src', 'href', 'profile_picture_url', 'avatar_url'] as $nestedKey) {
                    $nested = $value[$nestedKey] ?? null;
                    if (is_string($nested)) {
                        $nested = trim($nested);
                        if ($nested !== '' && str_starts_with($nested, 'http')) {
                            return $nested;
                        }
                    }
                }
            }
        }

        return null;
    }
}
