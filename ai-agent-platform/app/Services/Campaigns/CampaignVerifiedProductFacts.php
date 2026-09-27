<?php

namespace App\Services\Campaigns;

use App\Models\Business;
use App\Services\ProductService;

/**
 * Resolve catalog product facts from focus/brief text for grounded campaign captions.
 */
class CampaignVerifiedProductFacts
{
    public function __construct(private ProductService $products) {}

    /**
     * Build a VERIFIED_PRODUCT_FACTS block from focus + brief notes, or empty guidance.
     */
    public function block(Business $business, string $focus, ?string $traceId = null): string
    {
        $hits = $this->resolve($business, $focus, $traceId);
        if ($hits === []) {
            CampaignTrace::warning('campaigns.verified_facts.empty', [
                'trace_id' => $traceId,
                'business_id' => $business->id,
                'focus_preview' => CampaignTrace::clip($focus, 400),
                'skipped' => 'no_catalog_match',
                'note' => 'Draft must not invent product mechanics; name/price/CTA from owner brief only.',
            ]);

            return <<<'TEXT'
VERIFIED_PRODUCT_FACTS (only source for product claims):
(none matched in catalog)
If this block is empty: write only name/price/CTA that the owner brief explicitly stated — invent ZERO pack contents, game mechanics, or daily benefits.
TEXT;
        }

        $lines = ['VERIFIED_PRODUCT_FACTS (only source for product claims):'];
        foreach ($hits as $p) {
            $lines[] = $this->formatProduct($p);
        }
        $lines[] = 'You may ONLY state product benefits that appear above or as an obvious paraphrase of the owner briefing. Empty description ⇒ name + price + CTA only — no invented mechanics.';

        $block = implode("\n", $lines);
        CampaignTrace::info('campaigns.verified_facts.matched', [
            'trace_id' => $traceId,
            'business_id' => $business->id,
            'product_ids' => array_map(fn ($p) => $p['id'] ?? null, $hits),
            'product_names' => array_map(fn ($p) => $p['name'] ?? null, $hits),
            'descriptions_empty' => array_map(
                fn ($p) => trim((string) ($p['description'] ?? '')) === '',
                $hits
            ),
            'block_preview' => CampaignTrace::clip($block, 1500),
        ]);

        return $block;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function resolve(Business $business, string $focus, ?string $traceId = null): array
    {
        $terms = $this->candidateTerms($focus);
        if ($terms === []) {
            CampaignTrace::info('campaigns.verified_facts.no_terms', [
                'trace_id' => $traceId,
                'business_id' => $business->id,
                'focus_preview' => CampaignTrace::clip($focus, 400),
                'skipped' => 'no_search_terms_extracted',
            ]);

            return [];
        }

        CampaignTrace::info('campaigns.verified_facts.search', [
            'trace_id' => $traceId,
            'business_id' => $business->id,
            'terms' => $terms,
        ]);

        $byId = [];
        $misses = [];
        foreach ($terms as $term) {
            $rows = $this->products->search($business, $term, 3);
            if ($rows === []) {
                $misses[] = $term;
                continue;
            }
            foreach ($rows as $row) {
                $id = (int) ($row['id'] ?? 0);
                if ($id < 1 || isset($byId[$id])) {
                    continue;
                }
                $full = $this->products->get($business, $id);
                if (is_array($full)) {
                    $byId[$id] = $full;
                    CampaignTrace::info('campaigns.verified_facts.hit', [
                        'trace_id' => $traceId,
                        'business_id' => $business->id,
                        'term' => $term,
                        'product_id' => $id,
                        'name' => $full['name'] ?? null,
                        'price' => $full['price'] ?? null,
                        'has_description' => trim((string) ($full['description'] ?? '')) !== '',
                    ]);
                }
                if (count($byId) >= 2) {
                    break 2;
                }
            }
        }

        if ($misses !== []) {
            CampaignTrace::info('campaigns.verified_facts.term_misses', [
                'trace_id' => $traceId,
                'business_id' => $business->id,
                'terms_with_no_rows' => array_values(array_unique($misses)),
            ]);
        }

        return array_values($byId);
    }

    /**
     * @return list<string>
     */
    private function candidateTerms(string $focus): array
    {
        $focus = trim($focus);
        if ($focus === '') {
            return [];
        }

        $terms = [];

        // Title-case / Latin product phrases (Weekly Diamond Pass, Mobile Legends)
        if (preg_match_all('/\b[A-Z][A-Za-z0-9]+(?:\s+[A-Z][A-Za-z0-9]+){0,5}\b/u', $focus, $m)) {
            foreach ($m[0] as $chunk) {
                $terms[] = trim($chunk);
            }
        }

        // Individual Latin tokens (Weekly, Diamond, Pass…)
        if (preg_match_all('/\b[A-Za-z][A-Za-z0-9\-]{2,30}\b/u', $focus, $words)) {
            foreach ($words[0] as $w) {
                $terms[] = $w;
            }
        }

        // Arabic / Darija runs of 3+ letters
        if (preg_match_all('/[\x{0600}-\x{06FF}]{3,40}/u', $focus, $ar)) {
            foreach ($ar[0] as $chunk) {
                $terms[] = $chunk;
            }
        }

        $out = [];
        $seen = [];
        $skip = '/^(the|and|for|with|from|only|price|owner|briefing|focus|product|shop|push|buyers|at|da|dzd)$/iu';
        foreach ($terms as $t) {
            $t = trim($t);
            if (mb_strlen($t) < 3 || preg_match($skip, $t)) {
                continue;
            }
            $key = mb_strtolower($t);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $t;
            if (count($out) >= 12) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $p
     */
    private function formatProduct(array $p): string
    {
        $name = (string) ($p['name'] ?? '');
        $price = $p['price'] ?? '';
        $desc = trim((string) ($p['description'] ?? ''));
        $type = (string) ($p['type'] ?? 'physical');
        $stock = $p['stock'] ?? '';
        $note = trim((string) data_get($p, 'digital.delivery_note', ''));
        $variants = [];
        foreach ($p['variants'] ?? [] as $v) {
            if (! is_array($v)) {
                continue;
            }
            $vn = trim((string) ($v['name'] ?? ''));
            if ($vn !== '') {
                $variants[] = $vn.(isset($v['price']) ? ' @ '.$v['price'].' DA' : '');
            }
        }

        $parts = [
            "- #{$p['id']} {$name}",
            "price {$price} DA",
            "type {$type}",
            "stock {$stock}",
        ];
        if ($desc !== '') {
            $parts[] = 'description: '.mb_substr($desc, 0, 500);
        } else {
            $parts[] = 'description: (empty — do not invent features)';
        }
        if ($note !== '') {
            $parts[] = 'digital_delivery_note: '.mb_substr($note, 0, 300);
        }
        if ($variants !== []) {
            $parts[] = 'variants: '.implode('; ', array_slice($variants, 0, 8));
        }

        return implode(' | ', $parts);
    }
}
