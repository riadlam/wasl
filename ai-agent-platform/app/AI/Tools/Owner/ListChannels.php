<?php

namespace App\AI\Tools\Owner;

use App\AI\Tools\AgentTool;
use App\Models\Business;
use App\Models\SocialAccount;

class ListChannels implements AgentTool
{
    public function name(): string
    {
        return 'list_channels';
    }

    public function description(): string
    {
        return 'List connected social channels (pages/accounts) for this shop. Returns local id, socialapi_account_id, '
            .'and logo_url (custom upload or SocialAPI page picture) for branding on creatives.';
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
        $channels = SocialAccount::query()
            ->where('business_id', $business->id)
            ->where('provider', 'socialapi')
            ->where('platform', '!=', 'simulator')
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', '!=', 'disconnected');
            })
            ->with('logoAsset')
            ->orderBy('id')
            ->get()
            ->map(fn (SocialAccount $a) => [
                'id' => $a->id,
                'platform' => $a->platform,
                'name' => $a->name,
                'username' => $a->username,
                'socialapi_account_id' => $a->socialapi_account_id,
                'logo_url' => $a->resolvedLogoUrl(),
                'has_custom_logo' => $a->hasCustomLogo(),
                'avatar_url' => $a->avatar_url,
            ])
            ->values()
            ->all();

        return ['channels' => $channels];
    }
}
