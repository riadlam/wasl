<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Telegram\TelegramLinkService;
use App\Support\CurrentBusiness;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TelegramSettingsController extends Controller
{
    public function __construct(private TelegramLinkService $links) {}

    public function show(): JsonResponse
    {
        $business = CurrentBusiness::require();
        $settings = $this->links->settingsFor($business);
        $username = (string) config('services.telegram.bot_username');

        return response()->json([
            'configured' => filled(config('services.telegram.bot_token')) && $username !== '',
            'bot_username' => $username !== '' ? '@'.$username : null,
            'linked' => $settings->isLinked(),
            'telegram_username' => $settings->telegram_username,
            'linked_at' => $settings->linked_at?->toIso8601String(),
            'enabled' => (bool) $settings->enabled,
            'notify_new_orders' => (bool) $settings->notify_new_orders,
            'notify_ai_needs_human' => (bool) $settings->notify_ai_needs_human,
            'notify_post_events' => (bool) $settings->notify_post_events,
        ]);
    }

    public function link(): JsonResponse
    {
        $business = CurrentBusiness::require();
        if (! filled(config('services.telegram.bot_token')) || ! filled(config('services.telegram.bot_username'))) {
            return response()->json([
                'message' => 'Telegram bot is not configured on the server.',
            ], 422);
        }

        $link = $this->links->createLink($business);

        return response()->json($link);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'notify_new_orders' => ['sometimes', 'boolean'],
            'notify_ai_needs_human' => ['sometimes', 'boolean'],
            'notify_post_events' => ['sometimes', 'boolean'],
        ]);

        $settings = $this->links->updateToggles(CurrentBusiness::require(), $data);

        return response()->json([
            'enabled' => (bool) $settings->enabled,
            'notify_new_orders' => (bool) $settings->notify_new_orders,
            'notify_ai_needs_human' => (bool) $settings->notify_ai_needs_human,
            'notify_post_events' => (bool) $settings->notify_post_events,
            'linked' => $settings->isLinked(),
        ]);
    }

    public function destroy(): JsonResponse
    {
        $settings = $this->links->unlink(CurrentBusiness::require());

        return response()->json([
            'linked' => false,
            'enabled' => (bool) $settings->enabled,
        ]);
    }
}
