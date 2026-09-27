<?php

namespace App\Mcp\Tools;

use App\AI\ReplyLanguage;
use App\Mcp\McpContext;
use App\Models\Business;
use App\Models\SocialAccount;

class GetShopProfile extends BaseTool
{
    public function name(): string
    {
        return 'get_shop_profile';
    }

    public function description(): string
    {
        return 'Shop identity: name, description, currency, timezone, home wilaya, reply language, and connected channels. Call first when you need to know who the shop is or which channels exist.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass];
    }

    public function scopes(): array
    {
        return self::READ_ALL;
    }

    public function handle(Business $business, array $arguments, McpContext $context): array
    {
        $profile = [
            'name' => $business->name,
            'description' => $business->description,
            'currency' => $business->currency ?: 'DZD',
            'timezone' => $business->timezone,
            'wilaya' => $business->wilaya,
            'city' => $business->city,
            'reply_language' => ReplyLanguage::forBusiness($business),
        ];

        if (! $context->isCustomer()) {
            $profile['channels'] = SocialAccount::query()
                ->forBusiness($business->id)
                ->where('platform', '!=', 'simulator')
                ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'disconnected'))
                ->orderBy('id')
                ->get()
                ->map(fn (SocialAccount $a) => [
                    'id' => $a->id,
                    'platform' => $a->platform,
                    'name' => $a->name,
                    'username' => $a->username,
                ])
                ->values()
                ->all();
        }

        return ['shop' => $profile];
    }
}
