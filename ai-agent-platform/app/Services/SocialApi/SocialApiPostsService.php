<?php

namespace App\Services\SocialApi;

use Illuminate\Support\Facades\Http;

class SocialApiPostsService
{
    public function __construct(private SocialApiClient $client) {}

    /**
     * Published posts across accounts (browse + search).
     * Do not use /accounts/{id}/posts — that route 404s on SocialAPI.
     *
     * @see https://docs.social-api.ai/api-reference/posts/list-all-posts
     * @param  list<string>  $accountIds
     */
    public function listPublishedPosts(
        array $accountIds,
        ?string $search = null,
        ?string $cursor = null,
        int $limit = 20,
        ?string $platform = null,
    ): array {
        return $this->listByStatus($accountIds, 'published', $search, $cursor, $limit, $platform, 'created_desc');
    }

    /**
     * @param  list<string>  $accountIds
     * @see https://docs.social-api.ai/api-reference/posts/list-all-posts
     */
    public function listByStatus(
        array $accountIds,
        string $status = 'scheduled',
        ?string $search = null,
        ?string $cursor = null,
        int $limit = 20,
        ?string $platform = null,
        string $sort = 'scheduled_asc',
    ): array {
        return $this->client->get('/posts', array_filter([
            'account_ids' => implode(',', array_values(array_filter($accountIds))),
            'status' => $status,
            'search' => $search,
            'sort' => $sort,
            'platform' => $platform,
            'limit' => max(1, min(100, $limit)),
            'cursor' => $cursor,
        ], fn ($value) => $value !== null && $value !== ''));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @see https://docs.social-api.ai/api-reference/posts/create-or-schedule-a-post
     */
    public function createPost(array $payload): array
    {
        return $this->client->post('/posts', $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updatePost(string $postId, array $payload): array
    {
        return $this->client->patch('/posts/'.rawurlencode($postId), $payload);
    }

    public function deletePost(string $postId): array
    {
        return $this->client->delete('/posts/'.rawurlencode($postId));
    }

    public function publishPost(string $postId): array
    {
        return $this->client->post('/posts/'.rawurlencode($postId).'/publish');
    }

    public function getPost(string $postId): array
    {
        return $this->client->get('/posts/'.rawurlencode($postId));
    }

    /**
     * Server-side media upload → ready media_id.
     *
     * @see https://docs.social-api.ai/guides/media
     * @return array{media_id: string}
     */
    public function uploadMedia(string $contents, string $filename, ?string $mime = null): array
    {
        $remote = $this->client->upload('/media/upload', 'file', $contents, $filename, $mime);
        $id = $remote['media_id'] ?? $remote['id'] ?? $remote['data']['media_id'] ?? null;
        if (! is_string($id) || $id === '') {
            throw new \RuntimeException('SocialAPI media upload did not return a media_id.');
        }

        return ['media_id' => $id];
    }

    /**
     * Inbox posts that have comments (organic feed coverage).
     *
     * @see https://docs.social-api.ai/api-reference/inbox-comments/list-commented-posts
     */
    public function listCommentedPosts(
        ?string $accountId = null,
        ?string $platform = null,
        ?string $cursor = null,
        int $limit = 20,
    ): array {
        return $this->client->get('/inbox/comments', array_filter([
            'account_id' => $accountId,
            'platform' => $platform,
            'limit' => max(1, min(100, $limit)),
            'cursor' => $cursor,
            'sort_by' => 'date',
            'sort_order' => 'desc',
        ], fn ($value) => $value !== null && $value !== ''));
    }

    /**
     * Live engagement metrics for a SocialAPI or platform post ID.
     *
     * @see https://docs.social-api.ai/api-reference/posts/get-post-metrics
     * @return array{likes: int, comments: int, shares: int, saves: int}|null
     */
    public function getPostMetrics(string $postId): ?array
    {
        $remote = $this->client->get('/posts/'.rawurlencode($postId).'/metrics');

        return $this->extractMetrics($remote);
    }

    /**
     * Fetch metrics for many posts concurrently (with short cache).
     *
     * @param  list<string>  $platformPostIds
     * @return array<string, array{likes: int, comments: int, shares: int, saves: int}>
     */
    public function metricsForPosts(array $platformPostIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            fn ($id) => is_string($id) ? trim($id) : '',
            $platformPostIds,
        ))));

        if ($ids === []) {
            return [];
        }

        $out = [];
        $need = [];
        foreach ($ids as $id) {
            $cached = \Illuminate\Support\Facades\Cache::get('post-metrics:'.$id);
            if (is_array($cached)) {
                $out[$id] = $cached;
            } else {
                $need[] = $id;
            }
        }

        if ($need === []) {
            return $out;
        }

        $key = (string) config('services.socialapi.key');
        $base = rtrim((string) config('services.socialapi.base_url'), '/');
        if ($key === '' || $base === '') {
            return $out;
        }

        $responses = Http::pool(fn ($pool) => array_map(
            fn (string $id) => $pool->as($id)
                ->baseUrl($base)
                ->withToken($key)
                ->acceptJson()
                ->timeout(8)
                ->get('/posts/'.rawurlencode($id).'/metrics'),
            $need,
        ));

        foreach ($need as $id) {
            $response = $responses[$id] ?? null;
            if (! $response || ! method_exists($response, 'successful') || ! $response->successful()) {
                continue;
            }
            $metrics = $this->extractMetrics($response->json() ?: []);
            if ($metrics) {
                \Illuminate\Support\Facades\Cache::put('post-metrics:'.$id, $metrics, now()->addMinutes(15));
                $out[$id] = $metrics;
            }
        }

        return $out;
    }

    /**
     * @return array{posts: list<array<string, mixed>>, pagination: array{has_more: bool, next_cursor: ?string}}
     */
    public function normalizeList(array $remote): array
    {
        $rows = $remote['data'] ?? $remote['posts'] ?? [];
        if (! is_array($rows)) {
            $rows = [];
        }

        $posts = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $normalized = $this->normalizePost($row);
            if ($normalized) {
                $posts[] = $normalized;
            }
        }

        $pagination = is_array($remote['pagination'] ?? null) ? $remote['pagination'] : [];
        $next = $pagination['next_cursor'] ?? $remote['next_cursor'] ?? null;
        if (is_string($next) && $next === '') {
            $next = null;
        }

        return [
            'posts' => $posts,
            'pagination' => [
                'has_more' => (bool) ($pagination['has_more'] ?? ($next !== null)),
                'next_cursor' => $next,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    public function normalizePost(array $row): ?array
    {
        $target = $this->primaryTarget($row);
        $platformPostId = $this->firstString([
            $row['platform_post_id'] ?? null,
            $row['platform_id'] ?? null,
            $target['platform_post_id'] ?? null,
            $row['id'] ?? null,
        ]);

        if (! $platformPostId) {
            return null;
        }

        $media = $this->flattenMedia($row, $target);
        $thumbnail = $this->firstString([
            $row['thumbnail'] ?? null,
            $row['thumbnail_url'] ?? null,
            $row['media_url'] ?? null,
            $row['image_url'] ?? null,
            $row['full_picture'] ?? null,
            $target['thumbnail'] ?? null,
            $media[0]['url'] ?? null,
        ]);

        $publishedAt = $this->firstString([
            $row['published_at'] ?? null,
            $row['timestamp'] ?? null,
            $target['published_at'] ?? null,
            $row['created_at'] ?? null,
        ]);

        $metrics = is_array($target['metrics'] ?? null) ? $target['metrics'] : [];
        if (is_array($row['metrics'] ?? null)) {
            $metrics = array_merge($metrics, $row['metrics']);
        }

        $mediaType = $this->firstString([
            $row['media_type'] ?? null,
            $target['media_type'] ?? null,
            $media[0]['type'] ?? null,
        ]);

        return [
            'id' => $this->firstString([$row['id'] ?? null, $platformPostId]),
            'platform_post_id' => $platformPostId,
            'account_id' => $this->firstString([
                $row['account_id'] ?? null,
                $target['account_id'] ?? null,
            ]),
            'platform' => strtolower((string) ($this->firstString([
                $row['platform'] ?? null,
                $target['platform'] ?? null,
            ]) ?: 'unknown')),
            'caption' => (string) ($this->firstString([
                $row['caption'] ?? null,
                $row['text'] ?? null,
                $row['content'] ?? null,
                $target['text'] ?? null,
            ]) ?: ''),
            'title' => $this->firstString([$row['title'] ?? null, $target['title'] ?? null]),
            'thumbnail' => $thumbnail,
            'media' => $media,
            'permalink' => $this->firstString([
                $row['permalink'] ?? null,
                $row['url'] ?? null,
                $target['permalink'] ?? null,
            ]),
            'published_at' => $publishedAt,
            'like_count' => $this->intMetric([
                $row['like_count'] ?? null,
                $row['likes'] ?? null,
                $row['reaction_count'] ?? null,
                $row['reactions_count'] ?? null,
                $metrics['likes'] ?? null,
                $metrics['reactions'] ?? null,
            ]),
            'comments_count' => $this->intMetric([
                $row['comments_count'] ?? null,
                $row['comment_count'] ?? null,
                $row['comments'] ?? null,
                $metrics['comments'] ?? null,
            ]),
            'share_count' => $this->intMetric([
                $row['share_count'] ?? null,
                $row['shares_count'] ?? null,
                $metrics['shares'] ?? null,
            ]),
            'media_type' => $mediaType,
        ];
    }

    /**
     * @param  array<string, mixed>  $remote
     * @return array{likes: int, comments: int, shares: int, saves: int}|null
     */
    private function extractMetrics(array $remote): ?array
    {
        $data = is_array($remote['data'] ?? null) ? $remote['data'] : $remote;
        $targets = $data['targets'] ?? [];
        if (! is_array($targets)) {
            return null;
        }

        foreach ($targets as $target) {
            if (! is_array($target) || ! is_array($target['metrics'] ?? null)) {
                continue;
            }
            $m = $target['metrics'];

            return [
                'likes' => (int) ($m['likes'] ?? $m['reactions'] ?? 0),
                'comments' => (int) ($m['comments'] ?? 0),
                'shares' => (int) ($m['shares'] ?? 0),
                'saves' => (int) ($m['saves'] ?? 0),
            ];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function primaryTarget(array $row): array
    {
        $targets = $row['targets'] ?? [];
        if (! is_array($targets) || $targets === []) {
            return [];
        }

        foreach ($targets as $target) {
            if (is_array($target) && ($target['status'] ?? '') === 'published') {
                return $target;
            }
        }

        $first = $targets[0] ?? [];

        return is_array($first) ? $first : [];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $target
     * @return list<array{url: string, type: string}>
     */
    private function flattenMedia(array $row, array $target): array
    {
        $items = [];
        foreach ([$row['media'] ?? [], $target['media'] ?? []] as $group) {
            if (! is_array($group)) {
                continue;
            }
            foreach ($group as $item) {
                if (is_string($item) && $item !== '') {
                    $items[] = ['url' => $item, 'type' => 'image'];
                    continue;
                }
                if (! is_array($item)) {
                    continue;
                }
                $url = $this->firstString([
                    $item['url'] ?? null,
                    $item['source'] ?? null,
                    $item['thumbnail'] ?? null,
                ]);
                if (! $url) {
                    continue;
                }
                $items[] = [
                    'url' => $url,
                    'type' => (string) ($item['type'] ?? 'image'),
                ];
            }
        }

        return $items;
    }

    /**
     * @param  list<mixed>  $values
     */
    private function firstString(array $values): ?string
    {
        foreach ($values as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
            if (is_int($value) || is_float($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * @param  list<mixed>  $values
     */
    private function intMetric(array $values): int
    {
        foreach ($values as $value) {
            if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
                return (int) $value;
            }
        }

        return 0;
    }
}
