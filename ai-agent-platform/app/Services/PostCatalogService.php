<?php

namespace App\Services;

use App\Models\Business;
use App\Models\PostAiSetting;
use App\Models\SocialAccount;
use App\Services\SocialApi\SocialApiPostsService;
use App\Support\CurrentBusiness;
use Illuminate\Support\Facades\Cache;

class PostCatalogService
{
    public function __construct(
        private SocialApiPostsService $posts,
        private PostMediaService $media,
    ) {}

    /**
     * @return array{posts: list<array<string, mixed>>, pagination: array{has_more: bool, next_cursor: ?string}, meta: array<string, mixed>}
     */
    public function list(
        ?string $accountId = null,
        ?string $platform = null,
        ?string $search = null,
        ?string $cursor = null,
        int $limit = 20,
        ?Business $business = null,
        ?string $source = null,
    ): array {
        $business ??= CurrentBusiness::require();
        $accounts = $this->linkedAccounts($business, $accountId, $platform);
        $limit = max(1, min(20, $limit));

        if ($accounts->isEmpty()) {
            return [
                'posts' => [],
                'pagination' => ['has_more' => false, 'next_cursor' => null],
                'meta' => [
                    'accounts' => [],
                    'search' => $search,
                    'source' => null,
                ],
            ];
        }

        $remoteIds = $accounts
            ->pluck('socialapi_account_id')
            ->filter()
            ->values()
            ->all();

        $search = is_string($search) ? trim($search) : '';
        $search = $search !== '' ? $search : null;
        $source = in_array($source, ['posts', 'inbox_comments'], true) ? $source : null;

        // Prefer inbox commented-posts for browse (organic posts that get comments).
        // Use GET /v1/posts for search, or when the client continues a /posts cursor.
        // Never call /accounts/{id}/posts — SocialAPI returns http.404.
        if ($search !== null) {
            $source = 'posts';
        } elseif ($source === null) {
            $source = 'inbox_comments';
        }

        $singleRemote = $accounts->count() === 1
            ? (string) $accounts->first()->socialapi_account_id
            : null;

        try {
            if ($source === 'inbox_comments') {
                $remote = $this->posts->listCommentedPosts(
                    $singleRemote,
                    $platform,
                    $cursor,
                    $limit,
                );
            } else {
                $remote = $this->posts->listPublishedPosts(
                    $remoteIds,
                    $search,
                    $cursor,
                    $limit,
                    $platform,
                );
            }
            $normalized = $this->posts->normalizeList($remote);
        } catch (\Throwable $e) {
            // If inbox comments fails on first page, fall back to /posts.
            if ($source === 'inbox_comments' && ($cursor === null || $cursor === '')) {
                $source = 'posts';
                $remote = $this->posts->listPublishedPosts(
                    $remoteIds,
                    null,
                    null,
                    $limit,
                    $platform,
                );
                $normalized = $this->posts->normalizeList($remote);
            } else {
                throw $e;
            }
        }

        // Empty inbox comments on first browse page → try published /posts library.
        if (
            $normalized['posts'] === []
            && $source === 'inbox_comments'
            && $search === null
            && ($cursor === null || $cursor === '')
        ) {
            try {
                $remote = $this->posts->listPublishedPosts(
                    $remoteIds,
                    null,
                    null,
                    $limit,
                    $platform,
                );
                $normalized = $this->posts->normalizeList($remote);
                $source = 'posts';
            } catch (\Throwable) {
                // Keep empty inbox result.
            }
        }

        $byRemote = $accounts->keyBy('socialapi_account_id');

        $mapped = [];
        foreach ($normalized['posts'] as $post) {
            $account = $byRemote->get($post['account_id'] ?? '');
            if (! $account && $accounts->count() === 1) {
                $account = $accounts->first();
            }
            if (! $account) {
                $account = $accounts->firstWhere('platform', $post['platform'] ?? null);
            }
            if (! $account) {
                continue;
            }

            $post['account_id'] = $account->id;
            $post['socialapi_account_id'] = $account->socialapi_account_id;
            $post['account_name'] = $account->name;
            $post['platform'] = $post['platform'] ?: $account->platform;
            $mapped[] = $post;
        }

        $mapped = $this->applyCachedMetrics($mapped);
        $mapped = $this->media->enrichFromCache($mapped);

        $flags = app(WorkflowService::class)->engagementFlagsForPosts($business->id, $mapped);
        $posts = array_map(function (array $post) use ($flags) {
            $key = $post['account_id'].':'.$post['platform_post_id'];
            $flag = $flags[$key] ?? null;

            return array_merge($post, [
                'workflow_id' => $flag['workflow_id'] ?? null,
                'ai_comment_reply' => (bool) ($flag['ai_comment_reply'] ?? false),
                'ai_private_reply' => (bool) ($flag['ai_private_reply'] ?? false),
                'comment_mode' => $flag['comment_mode'] ?? 'agent',
                'fixed_comment_text' => $flag['fixed_comment_text'] ?? null,
                'fixed_comment_image_path' => $flag['fixed_comment_image_path'] ?? null,
                'fixed_comment_image_url' => $flag['fixed_comment_image_url'] ?? null,
                'dm_mode' => $flag['dm_mode'] ?? 'agent',
                'fixed_dm_text' => $flag['fixed_dm_text'] ?? null,
            ]);
        }, $mapped);

        return [
            'posts' => $posts,
            'pagination' => $normalized['pagination'],
            'meta' => [
                'accounts' => $accounts->map(fn (SocialAccount $account) => [
                    'id' => $account->id,
                    'platform' => $account->platform,
                    'name' => $account->name,
                    'username' => $account->username,
                    'avatar_url' => $account->avatar_url,
                    'socialapi_account_id' => $account->socialapi_account_id,
                    'status' => $account->status,
                    'connected_at' => $account->connected_at,
                ])->values()->all(),
                'search' => $search,
                'source' => $source,
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    public function upsertSettings(array $items, ?Business $business = null): array
    {
        $business ??= CurrentBusiness::require();
        $saved = [];

        foreach ($items as $item) {
            $account = SocialAccount::query()
                ->forBusiness($business->id)
                ->where('id', (int) ($item['account_id'] ?? 0))
                ->where('provider', 'socialapi')
                ->first();
            $platformPostId = trim((string) ($item['platform_post_id'] ?? ''));
            if (! $account || $platformPostId === '') {
                continue;
            }

            $payload = [
                'business_id' => $business->id,
                'social_account_id' => $account->id,
                'platform_post_id' => $platformPostId,
            ];
            if (array_key_exists('ai_comment_reply', $item)) {
                $payload['ai_comment_reply'] = (bool) $item['ai_comment_reply'];
            }
            if (array_key_exists('ai_private_reply', $item)) {
                $payload['ai_private_reply'] = (bool) $item['ai_private_reply'];
            }
            if (array_key_exists('comment_mode', $item)) {
                $payload['comment_mode'] = ($item['comment_mode'] ?? 'agent') === 'fixed' ? 'fixed' : 'agent';
            }
            if (array_key_exists('fixed_comment_text', $item)) {
                $text = $item['fixed_comment_text'];
                $payload['fixed_comment_text'] = is_string($text) && trim($text) !== '' ? trim($text) : null;
            }
            if (array_key_exists('fixed_comment_image_path', $item)) {
                $path = $item['fixed_comment_image_path'];
                $payload['fixed_comment_image_path'] = is_string($path) && $path !== '' ? $path : null;
            }
            if (array_key_exists('dm_mode', $item)) {
                $payload['dm_mode'] = ($item['dm_mode'] ?? 'agent') === 'fixed' ? 'fixed' : 'agent';
            }
            if (array_key_exists('fixed_dm_text', $item)) {
                $text = $item['fixed_dm_text'];
                $payload['fixed_dm_text'] = is_string($text) && trim($text) !== '' ? trim($text) : null;
            }

            $setting = PostAiSetting::query()->updateOrCreate(
                [
                    'business_id' => $business->id,
                    'social_account_id' => $account->id,
                    'platform_post_id' => $platformPostId,
                ],
                $payload,
            );

            $saved[] = $this->settingToArray($setting, $account);
        }

        return $saved;
    }

    /**
     * @param  list<array{account_id: int, platform_post_id: string}>  $posts
     * @return list<array<string, mixed>>
     */
    public function bulkApply(
        array $posts,
        ?bool $aiCommentReply,
        ?bool $aiPrivateReply,
        ?Business $business = null,
    ): array {
        $items = [];
        foreach ($posts as $post) {
            $item = [
                'account_id' => (int) ($post['account_id'] ?? 0),
                'platform_post_id' => (string) ($post['platform_post_id'] ?? ''),
            ];
            if ($aiCommentReply !== null) {
                $item['ai_comment_reply'] = $aiCommentReply;
            }
            if ($aiPrivateReply !== null) {
                $item['ai_private_reply'] = $aiPrivateReply;
            }
            $items[] = $item;
        }

        return $this->upsertSettings($items, $business);
    }

    /**
     * Resolve missing thumbnails / media for already-listed posts (lazy after first paint).
     *
     * @param  list<array{platform_post_id: string, permalink: string}>  $items
     * @return array{previews: array<string, array<string, mixed>>}
     */
    public function previews(array $items): array
    {
        $previews = $this->media->resolveBatch($items);
        $ids = array_values(array_unique(array_filter(array_map(
            fn ($item) => is_string($item['platform_post_id'] ?? null) ? trim($item['platform_post_id']) : null,
            $items,
        ))));

        // Refresh metrics in the same lazy pass (cached thereafter for list).
        if ($ids !== []) {
            try {
                $metrics = $this->posts->metricsForPosts($ids);
                foreach ($metrics as $id => $row) {
                    if (! isset($previews[$id])) {
                        $previews[$id] = ['platform_post_id' => $id];
                    }
                    $previews[$id]['like_count'] = $row['likes'];
                    $previews[$id]['comments_count'] = $row['comments'];
                    $previews[$id]['share_count'] = $row['shares'];
                }
            } catch (\Throwable) {
                // Keep media-only preview.
            }
        }

        return ['previews' => $previews];
    }

    /**
     * Apply previously cached live metrics without blocking the list request.
     *
     * @param  list<array<string, mixed>>  $posts
     * @return list<array<string, mixed>>
     */
    private function applyCachedMetrics(array $posts): array
    {
        return array_map(function (array $post) {
            $id = $post['platform_post_id'] ?? '';
            if (! is_string($id) || $id === '') {
                return $post;
            }
            $row = Cache::get('post-metrics:'.$id);
            if (! is_array($row)) {
                return $post;
            }

            $post['like_count'] = (int) ($row['likes'] ?? $post['like_count'] ?? 0);
            $post['comments_count'] = (int) ($row['comments'] ?? $post['comments_count'] ?? 0);
            $post['share_count'] = (int) ($row['shares'] ?? $post['share_count'] ?? 0);

            return $post;
        }, $posts);
    }

    /**
     * @return \Illuminate\Support\Collection<int, SocialAccount>
     */
    private function linkedAccounts(Business $business, ?string $accountId, ?string $platform)
    {
        $query = SocialAccount::query()
            ->forBusiness($business->id)
            ->where('provider', 'socialapi')
            ->where('status', '!=', 'disconnected')
            ->whereNotNull('socialapi_account_id')
            ->where('platform', '!=', 'simulator');

        $user = auth()->user();
        if ($user instanceof \App\Models\User) {
            $scope = $user->scopedSocialAccountIds($business->id);
            if (is_array($scope)) {
                if ($scope === []) {
                    return collect();
                }
                $query->whereIn('id', $scope);
            }
        }

        if ($accountId) {
            $query->where(function ($inner) use ($accountId) {
                $inner->where('id', $accountId)
                    ->orWhere('socialapi_account_id', $accountId);
            });
        }

        if ($platform) {
            $query->where('platform', $platform);
        }

        return $query->orderBy('name')->get();
    }

    /**
     * @param  list<array<string, mixed>>  $posts
     * @return array<string, PostAiSetting>
     */
    private function settingsFor(int $businessId, array $posts): array
    {
        if ($posts === []) {
            return [];
        }

        $accountIds = collect($posts)->pluck('account_id')->unique()->filter()->all();
        $postIds = collect($posts)->pluck('platform_post_id')->unique()->filter()->all();

        $rows = PostAiSetting::query()
            ->forBusiness($businessId)
            ->whereIn('social_account_id', $accountIds)
            ->whereIn('platform_post_id', $postIds)
            ->get();

        $keyed = [];
        foreach ($rows as $row) {
            $keyed[$row->social_account_id.':'.$row->platform_post_id] = $row;
        }

        return $keyed;
    }

    /**
     * @return array<string, mixed>
     */
    private function settingFields(?PostAiSetting $setting): array
    {
        return [
            'ai_comment_reply' => (bool) ($setting?->ai_comment_reply),
            'ai_private_reply' => (bool) ($setting?->ai_private_reply),
            'comment_mode' => ($setting?->comment_mode ?? 'agent') === 'fixed' ? 'fixed' : 'agent',
            'fixed_comment_text' => $setting?->fixed_comment_text,
            'fixed_comment_image_path' => $setting?->fixed_comment_image_path,
            'fixed_comment_image_url' => $setting?->fixedCommentImageUrl(),
            'dm_mode' => ($setting?->dm_mode ?? 'agent') === 'fixed' ? 'fixed' : 'agent',
            'fixed_dm_text' => $setting?->fixed_dm_text,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function settingToArray(PostAiSetting $setting, SocialAccount $account): array
    {
        return array_merge([
            'account_id' => $account->id,
            'platform_post_id' => $setting->platform_post_id,
        ], $this->settingFields($setting));
    }
}
