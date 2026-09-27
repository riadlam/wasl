<?php

namespace App\AI\Skills;

class ReplyGroundingGuard
{
    private const PAIR_SUM_LIMIT = 80;

    /**
     * @param  list<string>  $sources
     */
    public function ensure(string $reply, array $sources, callable $regenerate, string $fallback): string
    {
        if ($this->inventedNumbers($reply, $sources) === []) {
            return $reply;
        }

        $second = trim((string) $regenerate($reply));
        if ($second !== '' && $this->inventedNumbers($second, $sources) === []) {
            return $second;
        }

        return $fallback;
    }

    /**
     * Numbers in the reply that are not in any source. Formats are normalised first
     * (Arabic-Indic digits, "2 500", "2.500,00", "2500.0") and a total of two known
     * numbers (price + delivery fee) counts as grounded.
     *
     * @param  list<string>  $sources
     * @return list<string>
     */
    public function inventedNumbers(string $reply, array $sources): array
    {
        $allowed = [];
        foreach ($sources as $source) {
            foreach ($this->numbers((string) $source) as $number) {
                $allowed[$number] = true;
            }
        }

        $invented = [];
        $sums = null;
        foreach ($this->numbers($reply) as $number) {
            if (isset($allowed[$number])) {
                continue;
            }
            $sums ??= $this->pairSums(array_keys($allowed));
            if (isset($sums[$number])) {
                continue;
            }
            $invented[] = $number;
        }

        return array_values(array_unique($invented));
    }

    /**
     * @return list<string>
     */
    public function numbers(string $text): array
    {
        $text = strtr($text, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);

        preg_match_all('/\d{1,3}(?:[ .,\x{00A0}\x{202F}]\d{3})+(?:[.,]\d{1,2})?(?!\d)|\d+(?:[.,]\d+)?/u', $text, $matches);

        return array_values(array_map(fn (string $raw) => $this->normalize($raw), $matches[0] ?? []));
    }

    private function normalize(string $raw): string
    {
        $raw = preg_replace('/[\x{00A0}\x{202F} ]/u', '', $raw) ?? $raw;

        if (preg_match('/^\d{1,3}(?:[.,]\d{3})+(?:[.,]\d{1,2})?$/', $raw)) {
            if (preg_match('/^(.*)[.,](\d{1,2})$/', $raw, $m) && ! preg_match('/[.,]\d{3}$/', $raw)) {
                $raw = str_replace(['.', ','], '', $m[1]).'.'.$m[2];
            } else {
                $raw = str_replace(['.', ','], '', $raw);
            }
        } else {
            $raw = str_replace(',', '.', $raw);
        }

        if (str_contains($raw, '.')) {
            $raw = rtrim(rtrim($raw, '0'), '.');
        }
        $raw = ltrim($raw, '0');

        return $raw === '' || $raw[0] === '.' ? '0'.$raw : $raw;
    }

    /**
     * @param  list<string|int>  $numbers
     * @return array<string, true>
     */
    private function pairSums(array $numbers): array
    {
        $numbers = array_slice(array_values(array_unique(array_map('strval', $numbers))), 0, self::PAIR_SUM_LIMIT);
        $sums = [];
        $count = count($numbers);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i; $j < $count; $j++) {
                $sum = (float) $numbers[$i] + (float) $numbers[$j];
                $sums[$this->normalize(rtrim(rtrim(number_format($sum, 2, '.', ''), '0'), '.'))] = true;
            }
        }

        return $sums;
    }
}
