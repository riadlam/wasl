<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PostMediaController extends Controller
{
    /**
     * Same-origin proxy for SocialAPI / Facebook CDN post media.
     * Authorized via HMAC token bound to a cached remote URL id.
     */
    public function show(Request $request, string $id): StreamedResponse
    {
        $token = (string) $request->query('token', '');
        $expected = hash_hmac('sha256', 'post-media:'.$id, (string) config('app.key'));
        abort_unless($token !== '' && hash_equals($expected, $token), 403, 'Invalid media token.');

        $payload = Cache::get('post-media:'.$id);
        abort_unless(is_array($payload) && is_string($payload['url'] ?? null) && $payload['url'] !== '', 404, 'Media expired.');

        $url = $payload['url'];
        abort_unless($this->isAllowedRemoteUrl($url), 422, 'Unsupported media URL.');

        $kind = strtolower((string) ($payload['kind'] ?? 'image'));
        $upstream = Http::timeout(45)
            ->withHeaders([
                // Facebook lookaside/og:image URLs only serve bytes to the crawler UA.
                'User-Agent' => 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)',
                'Accept' => 'image/avif,image/webp,image/apng,image/*,video/*,*/*;q=0.8',
                'Referer' => 'https://www.facebook.com/',
            ])
            ->withOptions(['stream' => true])
            ->get($url);

        if (! $upstream->successful()) {
            abort(502, 'Could not fetch remote media.');
        }

        $contentType = $upstream->header('Content-Type') ?: $this->guessContentType($kind, $url);
        if (is_string($contentType) && str_contains(strtolower($contentType), 'text/html')) {
            abort(502, 'Remote media returned HTML instead of an image.');
        }
        $body = $upstream->toPsrResponse()->getBody();

        return response()->stream(function () use ($body) {
            while (! $body->eof()) {
                echo $body->read(1024 * 64);
                flush();
            }
        }, 200, [
            'Content-Type' => $contentType,
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function isAllowedRemoteUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https') {
            return false;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));

        return $host !== ''
            && ! in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            && ! str_ends_with($host, '.local');
    }

    private function guessContentType(string $kind, string $url): string
    {
        return match (true) {
            str_contains($kind, 'video') => 'video/mp4',
            (bool) preg_match('/\.(png)(\?|$)/i', $url) => 'image/png',
            (bool) preg_match('/\.(webp)(\?|$)/i', $url) => 'image/webp',
            (bool) preg_match('/\.(gif)(\?|$)/i', $url) => 'image/gif',
            (bool) preg_match('/\.(mp4|mov|webm)(\?|$)/i', $url) => 'video/mp4',
            default => 'image/jpeg',
        };
    }
}
