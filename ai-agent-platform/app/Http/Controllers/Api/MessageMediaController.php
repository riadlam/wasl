<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MessageMediaController extends Controller
{
    /**
     * Same-origin proxy for SocialAPI/Facebook CDN attachments.
     * Authorized via temporary signed URL (img/video tags cannot send API auth headers).
     */
    public function show(Request $request, Message $message): StreamedResponse
    {
        $expected = hash_hmac('sha256', 'msg-media:'.$message->id, (string) config('app.key'));
        $token = (string) $request->query('token', '');
        abort_unless($token !== '' && hash_equals($expected, $token), 403, 'Invalid media token.');

        $url = $this->resolveUrl($message);
        abort_unless(is_string($url) && $url !== '', 404, 'No media on this message.');
        abort_unless($this->isAllowedRemoteUrl($url), 422, 'Unsupported media URL.');

        $upstream = Http::timeout(45)
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; WaslInbox/1.0)',
                'Accept' => 'image/avif,image/webp,image/apng,image/*,video/*,audio/*,*/*;q=0.8',
                'Referer' => 'https://www.facebook.com/',
            ])
            ->withOptions(['stream' => true])
            ->get($url);

        if (! $upstream->successful()) {
            abort(502, 'Could not fetch remote media.');
        }

        $contentType = $upstream->header('Content-Type') ?: $this->guessContentType($message, $url);
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

    private function resolveUrl(Message $message): ?string
    {
        if (is_string($message->media_url) && $message->media_url !== '') {
            return $message->media_url;
        }

        $meta = is_array($message->metadata) ? $message->metadata : [];
        foreach (['attachment_url', 'media_url', 'url'] as $key) {
            $value = $meta[$key] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
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

    private function guessContentType(Message $message, string $url): string
    {
        $kind = strtolower((string) ($message->media_type ?: $message->type ?: ''));

        return match ($kind) {
            'image', 'photo', 'sticker' => 'image/jpeg',
            'video', 'reel' => 'video/mp4',
            'audio', 'voice', 'voice_note' => 'audio/mpeg',
            default => match (true) {
                (bool) preg_match('/\.(png)(\?|$)/i', $url) => 'image/png',
                (bool) preg_match('/\.(webp)(\?|$)/i', $url) => 'image/webp',
                (bool) preg_match('/\.(gif)(\?|$)/i', $url) => 'image/gif',
                (bool) preg_match('/\.(mp4|mov)(\?|$)/i', $url) => 'video/mp4',
                (bool) preg_match('/\.(mp3|m4a)(\?|$)/i', $url) => 'audio/mpeg',
                default => 'application/octet-stream',
            },
        };
    }
}
