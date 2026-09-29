<?php

namespace App\Support;

/**
 * Telegram HTML helpers (parse_mode=HTML).
 */
final class TelegramHtml
{
    public static function escape(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    public static function bold(?string $value): string
    {
        return '<b>'.self::escape($value).'</b>';
    }

    public static function italic(?string $value): string
    {
        return '<i>'.self::escape($value).'</i>';
    }

    public static function code(?string $value): string
    {
        return '<code>'.self::escape($value).'</code>';
    }

    /**
     * @param  list<string>  $lines
     */
    public static function join(array $lines): string
    {
        return implode("\n", array_values(array_filter($lines, fn ($line) => $line !== null && $line !== '')));
    }
}
