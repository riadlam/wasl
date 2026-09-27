<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Resolve missing SocialAPI post thumbnails (often null on Facebook inbox posts)
 * via Open Graph, and mint same-origin proxy URLs for CDN media.
 *
 * List responses only apply cached previews so the Posts grid stays fast.
 * Missing previews are filled by resolveBatch() after the first paint.
 */
class PostMediaService
{
    /**
     * Apply cached Open Graph previews only (no network).
     *
     * @param  list<array<string, mixed>>  $posts
     * @return list<array<string, mixed>>
     */
    public function enrichFromCache(array $posts): array
    {
        foreach ($posts as $index => $post) {
            if (! empty($post['thumbnail'])) {
                continue;
            }
            $permalink = is_string($post['permalink'] ?? null) ? trim($post['permalink']) : '';
            if ($permalink === '') {
                continue;
            }
            $cached = Cache::get($this->ogCacheKey($permalink));
            if (is_array($cached)) {
                $posts[$index] = $this->applyPreview($post, $cached);
            }
        }

        return array_map(fn (array $post) => $this->withProxiedMedia($post), $posts);
    }

    /**
     * Resolve / refresh previews for posts (Open Graph + optional live metrics).
     *
     * @param  list<array{platform_post_id?: string, permalink?: string}>  $items
     * @return array<string, array<string, mixed>> keyed by platform_post_id
     */
    public function resolveBatch(array $items): array
    {
        $permalinks = [];
        $byPermalink = [];
        foreach ($items as $item) {
            $permalink = is_string($item['permalink'] ?? null) ? trim($item['permalink']) : '';
            $postId = is_string($item['platform_post_id'] ?? null) ? trim($item['platform_post_id']) : '';
            if ($permalink === '' || $postId === '') {
                continue;
            }
            $permalinks[$permalink] = true;
            $byPermalink[$permalink] = $postId;
        }

        $unique = array_keys($permalinks);
        $fetched = $this->fetchOpenGraphBatch($unique);

        $out = [];
        foreach ($byPermalink as $permalink => $postId) {
            $preview = $fetched[$permalink] ?? Cache::get($this->ogCacheKey($permalink));
            if (! is_array($preview)) {
                continue;
            }
            if (isset($fetched[$permalink])) {
                Cache::put($this->ogCacheKey($permalink), $preview, now()->addDay());
            }

            $row = [
                'platform_post_id' => $postId,
                'thumbnail' => $preview['thumbnail'] ?? null,
                'video_url' => $preview['video_url'] ?? null,
                'media_type' => $preview['media_type'] ?? null,
            ];
            $out[$postId] = $this->withProxiedMedia($row);
        }

        return $out;
    }

    /**
     * @param  list<string>  $permalinks
     * @return array<string, array{thumbnail: ?string, video_url: ?string, media_type: ?string}>
     */
    private function fetchOpenGraphBatch(array $permalinks): array
    {
        if ($permalinks === []) {
            return [];
        }

        // Prefer cache hits; only hit the network for misses.
        $need = [];
        $out = [];
        foreach ($permalinks as $permalink) {
            $cached = Cache::get($this->ogCacheKey($permalink));
            if (is_array($cached)) {
                $out[$permalink] = $cached;
            } else {
                $need[] = $permalink;
            }
        }

        if ($need === []) {
            return $out;
        }

        $responses = Http::pool(fn ($pool) => array_map(
            fn (string $url) => $pool->as(sha1($url))
                ->timeout(8)
                ->withHeaders([
                    'User-Agent' => 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)',
                    'Accept' => 'text/html,application/xhtml+xml',
                ])
                ->get($url),
            $need,
        ));

        foreach ($need as $permalink) {
            $response = $responses[sha1($permalink)] ?? null;
            if (! $response || ! method_exists($response, 'successful') || ! $response->successful()) {
                continue;
            }
            $preview = $this->parseOpenGraph((string) $response->body());
            if ($preview['thumbnail'] || $preview['video_url']) {
                $out[$permalink] = $preview;
            }
        }

        return $out;
    }

    /**
     * @return array{thumbnail: ?string, video_url: ?string, media_type: ?string}
     */
    private function parseOpenGraph(string $html): array
    {
        $image = $this->metaContent($html, 'og:image')
            ?? $this->metaContent($html, 'og:image:url')
            ?? $this->metaContent($html, 'twitter:image');
        $video = $this->metaContent($html, 'og:video')
            ?? $this->metaContent($html, 'og:video:url')
            ?? $this->metaContent($html, 'og:video:secure_url');
        $type = $this->metaContent($html, 'og:type');

        $mediaType = null;
        if ($video || ($type && str_contains(strtolower($type), 'video'))) {
            $mediaType = 'video';
        } elseif ($image) {
            $mediaType = 'image';
        }

        return [
            'thumbnail' => $image,
            'video_url' => $video,
            'media_type' => $mediaType,
        ];
    }

    private function metaContent(string $html, string $property): ?string
    {
        $quoted = preg_quote($property, '/');
        if (preg_match('/property=["\']'.$quoted.'["\'][^>]*content=["\']([^"\']+)/i', $html, $m)
            || preg_match('/content=["\']([^"\']+)["\'][^>]*property=["\']'.$quoted.'["\']/i', $html, $m)
            || preg_match('/name=["\']'.$quoted.'["\'][^>]*content=["\']([^"\']+)/i', $html, $m)) {
            $value = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $value = trim($value);

            return $value !== '' ? $value : null;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $post
     * @param  array{thumbnail: ?string, video_url: ?string, media_type: ?string}  $preview
     * @return array<string, mixed>
     */
    private function applyPreview(array $post, array $preview): array
    {
        if (empty($post['thumbnail']) && ! empty($preview['thumbnail'])) {
            $post['thumbnail'] = $preview['thumbnail'];
        }
        if (! empty($preview['video_url'])) {
            $media = is_array($post['media'] ?? null) ? $post['media'] : [];
            $media[] = ['url' => $preview['video_url'], 'type' => 'video'];
            $post['media'] = $media;
            $post['video_url'] = $preview['video_url'];
        }
        if (empty($post['media_type']) && ! empty($preview['media_type'])) {
            $post['media_type'] = $preview['media_type'];
        }

        return $post;
    }

    /**
     * @param  array<string, mixed>  $post
     * @return array<string, mixed>
     */
    private function withProxiedMedia(array $post): array
    {
        if (! empty($post['thumbnail']) && is_string($post['thumbnail']) && ! str_starts_with($post['thumbnail'], '/media/posts/')) {
            $post['thumbnail'] = $this->proxyUrl($post['thumbnail'], 'image');
        }
        if (! empty($post['video_url']) && is_string($post['video_url']) && ! str_starts_with($post['video_url'], '/media/posts/')) {
            $post['video_url'] = $this->proxyUrl($post['video_url'], 'video');
        }
        if (is_array($post['media'] ?? null)) {
            $post['media'] = array_map(function ($item) {
                if (! is_array($item) || empty($item['url']) || ! is_string($item['url'])) {
                    return $item;
                }
                if (str_starts_with($item['url'], '/media/posts/')) {
                    return $item;
                }
                $kind = str_contains(strtolower((string) ($item['type'] ?? '')), 'video') ? 'video' : 'image';
                $item['url'] = $this->proxyUrl($item['url'], $kind);

                return $item;
            }, $post['media']);
        }

        return $post;
    }

    public function proxyUrl(string $remoteUrl, string $kind = 'image'): string
    {
        $id = hash('sha256', $remoteUrl);
        Cache::put('post-media:'.$id, [
            'url' => $remoteUrl,
            'kind' => $kind,
        ], now()->addDays(2));

        $token = hash_hmac('sha256', 'post-media:'.$id, (string) config('app.key'));

        return '/media/posts/'.$id.'?token='.$token;
    }

    private function ogCacheKey(string $permalink): string
    {
        return 'post-og:'.sha1($permalink);
    }
}
