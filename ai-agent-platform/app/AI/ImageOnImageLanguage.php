<?php

namespace App\AI;

use App\Models\Business;

/**
 * Hardens Fal image prompts so on-image copy follows shop / owner language.
 *
 * Never paint owner briefing / campaign-meta instructions onto the creative
 * (e.g. "نديرو بوستات جداد…" / "make posts like before").
 */
class ImageOnImageLanguage
{
    /** @var list<string> */
    private const META_MARKERS = [
        'نديرو',
        'بوستات جداد',
        'بوستات جديدة',
        'نفس الكونسبت',
        'نفس الستايل',
        'واش درنا',
        'ما قبل',
        'مقبل',
        'اللي قبل',
        'owner briefing',
        'focus brief',
        'make new posts',
        'same concept',
        'scheduled',
        'campaign tease',
        'ai campaigns',
    ];

    public static function enrich(Business $business, string $prompt, ?string $ownerBrief = null): string
    {
        $prompt = trim($prompt);
        // Briefing chat is direction for agents — never a source of on-image slogans.
        $brief = self::sanitizeMarketingHints(trim((string) $ownerBrief));
        $haystack = mb_strtolower($prompt."\n".$brief);
        $shopLang = ReplyLanguage::forBusiness($business);

        $mode = self::resolveMode($haystack, $shopLang);
        if ($mode === null) {
            return self::noOverlayGuard().' '.$prompt;
        }

        $block = $mode === ReplyLanguage::FRENCH
            ? self::frenchTypographyBlock($brief, $prompt)
            : self::darijaTypographyBlock($brief, $prompt);

        // If the model already included our guard markers, still prepend the hard block once.
        if (stripos($prompt, 'ON-IMAGE LANGUAGE (MANDATORY)') !== false) {
            return $prompt;
        }

        return $block.' '.$prompt;
    }

    /**
     * Keep short marketing slogans; drop campaign-meta / owner-instruction lines.
     */
    public static function sanitizeMarketingHints(string $text): string
    {
        if ($text === '') {
            return '';
        }

        $lines = preg_split('/\R+/u', $text) ?: [$text];
        $kept = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (self::looksLikeCampaignMeta($line)) {
                continue;
            }
            $kept[] = $line;
        }

        return trim(implode("\n", $kept));
    }

    public static function looksLikeCampaignMeta(string $text): bool
    {
        $hay = mb_strtolower($text);
        foreach (self::META_MARKERS as $marker) {
            if (str_contains($hay, mb_strtolower($marker))) {
                return true;
            }
        }

        // Owner/AI transcript prefixes from brief_notes.
        if (preg_match('/^(owner|ai)\s*:/iu', $hay)) {
            return true;
        }

        return false;
    }

    private static function resolveMode(string $haystack, string $shopLang): ?string
    {
        $wantsFrench = (bool) preg_match(
            '/(fran[cç]ais|french|en\s*fran|بالفرنسية|en fr)/iu',
            $haystack,
        );
        $wantsDarija = (bool) preg_match(
            '/(darja|darija|dz\b|بالدارجة|بالعربية|عربي|عربية|arabic[- ]script|arabic text)/iu',
            $haystack,
        );
        $wantsOnImageText = (bool) preg_match(
            '/(كتب|نص|typography|poster text|on the (poster|image|banner)|text on|écriture|ecrire|écrire|écrire dessus|كتب عليها|نص في)/iu',
            $haystack,
        );
        $looksPromo = (bool) preg_match(
            '/(promo|poster|banner|pass|weekly|offre|offer|réduc|reduc|%|فيسبوك|facebook|instagram|عرض|تمريرة|story|reel|شحن|top-?up)/iu',
            $haystack,
        );

        if ($wantsFrench) {
            return ReplyLanguage::FRENCH;
        }
        if ($wantsDarija || $wantsOnImageText) {
            return $wantsFrench ? ReplyLanguage::FRENCH : ReplyLanguage::DARIJA;
        }
        // Marketing creatives: default on-image copy to the shop reply language.
        if ($looksPromo) {
            return $shopLang === ReplyLanguage::FRENCH
                ? ReplyLanguage::FRENCH
                : ReplyLanguage::DARIJA;
        }

        return null;
    }

    private static function noOverlayGuard(): string
    {
        return 'Clean social-media marketing photo, high quality, natural lighting, no gibberish text, no fake logos, no watermarks, no UI chrome. Prefer photoreal product or lifestyle visuals without English headline overlays unless typography is explicitly requested. NEVER render owner instructions, briefing chat, or meta lines like "make new posts" on the image.';
    }

    private static function darijaTypographyBlock(string $brief, string $prompt): string
    {
        $hints = self::phraseHints($brief, $prompt);

        return 'ON-IMAGE LANGUAGE (MANDATORY): This is an Algerian shop creative. Any promotional headline or slogan ON the image MUST be Algerian Darija written in correct Arabic script (not English sentences, not arabizi/Latin Darija). Brand or product names may stay Latin next to the Darija. Render sharp, readable Arabic letters with high contrast; no gibberish, no fake logos, no watermarks, no UI chrome. FORBIDDEN on-image text: owner briefing, "make posts", "same concept", campaign meta, chat instructions.'
            .($hints !== '' ? ' Suggested Darija/brand lines to render: '.$hints.'.' : ' Invent short natural Darija promo lines in Arabic script that match the SUBJECT of the image (product/service), never campaign instructions.');
    }

    private static function frenchTypographyBlock(string $brief, string $prompt): string
    {
        $hints = self::phraseHints($brief, $prompt);

        return 'ON-IMAGE LANGUAGE (MANDATORY): This is a French-language shop creative. Any promotional headline or slogan ON the image MUST be clear French (not English). Brand names may stay as written. Sharp readable letters, high contrast; no gibberish, no fake logos, no watermarks, no UI chrome. FORBIDDEN: owner briefing or campaign-meta instructions as headlines.'
            .($hints !== '' ? ' Suggested French/brand lines to render: '.$hints.'.' : ' Invent short natural French promo lines that match the subject.');
    }

    private static function phraseHints(string $brief, string $prompt): string
    {
        $chunks = [];
        // Prefer the creative image_prompt / caption — brief is already sanitized.
        foreach ([$prompt, $brief] as $text) {
            if ($text === '' || self::looksLikeCampaignMeta($text)) {
                continue;
            }
            if (preg_match_all('/[«"“]([^»"”]{2,40})[»"”]/u', $text, $m)) {
                foreach ($m[1] as $q) {
                    $q = trim($q);
                    if ($q !== '' && ! self::looksLikeCampaignMeta($q)) {
                        $chunks[] = '"'.$q.'"';
                    }
                }
            }
            // Keep short Latin product titles like "3 Weekly Pass" (stop before language tags).
            if (preg_match_all('/\b(\d+\s+[A-Za-z][A-Za-z0-9]*(?:\s+[A-Za-z][A-Za-z0-9]*){0,3})\b/u', $text, $m2)) {
                foreach ($m2[1] as $q) {
                    $q = trim($q);
                    if (preg_match('/\b(bl|bel|en|darja|darija|fran)/iu', $q)) {
                        continue;
                    }
                    if (mb_strlen($q) >= 3 && ! self::looksLikeCampaignMeta($q)) {
                        $chunks[] = '"'.$q.'"';
                    }
                }
            }
            if (preg_match_all('/[\x{0600}-\x{06FF}][\x{0600}-\x{06FF}\s]{1,40}/u', $text, $m3)) {
                foreach ($m3[0] as $q) {
                    $q = trim($q);
                    if (mb_strlen($q) >= 3 && ! self::looksLikeCampaignMeta($q)) {
                        $chunks[] = '"'.$q.'"';
                    }
                }
            }
        }

        $chunks = array_values(array_unique($chunks));

        return implode(', ', array_slice($chunks, 0, 4));
    }
}
