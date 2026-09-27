<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramBotClient
{
    public function configured(): bool
    {
        return filled(config('services.telegram.bot_token'));
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $inlineKeyboard  rows of [{text, callback_data}]
     * @return array{ok: bool, message_id?: string, chat_id?: string, error?: string, file_id?: string|null}
     */
    public function sendMessage(string $chatId, string $text, ?array $inlineKeyboard = null): array
    {
        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'disable_web_page_preview' => true,
        ];
        if ($inlineKeyboard) {
            $payload['reply_markup'] = [
                'inline_keyboard' => $inlineKeyboard,
            ];
        }

        return $this->call('sendMessage', $payload);
    }

    /**
     * Send a photo with caption (max 1024 chars). Prefer raw bytes so Telegram does not need to fetch our URL.
     *
     * @param  array<int, array<string, mixed>>|null  $inlineKeyboard
     * @return array{ok: bool, message_id?: string, chat_id?: string, error?: string, file_id?: string|null}
     */
    public function sendPhoto(
        string $chatId,
        string $photoBytes,
        string $filename = 'post.jpg',
        ?string $caption = null,
        ?array $inlineKeyboard = null,
    ): array {
        $fields = [
            'chat_id' => $chatId,
        ];
        if ($caption !== null && $caption !== '') {
            $fields['caption'] = mb_substr($caption, 0, 1024);
        }
        if ($inlineKeyboard) {
            $fields['reply_markup'] = json_encode(['inline_keyboard' => $inlineKeyboard], JSON_UNESCAPED_UNICODE);
        }

        return $this->callMultipart('sendPhoto', $fields, 'photo', $photoBytes, $filename);
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $inlineKeyboard  null = leave markup unchanged; [] = remove buttons
     * @return array{ok: bool, message_id?: string, chat_id?: string, error?: string, file_id?: string|null}
     */
    public function editMessageText(
        string $chatId,
        string $messageId,
        string $text,
        ?array $inlineKeyboard = [],
    ): array {
        $payload = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
            'disable_web_page_preview' => true,
        ];
        if ($inlineKeyboard !== null) {
            $payload['reply_markup'] = [
                'inline_keyboard' => $inlineKeyboard,
            ];
        }

        return $this->call('editMessageText', $payload);
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $inlineKeyboard  null = leave; [] = clear
     * @return array{ok: bool, message_id?: string, chat_id?: string, error?: string, file_id?: string|null}
     */
    public function editMessageCaption(
        string $chatId,
        string $messageId,
        string $caption,
        ?array $inlineKeyboard = [],
    ): array {
        $payload = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'caption' => mb_substr($caption, 0, 1024),
        ];
        if ($inlineKeyboard !== null) {
            $payload['reply_markup'] = [
                'inline_keyboard' => $inlineKeyboard,
            ];
        }

        return $this->call('editMessageCaption', $payload);
    }

    /**
     * Replace photo + caption on an existing media message.
     *
     * @param  array<int, array<string, mixed>>|null  $inlineKeyboard  null = leave; [] = clear
     * @return array{ok: bool, message_id?: string, chat_id?: string, error?: string, file_id?: string|null}
     */
    public function editMessagePhoto(
        string $chatId,
        string $messageId,
        string $photoBytes,
        string $filename = 'post.jpg',
        ?string $caption = null,
        ?array $inlineKeyboard = [],
    ): array {
        $media = [
            'type' => 'photo',
            'media' => 'attach://photo',
        ];
        if ($caption !== null) {
            $media['caption'] = mb_substr($caption, 0, 1024);
        }

        $fields = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'media' => json_encode($media, JSON_UNESCAPED_UNICODE),
        ];
        if ($inlineKeyboard !== null) {
            $fields['reply_markup'] = json_encode(['inline_keyboard' => $inlineKeyboard], JSON_UNESCAPED_UNICODE);
        }

        return $this->callMultipart('editMessageMedia', $fields, 'photo', $photoBytes, $filename);
    }

    public function deleteMessage(string $chatId, string $messageId): array
    {
        return $this->call('deleteMessage', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
        ]);
    }

    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null): void
    {
        $payload = ['callback_query_id' => $callbackQueryId];
        if ($text !== null && $text !== '') {
            $payload['text'] = mb_substr($text, 0, 200);
        }
        $this->call('answerCallbackQuery', $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, message_id?: string, chat_id?: string, error?: string, file_id?: string|null}
     */
    private function call(string $method, array $payload): array
    {
        $token = (string) config('services.telegram.bot_token');
        if ($token === '') {
            return ['ok' => false, 'error' => 'Telegram bot token is not configured.', 'file_id' => null];
        }

        try {
            $response = Http::timeout(15)
                ->asJson()
                ->post("https://api.telegram.org/bot{$token}/{$method}", $payload);

            return $this->normalizeResponse($response->json(), $response->successful(), $response->body(), $payload['chat_id'] ?? null);
        } catch (Throwable $e) {
            Log::warning('telegram.api_exception', ['method' => $method, 'error' => $e->getMessage()]);

            return ['ok' => false, 'error' => $e->getMessage(), 'file_id' => null];
        }
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array{ok: bool, message_id?: string, chat_id?: string, error?: string, file_id?: string|null}
     */
    private function callMultipart(
        string $method,
        array $fields,
        string $fileField,
        string $bytes,
        string $filename,
    ): array {
        $token = (string) config('services.telegram.bot_token');
        if ($token === '') {
            return ['ok' => false, 'error' => 'Telegram bot token is not configured.', 'file_id' => null];
        }

        try {
            $response = Http::timeout(60)
                ->attach($fileField, $bytes, $filename ?: 'post.jpg')
                ->post("https://api.telegram.org/bot{$token}/{$method}", $fields);

            return $this->normalizeResponse($response->json(), $response->successful(), $response->body(), $fields['chat_id'] ?? null);
        } catch (Throwable $e) {
            Log::warning('telegram.api_exception', ['method' => $method, 'error' => $e->getMessage()]);

            return ['ok' => false, 'error' => $e->getMessage(), 'file_id' => null];
        }
    }

    /**
     * @param  mixed  $json
     * @return array{ok: bool, message_id?: string, chat_id?: string, error?: string, file_id?: string|null}
     */
    private function normalizeResponse(mixed $json, bool $successful, string $rawBody, mixed $fallbackChatId): array
    {
        if (! $successful || ! is_array($json) || empty($json['ok'])) {
            $error = is_array($json) ? (string) ($json['description'] ?? $rawBody) : $rawBody;
            Log::warning('telegram.api_failed', ['error' => $error]);

            return ['ok' => false, 'error' => $error, 'file_id' => null];
        }

        $result = is_array($json['result'] ?? null) ? $json['result'] : [];
        $fileId = null;
        $photos = $result['photo'] ?? null;
        if (is_array($photos) && $photos !== []) {
            $largest = end($photos);
            $fileId = is_array($largest) ? (string) ($largest['file_id'] ?? '') : null;
            $fileId = $fileId !== '' ? $fileId : null;
        }

        return [
            'ok' => true,
            'message_id' => isset($result['message_id']) ? (string) $result['message_id'] : null,
            'chat_id' => isset($result['chat']['id']) ? (string) $result['chat']['id'] : (isset($fallbackChatId) ? (string) $fallbackChatId : null),
            'file_id' => $fileId,
        ];
    }
}
