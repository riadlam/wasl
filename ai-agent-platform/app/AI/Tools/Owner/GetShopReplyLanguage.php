<?php

namespace App\AI\Tools\Owner;

use App\AI\ReplyLanguage;
use App\AI\Tools\AgentTool;
use App\Models\Business;

/**
 * Shop reply language for TranslationAgent — Darija vs French from agent settings.
 */
class GetShopReplyLanguage implements AgentTool
{
    public function name(): string
    {
        return 'get_shop_reply_language';
    }

    public function description(): string
    {
        return 'Read the logged-in shop reply language from agent settings (Darija or French). '
            .'Use before translating owner-facing text. Does not change settings.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => (object) [],
        ];
    }

    public function handle(Business $business, array $arguments, array $context = []): array
    {
        $business->loadMissing(['agent', 'agentSettings']);
        $raw = (string) ($business->agentSettings?->language ?: $business->agent?->language ?: '');
        $normalized = ReplyLanguage::forBusiness($business);

        return [
            'ok' => true,
            'language' => $normalized,
            'language_raw' => $raw,
            'label' => $normalized === ReplyLanguage::FRENCH ? 'French' : 'Algerian Darija',
            'script_hint' => $normalized === ReplyLanguage::FRENCH
                ? 'French (Latin script)'
                : 'Algerian Darija (Arabic or Latin Arabizi — prefer natural Maghreb phrasing)',
            'source' => $business->agentSettings?->language
                ? 'agent_settings'
                : ($business->agent?->language ? 'agent' : 'default'),
        ];
    }
}
