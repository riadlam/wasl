<?php

namespace App\AI\Skills;

use App\AI\ReplyLanguage;
use App\Models\AiProfilePerChannel;
use App\Models\Business;
use Illuminate\Support\Facades\Cache;

class SkillRegistry
{
    /**
     * @return array<string, array{name: string, description: string, surfaces: list<string>, body: string}>
     */
    public function all(): array
    {
        $loader = function (): array {
            $root = (string) config('ai_skills.path');
            $skills = [];
            if (! is_dir($root)) {
                return $skills;
            }

            foreach (glob($root.DIRECTORY_SEPARATOR.'*'.DIRECTORY_SEPARATOR.'SKILL.md') ?: [] as $file) {
                $parsed = $this->parse((string) file_get_contents($file));
                if ($parsed === null) {
                    continue;
                }
                $skills[$parsed['name']] = $parsed;
            }

            return $skills;
        };

        if (app()->environment('testing')) {
            return $loader();
        }

        // Bust when any SKILL.md changes so prompt edits apply without waiting on TTL.
        $stamp = '0';
        $root = (string) config('ai_skills.path');
        if (is_dir($root)) {
            foreach (glob($root.DIRECTORY_SEPARATOR.'*'.DIRECTORY_SEPARATOR.'SKILL.md') ?: [] as $file) {
                $stamp .= '|'.(string) @filemtime($file);
            }
        }

        return Cache::remember('ai_skills.registry.'.md5($stamp), 300, $loader);
    }

    public function promptFor(string $surface, ?Business $business = null, array $context = []): string
    {
        $enabled = config('ai_skills.surfaces.'.$surface, []);
        if (! is_array($enabled) || $enabled === []) {
            return '';
        }

        $skills = $this->all();
        $blocks = [];
        foreach ($enabled as $name) {
            $skill = $skills[$name] ?? null;
            if (! is_array($skill)) {
                continue;
            }
            if ($skill['surfaces'] !== [] && ! in_array($surface, $skill['surfaces'], true)) {
                continue;
            }
            $blocks[] = $this->fill($skill['body'], $business, $context);
        }

        return trim(implode("\n\n", $blocks));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function fill(string $body, ?Business $business, array $context): string
    {
        $language = (string) ($context['reply_language'] ?? ($business ? ReplyLanguage::forBusiness($business) : ReplyLanguage::DARIJA));
        $label = ReplyLanguage::normalize($language) === ReplyLanguage::FRENCH ? 'French' : 'Algerian Darija';

        $replacements = [
            '{{reply_language}}' => $label,
            '{{page_name}}' => (string) ($context['page_name'] ?? $business?->name ?? 'the page'),
            '{{offer_type}}' => (string) ($context['offer_type'] ?? 'unknown'),
        ];

        return strtr($body, $replacements);
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return array{page_name: string, offer_type: string}
     */
    public function contextFromProfile(?AiProfilePerChannel $profile, ?Business $business = null): array
    {
        $data = is_array($profile?->profile) ? $profile->profile : [];
        $account = $profile?->socialAccount;
        $page = (string) ($data['channel']['page_name'] ?? $data['business']['display_name'] ?? '');
        if ($page === '' && $account) {
            $page = (string) ($account->name ?: $account->username ?: '');
        }
        if ($page === '') {
            $page = (string) ($business?->name ?? '');
        }
        $offer = (string) ($data['business']['offer_type'] ?? $data['offer_type'] ?? '');
        if ($offer === '' && ($data['storage'] ?? '') !== 'supabase') {
            $offer = $this->inferOffer($data);
        }
        $answers = is_array($data['interview_answers'] ?? null) ? $data['interview_answers'] : [];
        if ($offer === '' && isset($answers['business.offer_type'])) {
            $offer = (string) $answers['business.offer_type'];
        }

        return [
            'page_name' => $page !== '' ? $page : 'the page',
            'offer_type' => $offer !== '' ? $offer : 'unknown',
        ];
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    public function inferOffer(array $profile): string
    {
        $chunks = [
            (string) ($profile['business']['offer_type'] ?? ''),
            (string) ($profile['business']['what_we_sell'] ?? ''),
            (string) ($profile['business']['what_we_sell_detail'] ?? ''),
        ];
        foreach ($profile['catalog_signals'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $chunks[] = (string) ($row['name_or_category'] ?? '');
            $chunks[] = (string) ($row['notes'] ?? '');
        }
        $text = strtolower(implode(' ', $chunks));
        foreach (['digital', 'download', 'ebook', 'course', 'cours', 'formation', 'pdf', 'abonnement', 'saas', 'online'] as $needle) {
            if (str_contains($text, $needle)) {
                return 'digital';
            }
        }

        return '';
    }

    /**
     * @return array{name: string, description: string, surfaces: list<string>, body: string}|null
     */
    private function parse(string $raw): ?array
    {
        if (! preg_match('/^---\s*\R(.*?)\R---\s*\R(.*)$/s', trim($raw), $match)) {
            return null;
        }

        $meta = [];
        foreach (preg_split('/\R/', $match[1]) ?: [] as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }
            [$key, $value] = explode(':', $line, 2);
            $meta[trim($key)] = trim($value);
        }

        $name = $meta['name'] ?? '';
        if ($name === '') {
            return null;
        }

        $surfaces = array_values(array_filter(array_map('trim', explode(',', $meta['metadata.surfaces'] ?? ''))));

        return [
            'name' => $name,
            'description' => $meta['description'] ?? '',
            'surfaces' => $surfaces,
            'body' => trim($match[2]),
        ];
    }
}
