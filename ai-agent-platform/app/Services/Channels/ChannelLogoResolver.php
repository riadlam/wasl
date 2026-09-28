<?php

namespace App\Services\Channels;

use App\Models\Business;
use App\Models\SocialAccount;

class ChannelLogoResolver
{
    /**
     * Resolve the shop's channel logo URL (custom upload → page avatar).
     * Prefers a platform-matched connected account when $platform is set.
     */
    public function resolveUrl(Business $business, ?string $platform = null): ?string
    {
        $platform = is_string($platform) ? strtolower(trim($platform)) : '';

        if ($platform !== '' && $platform !== 'simulator') {
            $matched = $this->queryBase($business)
                ->where('platform', $platform)
                ->orderByDesc('id')
                ->first();

            $url = $matched?->resolvedLogoUrl();
            if (is_string($url) && $url !== '') {
                return $url;
            }
        }

        $fallback = $this->queryBase($business)
            ->where('platform', '!=', 'simulator')
            ->orderByDesc('id')
            ->get()
            ->map(fn (SocialAccount $a) => $a->resolvedLogoUrl())
            ->first(fn ($u) => is_string($u) && $u !== '');

        return is_string($fallback) && $fallback !== '' ? $fallback : null;
    }

    /**
     * Mandatory PAGE_LOGO prompt prefix, or null when no logo is available.
     */
    public function mandatoryPromptPrefix(Business $business, ?string $platform = null): ?string
    {
        $url = $this->resolveUrl($business, $platform);
        if ($url === null) {
            return null;
        }

        return 'PAGE_LOGO (MANDATORY): '.$url
            .' — You MUST include this exact business page logo/mark in the image '
            .'(small corner watermark-style, never covering the main product). '
            .'Do NOT invent a different logo or omit this mark.';
    }

    /**
     * @return array{error: string, code: string}
     */
    public function missingLogoError(): array
    {
        return [
            'error' => 'Channel page logo is required before generating post images. Connect a channel or upload the page logo in Channels.',
            'code' => 'channel.logo_required',
        ];
    }

    private function queryBase(Business $business)
    {
        return SocialAccount::query()
            ->where('business_id', $business->id)
            ->where('provider', 'socialapi')
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', '!=', 'disconnected');
            })
            ->with('logoAsset');
    }
}
