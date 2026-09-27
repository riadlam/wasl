<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Telegram\TelegramWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request, string $secret, TelegramWebhookService $webhooks): JsonResponse
    {
        $expected = (string) config('services.telegram.webhook_secret');
        if ($expected === '' || ! hash_equals($expected, $secret)) {
            abort(404);
        }

        $payload = $request->all();
        if (is_array($payload)) {
            \Illuminate\Support\Facades\Log::info('telegram.webhook', [
                'has_message' => isset($payload['message']),
                'has_callback' => isset($payload['callback_query']),
                'text' => isset($payload['message']['text']) ? mb_substr((string) $payload['message']['text'], 0, 80) : null,
            ]);
            $webhooks->handle($payload);
        }

        return response()->json(['ok' => true]);
    }
}
