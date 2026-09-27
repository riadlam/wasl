<?php

namespace App\Support;

/**
 * Per-platform publish caps from SocialAPI posts overview.
 *
 * @see https://docs.social-api.ai/posts/overview
 */
final class SocialPlatformLimits
{
    /** Max media items per post (carousel / multi-image). */
    private const MEDIA_CAPS = [
        'instagram' => 10,
        'facebook' => 10,
        'linkedin' => 20,
        'threads' => 20,
        'tiktok' => 35,
        'youtube' => 1,
        'twitter' => 0,
        'google' => 1,
        'pinterest' => 1,
    ];

    /** Caption / text max length. */
    private const TEXT_CAPS = [
        'instagram' => 2200,
        'facebook' => 63206,
        'linkedin' => 3000,
        'threads' => 500,
        'tiktok' => 2200,
        'youtube' => 5000,
        'twitter' => 280,
        'google' => 1500,
        'pinterest' => 500,
    ];

    /**
     * @param  list<string>  $platforms
     */
    public static function maxMedia(array $platforms): int
    {
        $platforms = array_values(array_filter(array_map(
            fn ($p) => strtolower(trim((string) $p)),
            $platforms,
        )));
        if ($platforms === []) {
            return 10;
        }

        $caps = [];
        foreach ($platforms as $platform) {
            $caps[] = self::MEDIA_CAPS[$platform] ?? 10;
        }

        return max(0, min($caps));
    }

    /**
     * @param  list<string>  $platforms
     */
    public static function maxText(array $platforms): int
    {
        $platforms = array_values(array_filter(array_map(
            fn ($p) => strtolower(trim((string) $p)),
            $platforms,
        )));
        if ($platforms === []) {
            return 2200;
        }

        $caps = [];
        foreach ($platforms as $platform) {
            $caps[] = self::TEXT_CAPS[$platform] ?? 2200;
        }

        return max(1, min($caps));
    }

    /**
     * @return array<string, array{max_media: int, max_text: int}>
     */
    public static function all(): array
    {
        $keys = array_unique([...array_keys(self::MEDIA_CAPS), ...array_keys(self::TEXT_CAPS)]);
        $out = [];
        foreach ($keys as $platform) {
            $out[$platform] = [
                'max_media' => self::MEDIA_CAPS[$platform] ?? 10,
                'max_text' => self::TEXT_CAPS[$platform] ?? 2200,
            ];
        }

        return $out;
    }
}
