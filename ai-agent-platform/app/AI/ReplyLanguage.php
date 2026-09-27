<?php

namespace App\AI;

use App\Models\Business;

class ReplyLanguage
{
    public const DARIJA = 'Darija';

    public const FRENCH = 'French';

    public static function normalize(?string $language): string
    {
        $value = strtolower(trim((string) $language));

        return str_contains($value, 'fr') ? self::FRENCH : self::DARIJA;
    }

    public static function forBusiness(Business $business): string
    {
        $business->loadMissing(['agent', 'agentSettings']);

        return self::normalize(
            $business->agentSettings?->language ?: $business->agent?->language
        );
    }

    public static function forCustomerAi(Business $business): string
    {
        $language = \App\Models\CustomerAiSetting::forBusiness($business)->language;

        return $language ? self::normalize($language) : self::forBusiness($business);
    }

    public static function instruction(string $language): string
    {
        $language = self::normalize($language);
        $label = $language === self::FRENCH ? 'French' : 'Algerian Darija';
        $script = $language === self::FRENCH
            ? 'Write French in the Latin alphabet only. Do not mix Arabic script into a French reply.'
            : <<<'DARIJA'
Write Algerian Maghrebi Darija in Arabic script only (not Egyptian, not Levantine, not MSA advertising Arabic).
Natural Algerian flavor when it fits: واش، دروك، دوكة، بزاف، راك، تاع، شحال، برك، صح.
FORBIDDEN Egyptian / Mashriqi markers: دلوقتي، دلوقت، عايز، كده، أوي، جدا as filler, إزاي.
No Arabizi (ch7al, rani, wesh in Latin). Do not mix Arabic and Latin inside one word. Brand names, game titles, SKUs, URLs and @handles may stay Latin with spaces around them.
DARIJA;

        return <<<TEXT
Reply language (mandatory): {$label}.
Reply only in {$label}. Understand both Algerian Darija and French in the same thread.
If the user writes in the other language, keep the same intent, prices, stock, and promises, and only phrase the answer in {$label}. Translate the reply; do not reinterpret it.
Older turns may mix both languages. Treat them as the same facts. Do not copy the user's language when it differs from {$label}.
{$script}
One short reply. Do not recap earlier turns.
TEXT;
    }

    /**
     * Compact dialect lock for creative / campaign system prompts (no "one short reply" chat rule).
     */
    public static function creativeInstruction(string $language): string
    {
        $language = self::normalize($language);
        if ($language === self::FRENCH) {
            return <<<'TEXT'
Language (mandatory): French.
Write title, caption and hashtags in French (Latin alphabet only). Do not mix Arabic script into French lines. Brand marks that are Arabic may stay as written.
TEXT;
        }

        return <<<'TEXT'
Language (mandatory): Algerian Maghrebi Darija (not Egyptian, not Levantine, not MSA ad-speak).
Write title and caption in Algerian Darija using Arabic script. Natural flavor when it fits: واش، دروك، دوكة، بزاف، راك، تاع، شحال، برك.
FORBIDDEN: دلوقتي، دلوقت، عايز، كده، أوي، جدا as filler, إزاي; Arabizi; mixing scripts inside one word.
Brand names, game titles, SKUs, URLs and @handles stay Latin with spaces around them (e.g. شحن Mobile Legends دروك ⚡).
TEXT;
    }

    public static function line(string $key, string $language): string
    {
        $french = self::normalize($language) === self::FRENCH;

        return match ($key) {
            'handoff' => $french
                ? 'Je transmets la conversation à un membre de l\'équipe.'
                : 'فهمت، رح نحوّل المحادثة لواحد من الفريق يعاونك.',
            'busy' => $french
                ? 'Désolé, le service est occupé. Réessayez dans un moment.'
                : 'سمحلي، الخدمة مشاغلة دوكة. عاود من بعد شوي.',
            'will_check' => $french
                ? 'Je vérifie cette information et je reviens vers vous.'
                : 'نأكدلك هاد المعلومة و نرجعولك.',
            default => $french
                ? 'Je n\'ai pas pu répondre pour le moment. L\'équipe revient vers vous.'
                : 'سمحلي، ما قدرتش نجاوب دوكة. فريق المحل يعاود عليك.',
        };
    }
}
