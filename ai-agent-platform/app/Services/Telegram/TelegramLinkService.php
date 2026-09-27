<?php

namespace App\Services\Telegram;

use App\Events\TelegramMerchantLinked;
use App\Models\Business;
use App\Models\BusinessTelegramSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class TelegramLinkService
{
    public const CODE_TTL_MINUTES = 10;

    public function settingsFor(Business $business): BusinessTelegramSetting
    {
        return BusinessTelegramSetting::query()->firstOrCreate(
            ['business_id' => $business->id],
            [
                'enabled' => true,
                'notify_new_orders' => true,
                'notify_ai_needs_human' => true,
                'notify_post_events' => true,
            ],
        );
    }

    /**
     * @return array{code: string, deep_link: string, expires_in: int}
     */
    public function createLink(Business $business): array
    {
        $code = Str::lower(Str::random(24));
        Cache::put($this->cacheKey($code), $business->id, now()->addMinutes(self::CODE_TTL_MINUTES));

        $username = (string) config('services.telegram.bot_username');
        $deepLink = $username !== ''
            ? 'https://t.me/'.$username.'?start='.$code
            : '';

        return [
            'code' => $code,
            'deep_link' => $deepLink,
            'expires_in' => self::CODE_TTL_MINUTES * 60,
        ];
    }

    public function bindFromStart(string $code, string $chatId, ?string $username = null): ?Business
    {
        $businessId = Cache::pull($this->cacheKey(trim($code)));
        if (! $businessId) {
            return null;
        }

        $business = Business::query()->find($businessId);
        if (! $business) {
            return null;
        }

        $settings = $this->settingsFor($business);
        $settings->update([
            'telegram_chat_id' => $chatId,
            'telegram_username' => $username ? ltrim($username, '@') : $settings->telegram_username,
            'linked_at' => now(),
            'enabled' => true,
        ]);

        $settings = $settings->fresh();
        event(new TelegramMerchantLinked($business, $settings));

        return $business;
    }

    public function unlink(Business $business): BusinessTelegramSetting
    {
        $settings = $this->settingsFor($business);
        $settings->update([
            'telegram_chat_id' => null,
            'telegram_username' => null,
            'linked_at' => null,
        ]);

        return $settings->fresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateToggles(Business $business, array $data): BusinessTelegramSetting
    {
        $settings = $this->settingsFor($business);
        $settings->fill(array_intersect_key($data, array_flip([
            'enabled',
            'notify_new_orders',
            'notify_ai_needs_human',
            'notify_post_events',
        ])));
        $settings->save();

        return $settings->fresh();
    }

    private function cacheKey(string $code): string
    {
        return 'telegram:link:'.Str::lower($code);
    }
}
