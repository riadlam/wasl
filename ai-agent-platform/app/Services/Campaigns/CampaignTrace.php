<?php

namespace App\Services\Campaigns;

use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Structured debug trail for campaign tease / brief / draft (storage/logs/campaigns.log).
 */
final class CampaignTrace
{
    public static function log(): LoggerInterface
    {
        return Log::channel((string) config('campaigns.log_channel', 'campaigns'));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function info(string $event, array $context = []): void
    {
        self::log()->info($event, self::normalize($context));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function warning(string $event, array $context = []): void
    {
        self::log()->warning($event, self::normalize($context));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function error(string $event, array $context = [], ?Throwable $e = null): void
    {
        if ($e) {
            $context['exception'] = $e->getMessage();
            $context['exception_class'] = $e::class;
        }
        self::log()->error($event, self::normalize($context));
    }

    public static function clip(?string $text, int $max = 1200): string
    {
        $text = trim((string) $text);
        if ($text === '') {
            return '';
        }
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return mb_substr($text, 0, $max).'…[truncated '.mb_strlen($text).' chars]';
    }

    /**
     * @param  list<array{tool?: string, arguments?: mixed, result?: mixed, duration_ms?: int}>  $toolLog
     * @return list<array<string, mixed>>
     */
    public static function summarizeTools(array $toolLog): array
    {
        $out = [];
        foreach ($toolLog as $row) {
            $result = $row['result'] ?? null;
            $err = is_array($result) ? ($result['error'] ?? null) : null;
            $out[] = [
                'tool' => $row['tool'] ?? '?',
                'arguments' => $row['arguments'] ?? [],
                'duration_ms' => $row['duration_ms'] ?? null,
                'ok' => $err === null,
                'error' => $err,
                'result_preview' => self::clip(
                    is_string($result) ? $result : (string) json_encode($result, JSON_UNESCAPED_UNICODE),
                    600
                ),
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private static function normalize(array $context): array
    {
        foreach ($context as $key => $value) {
            if (is_string($value) && mb_strlen($value) > 4000) {
                $context[$key] = self::clip($value, 4000);
            }
        }

        return $context;
    }
}
