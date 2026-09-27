<?php

namespace App\AI;

use App\Models\AgentChatMessage;
use App\Models\Business;

/**
 * Picks the image the owner said yes to, not a later regeneration.
 */
class AffirmedChatImage
{
    /**
     * @return array{apply: bool, asset_ids: list<int>, error?: string}
     */
    public function resolve(Business $business, string $currentUserText): array
    {
        $rows = AgentChatMessage::query()
            ->where('business_id', $business->id)
            ->orderBy('id')
            ->get(['id', 'role', 'content', 'meta']);

        $timeline = [];
        foreach ($rows as $row) {
            $timeline[] = [
                'role' => $row->role,
                'content' => (string) $row->content,
                'asset_ids' => $this->assetIds(is_array($row->meta) ? $row->meta : []),
            ];
        }
        $timeline[] = [
            'role' => 'user',
            'content' => $currentUserText,
            'asset_ids' => [],
        ];

        $affirmIndex = null;
        for ($i = count($timeline) - 1; $i >= 0; $i--) {
            if ($timeline[$i]['role'] !== 'user') {
                continue;
            }
            $before = $i > 0 ? $timeline[$i - 1] : null;
            if ($this->affirmsImage($timeline[$i]['content'], $before['content'] ?? '')) {
                $affirmIndex = $i;
                break;
            }
        }

        if ($affirmIndex === null) {
            return ['apply' => false, 'asset_ids' => []];
        }

        $withAssets = [];
        for ($i = 0; $i < $affirmIndex; $i++) {
            if ($timeline[$i]['role'] === 'assistant' && $timeline[$i]['asset_ids'] !== []) {
                $withAssets[] = $timeline[$i]['asset_ids'];
            }
        }

        if ($withAssets === []) {
            return [
                'apply' => true,
                'asset_ids' => [],
                'error' => 'The owner said to use an image, but that message has no saved image yet.',
            ];
        }

        $chosen = $this->wantsPrevious($timeline[$affirmIndex]['content']) && count($withAssets) >= 2
            ? $withAssets[count($withAssets) - 2]
            : $withAssets[count($withAssets) - 1];

        return ['apply' => true, 'asset_ids' => $chosen];
    }

    public function affirmsImage(string $text, string $previousAssistant = ''): bool
    {
        $t = mb_strtolower(trim($text));
        if ($t === '') {
            return false;
        }

        if (preg_match('/(use it|use this|use the (new|previous|old) one|cette image|استعملها|نستعملها)/iu', $t)) {
            return true;
        }

        $weak = (bool) preg_match('/(^(oui|yes|نعم|واه|هادي|هذه|هذا)$|الأولى|الاولى|القديمة|الجديدة)/iu', $t);
        if (! $weak) {
            return false;
        }

        return (bool) preg_match('/(صورة|image|photo|poster|بوستر)/iu', mb_strtolower($previousAssistant));
    }

    private function wantsPrevious(string $text): bool
    {
        return (bool) preg_match(
            '/(previous|the old one|الأولى|الاولى|القديمة)/iu',
            mb_strtolower($text),
        );
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return list<int>
     */
    private function assetIds(array $meta): array
    {
        $ids = is_array($meta['asset_ids'] ?? null) ? $meta['asset_ids'] : [];

        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }
}
