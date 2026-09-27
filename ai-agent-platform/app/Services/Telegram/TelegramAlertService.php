<?php

namespace App\Services\Telegram;

use App\Jobs\Telegram\SendTelegramAlertJob;
use App\Models\Business;
use App\Models\BusinessTelegramSetting;
use App\Models\TelegramOutboundMessage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramAlertService
{
    public function __construct(
        private TelegramBotClient $bot,
        private TelegramLinkService $links,
    ) {}

    /**
     * Queue a new Telegram alert (persists outbound message when sent).
     *
     * @param  array<int, array<string, mixed>>|null  $inlineKeyboard
     * @param  array{bytes: string, filename?: string, asset_id?: int|null}|null  $photo
     */
    public function notify(
        Business $business,
        string $kind,
        string $text,
        ?Model $subject = null,
        ?array $inlineKeyboard = null,
        ?array $photo = null,
    ): void {
        try {
            $settings = $this->links->settingsFor($business);
            if (! $settings->allows($kind)) {
                Log::info('telegram.notify_skipped', [
                    'business_id' => $business->id,
                    'kind' => $kind,
                    'linked' => $settings->isLinked(),
                    'enabled' => (bool) $settings->enabled,
                ]);

                return;
            }
            if (! $this->bot->configured()) {
                Log::warning('telegram.notify_skipped', [
                    'business_id' => $business->id,
                    'kind' => $kind,
                    'reason' => 'bot_not_configured',
                ]);

                return;
            }

            SendTelegramAlertJob::dispatch(
                businessId: $business->id,
                kind: $kind,
                text: $text,
                subjectType: $subject ? $subject::class : null,
                subjectId: $subject?->getKey(),
                inlineKeyboard: $inlineKeyboard,
                mode: 'send',
                photo: $this->serializePhotoForJob($photo),
            );
        } catch (Throwable $e) {
            Log::warning('telegram.notify_failed', ['error' => $e->getMessage(), 'kind' => $kind]);
        }
    }

    /**
     * Edit a previously sent alert for this subject+kind (sync preferred for button UX).
     *
     * @param  array<int, array<string, mixed>>|null  $inlineKeyboard  null = leave; [] = clear buttons
     * @param  array{bytes: string, filename?: string, asset_id?: int|null}|null  $photo  null = keep existing media
     */
    public function updateFor(
        Model $subject,
        string $kind,
        string $text,
        ?array $inlineKeyboard = [],
        bool $sync = true,
        ?array $photo = null,
    ): void {
        try {
            $businessId = (int) ($subject->getAttribute('business_id') ?? 0);
            if ($businessId <= 0 && $subject instanceof \App\Models\AiCampaignSlot) {
                $businessId = (int) ($subject->campaign()?->value('business_id')
                    ?? \App\Models\AiCampaign::query()->whereKey($subject->ai_campaign_id)->value('business_id')
                    ?? 0);
            }
            if ($businessId <= 0) {
                return;
            }

            if ($sync) {
                $this->editNow($kind, $text, $subject::class, $subject->getKey(), $inlineKeyboard, $photo);

                return;
            }

            SendTelegramAlertJob::dispatch(
                businessId: $businessId,
                kind: $kind,
                text: $text,
                subjectType: $subject::class,
                subjectId: $subject->getKey(),
                inlineKeyboard: $inlineKeyboard,
                mode: 'edit',
                photo: $this->serializePhotoForJob($photo),
            );
        } catch (Throwable $e) {
            Log::warning('telegram.update_failed', ['error' => $e->getMessage(), 'kind' => $kind]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $inlineKeyboard
     * @param  array{bytes: string, filename?: string, asset_id?: int|null}|null  $photo
     * @return bool True when Telegram accepted the message
     */
    public function sendNow(
        Business $business,
        BusinessTelegramSetting $settings,
        string $kind,
        string $text,
        ?Model $subject = null,
        ?array $inlineKeyboard = null,
        ?array $photo = null,
    ): bool {
        if (! $settings->allows($kind) || ! $settings->telegram_chat_id) {
            return false;
        }

        $chatId = (string) $settings->telegram_chat_id;
        $photoBytes = is_string($photo['bytes'] ?? null) ? (string) $photo['bytes'] : '';
        $hasPhoto = $photoBytes !== '';
        $filename = (string) ($photo['filename'] ?? 'post.jpg');
        $assetId = isset($photo['asset_id']) ? (int) $photo['asset_id'] : null;

        // Reuse the same Telegram message for this subject+kind when possible.
        if ($subject) {
            $existing = TelegramOutboundMessage::query()
                ->where('subject_type', $subject::class)
                ->where('subject_id', $subject->getKey())
                ->where('kind', $kind)
                ->first();
            if ($existing && $existing->chat_id && $existing->message_id) {
                $edited = $this->editExisting(
                    $existing,
                    $text,
                    $inlineKeyboard ?? [],
                    $hasPhoto ? ['bytes' => $photoBytes, 'filename' => $filename, 'asset_id' => $assetId] : null,
                );
                if ($edited) {
                    return true;
                }
                Log::warning('telegram.edit_fallback_to_send', [
                    'business_id' => $business->id,
                    'kind' => $kind,
                    'subject_id' => $subject->getKey(),
                    'message_id' => (string) $existing->message_id,
                ]);
                // Fall through to send a new message if edit failed (e.g. deleted).
            }
        }

        $result = $hasPhoto
            ? $this->bot->sendPhoto($chatId, $photoBytes, $filename, $text, $inlineKeyboard)
            : $this->bot->sendMessage($chatId, $text, $inlineKeyboard);

        if (empty($result['ok']) || empty($result['message_id'])) {
            Log::warning('telegram.send_now_failed', [
                'business_id' => $business->id,
                'kind' => $kind,
                'chat_id' => $chatId,
                'error' => $result['error'] ?? 'unknown',
                'with_photo' => $hasPhoto,
                'subject_type' => $subject ? $subject::class : null,
                'subject_id' => $subject?->getKey(),
            ]);

            return false;
        }

        if ($subject) {
            TelegramOutboundMessage::query()->updateOrCreate(
                [
                    'subject_type' => $subject::class,
                    'subject_id' => $subject->getKey(),
                    'kind' => $kind,
                ],
                [
                    'business_id' => $business->id,
                    'chat_id' => (string) ($result['chat_id'] ?: $chatId),
                    'message_id' => (string) $result['message_id'],
                    'last_text' => $text,
                    'metadata' => [
                        'keyboard' => (bool) $inlineKeyboard,
                        'has_photo' => $hasPhoto,
                        'asset_id' => $assetId,
                        'file_id' => $result['file_id'] ?? null,
                    ],
                ],
            );
        }

        return true;
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $inlineKeyboard  null = leave; [] = clear
     * @param  array{bytes: string, filename?: string, asset_id?: int|null}|null  $photo
     */
    public function editNow(
        string $kind,
        string $text,
        string $subjectType,
        int|string $subjectId,
        ?array $inlineKeyboard = [],
        ?array $photo = null,
    ): bool {
        $row = TelegramOutboundMessage::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where('kind', $kind)
            ->first();

        if (! $row) {
            return false;
        }

        return $this->editExisting($row, $text, $inlineKeyboard, $photo);
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $inlineKeyboard
     * @param  array{bytes: string, filename?: string, asset_id?: int|null}|null  $photo  null = keep media as-is
     */
    private function editExisting(
        TelegramOutboundMessage $row,
        string $text,
        ?array $inlineKeyboard,
        ?array $photo,
    ): bool {
        $meta = is_array($row->metadata) ? $row->metadata : [];
        $wasPhoto = ! empty($meta['has_photo']);
        $photoBytes = is_string($photo['bytes'] ?? null) ? (string) $photo['bytes'] : '';
        $wantPhoto = $photoBytes !== '';
        $filename = (string) ($photo['filename'] ?? 'post.jpg');
        $assetId = array_key_exists('asset_id', $photo ?? [])
            ? (isset($photo['asset_id']) ? (int) $photo['asset_id'] : null)
            : ($meta['asset_id'] ?? null);
        $prevAssetId = isset($meta['asset_id']) ? (int) $meta['asset_id'] : null;
        $assetChanged = $wantPhoto && $wasPhoto
            && (int) ($assetId ?? 0) !== (int) ($prevAssetId ?? 0);

        $result = ['ok' => false, 'error' => 'unhandled'];

        if ($wantPhoto && ! $wasPhoto) {
            // Upgrade text → photo: delete old, send photo, keep same outbound row.
            $this->bot->deleteMessage((string) $row->chat_id, (string) $row->message_id);
            $result = $this->bot->sendPhoto(
                (string) $row->chat_id,
                $photoBytes,
                $filename,
                $text,
                $inlineKeyboard ?? [],
            );
            if (! empty($result['ok']) && ! empty($result['message_id'])) {
                $row->update([
                    'message_id' => (string) $result['message_id'],
                    'chat_id' => (string) ($result['chat_id'] ?: $row->chat_id),
                    'last_text' => $text,
                    'metadata' => [
                        'keyboard' => is_array($inlineKeyboard) && $inlineKeyboard !== [],
                        'has_photo' => true,
                        'asset_id' => $assetId,
                        'file_id' => $result['file_id'] ?? null,
                    ],
                ]);

                return true;
            }

            return false;
        }

        if ($wasPhoto && $wantPhoto && $assetChanged) {
            $result = $this->bot->editMessagePhoto(
                (string) $row->chat_id,
                (string) $row->message_id,
                $photoBytes,
                $filename,
                $text,
                $inlineKeyboard,
            );
        } elseif ($wasPhoto) {
            $result = $this->bot->editMessageCaption(
                (string) $row->chat_id,
                (string) $row->message_id,
                $text,
                $inlineKeyboard,
            );
        } else {
            $result = $this->bot->editMessageText(
                (string) $row->chat_id,
                (string) $row->message_id,
                $text,
                $inlineKeyboard,
            );
        }

        $notModified = str_contains((string) ($result['error'] ?? ''), 'message is not modified');
        if (! empty($result['ok']) || $notModified) {
            $row->update([
                'last_text' => $text,
                'metadata' => array_merge($meta, [
                    'keyboard' => is_array($inlineKeyboard) && $inlineKeyboard !== [],
                    'has_photo' => $wasPhoto || $wantPhoto,
                    'asset_id' => $assetId ?? ($meta['asset_id'] ?? null),
                    'file_id' => $result['file_id'] ?? ($meta['file_id'] ?? null),
                ]),
            ]);

            return true;
        }

        // Caption edit can fail if Telegram thinks it's a text message — try text as last resort.
        if ($wasPhoto && str_contains(strtolower((string) ($result['error'] ?? '')), 'there is no caption')) {
            $fallback = $this->bot->editMessageText(
                (string) $row->chat_id,
                (string) $row->message_id,
                $text,
                $inlineKeyboard,
            );
            $ok = ! empty($fallback['ok']) || str_contains((string) ($fallback['error'] ?? ''), 'message is not modified');
            if ($ok) {
                $row->update([
                    'last_text' => $text,
                    'metadata' => array_merge($meta, [
                        'keyboard' => is_array($inlineKeyboard) && $inlineKeyboard !== [],
                        'has_photo' => false,
                    ]),
                ]);
            }

            return $ok;
        }

        return false;
    }

    /**
     * Jobs cannot carry raw image bytes — stash briefly in cache.
     *
     * @param  array{bytes: string, filename?: string, asset_id?: int|null}|null  $photo
     * @return array{cache_key: string, filename: string, asset_id: int|null}|null
     */
    private function serializePhotoForJob(?array $photo): ?array
    {
        $bytes = is_string($photo['bytes'] ?? null) ? (string) $photo['bytes'] : '';
        if ($bytes === '') {
            return null;
        }
        $key = 'tg:photo:'.bin2hex(random_bytes(16));
        \Illuminate\Support\Facades\Cache::put($key, $bytes, now()->addMinutes(15));

        return [
            'cache_key' => $key,
            'filename' => (string) ($photo['filename'] ?? 'post.jpg'),
            'asset_id' => isset($photo['asset_id']) ? (int) $photo['asset_id'] : null,
        ];
    }

    /**
     * @param  array{cache_key?: string, bytes?: string, filename?: string, asset_id?: int|null}|null  $photo
     * @return array{bytes: string, filename: string, asset_id: int|null}|null
     */
    public function hydratePhoto(?array $photo): ?array
    {
        if (! $photo) {
            return null;
        }
        if (is_string($photo['bytes'] ?? null) && $photo['bytes'] !== '') {
            return [
                'bytes' => (string) $photo['bytes'],
                'filename' => (string) ($photo['filename'] ?? 'post.jpg'),
                'asset_id' => isset($photo['asset_id']) ? (int) $photo['asset_id'] : null,
            ];
        }
        $key = (string) ($photo['cache_key'] ?? '');
        if ($key === '') {
            return null;
        }
        $bytes = \Illuminate\Support\Facades\Cache::pull($key);
        if (! is_string($bytes) || $bytes === '') {
            return null;
        }

        return [
            'bytes' => $bytes,
            'filename' => (string) ($photo['filename'] ?? 'post.jpg'),
            'asset_id' => isset($photo['asset_id']) ? (int) $photo['asset_id'] : null,
        ];
    }

    /**
     * @return list<list<array{text: string, callback_data: string}>>
     */
    public function pendingPostKeyboard(int $pendingActionId): array
    {
        return [[
            ['text' => '✅ Approve', 'callback_data' => 'tg:ok:'.$pendingActionId],
            ['text' => '❌ Deny', 'callback_data' => 'tg:no:'.$pendingActionId],
        ]];
    }
}
