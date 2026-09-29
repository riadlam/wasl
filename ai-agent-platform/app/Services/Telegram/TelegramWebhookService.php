<?php

namespace App\Services\Telegram;

use App\AI\Tools\Owner\CancelPendingAction;
use App\AI\Tools\Owner\ConfirmPendingAction;
use App\Models\AgentPendingAction;
use App\Models\AiCampaignSlot;
use App\Services\Campaigns\CampaignSlotApprovalService;
use App\Support\TelegramHtml;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramWebhookService
{
    public function __construct(
        private TelegramBotClient $bot,
        private TelegramLinkService $links,
        private ConfirmPendingAction $confirm,
        private CancelPendingAction $cancel,
        private CampaignSlotApprovalService $slotApprovals,
    ) {}

    /**
     * @param  array<string, mixed>  $update
     */
    public function handle(array $update): void
    {
        if (isset($update['callback_query']) && is_array($update['callback_query'])) {
            $this->handleCallback($update['callback_query']);

            return;
        }

        $message = is_array($update['message'] ?? null) ? $update['message'] : null;
        if (! $message) {
            return;
        }

        $text = trim((string) ($message['text'] ?? ''));
        $chatId = (string) ($message['chat']['id'] ?? '');
        $username = isset($message['from']['username']) ? (string) $message['from']['username'] : null;
        if ($chatId === '' || $text === '') {
            return;
        }

        if (preg_match('/^\/start(?:\s+(.+))?$/u', $text, $m)) {
            $code = trim((string) ($m[1] ?? ''));
            if ($code === '') {
                $this->bot->sendMessage(
                    $chatId,
                    TelegramHtml::join([
                        '🔗 '.TelegramHtml::bold('Connect Telegram'),
                        '',
                        'Open '.TelegramHtml::bold('Settings → Integrations').' in Wasl and tap '.TelegramHtml::bold('Connect Telegram').' to get your personal link.',
                    ]),
                );

                return;
            }

            $business = $this->links->bindFromStart($code, $chatId, $username);
            if (! $business) {
                $this->bot->sendMessage(
                    $chatId,
                    TelegramHtml::join([
                        '⚠️ '.TelegramHtml::bold('Link expired'),
                        '',
                        'Generate a new one from Wasl '.TelegramHtml::bold('Settings → Integrations').'.',
                    ]),
                );

                return;
            }

            $fromName = trim((string) (($message['from']['first_name'] ?? '').' '.($message['from']['last_name'] ?? '')));
            $shop = $business->name;
            $hello = $fromName !== '' ? 'Welcome, '.$fromName.'!' : 'Welcome!';
            $this->bot->sendMessage(
                $chatId,
                TelegramHtml::join([
                    '🎉 '.TelegramHtml::bold($hello),
                    '',
                    '✅ Connected to '.TelegramHtml::bold($shop).' on Wasl.',
                    '',
                    'You’ll get alerts here for:',
                    '🛒 New orders',
                    '🙋 AI handoffs',
                    '📝 Post & story approvals',
                    '',
                    TelegramHtml::italic('Keep this chat open for shop alerts.'),
                ]),
            );
        }
    }

    /**
     * @param  array<string, mixed>  $callback
     */
    private function handleCallback(array $callback): void
    {
        $callbackId = (string) ($callback['id'] ?? '');
        $data = (string) ($callback['data'] ?? '');
        $chatId = (string) ($callback['message']['chat']['id'] ?? '');

        if (preg_match('/^tg:slot:(ok|no|re):(\d+)$/', $data, $m)) {
            $this->handleSlotCallback($callbackId, $chatId, $m[1], (int) $m[2], $callback);

            return;
        }

        if (! preg_match('/^tg:(ok|no):(\d+)$/', $data, $m)) {
            if ($callbackId !== '') {
                $this->bot->answerCallbackQuery($callbackId, 'Unknown action');
            }

            return;
        }

        $approve = $m[1] === 'ok';
        $actionId = (int) $m[2];
        $action = AgentPendingAction::query()->find($actionId);
        if (! $action || ! $action->business) {
            if ($callbackId !== '') {
                $this->bot->answerCallbackQuery($callbackId, 'Action not found');
            }

            return;
        }

        $settings = $this->links->settingsFor($action->business);
        if ((string) $settings->telegram_chat_id !== $chatId) {
            if ($callbackId !== '') {
                $this->bot->answerCallbackQuery($callbackId, 'Not authorized');
            }

            return;
        }

        try {
            if ($approve) {
                $result = $this->confirm->handle($action->business, [
                    'action_id' => $action->id,
                    'confirmed' => true,
                ], ['user_id' => $action->user_id]);
                $ok = empty($result['error']);
                if ($callbackId !== '') {
                    $this->bot->answerCallbackQuery($callbackId, $ok ? 'Approved' : 'Failed');
                }
            } else {
                $result = $this->cancel->handle($action->business, [
                    'action_id' => $action->id,
                ], ['user_id' => $action->user_id]);
                $ok = empty($result['error']);
                if ($callbackId !== '') {
                    $this->bot->answerCallbackQuery($callbackId, $ok ? 'Denied' : 'Failed');
                }
            }
        } catch (Throwable $e) {
            Log::warning('telegram.callback_failed', ['error' => $e->getMessage(), 'action_id' => $actionId]);
            if ($callbackId !== '') {
                $this->bot->answerCallbackQuery($callbackId, 'Error');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $callback
     */
    private function handleSlotCallback(string $callbackId, string $chatId, string $action, int $slotId, array $callback = []): void
    {
        $slot = AiCampaignSlot::query()->with('campaign.business')->find($slotId);
        $business = $slot?->campaign?->business;
        if (! $slot || ! $business) {
            if ($callbackId !== '') {
                $this->bot->answerCallbackQuery($callbackId, 'Slot not found');
            }

            return;
        }

        $settings = $this->links->settingsFor($business);
        if ((string) $settings->telegram_chat_id !== $chatId) {
            if ($callbackId !== '') {
                $this->bot->answerCallbackQuery($callbackId, 'Not authorized');
            }

            return;
        }

        $message = is_array($callback['message'] ?? null) ? $callback['message'] : [];
        $clickedMessageId = (string) ($message['message_id'] ?? '');
        $hasPhoto = isset($message['photo']) && is_array($message['photo']) && $message['photo'] !== [];

        if ($clickedMessageId !== '') {
            $this->slotApprovals->bindTelegramMessage($business, $slot, $chatId, $clickedMessageId, $hasPhoto);
        }

        // Reject spam / stale taps before any heavy work.
        $fresh = $slot->fresh();
        $status = (string) ($fresh?->status ?? '');
        $busyStatuses = [
            AiCampaignSlot::STATUS_PUBLISHING,
            AiCampaignSlot::STATUS_REGEN_REQUESTED,
            AiCampaignSlot::STATUS_GENERATING,
            AiCampaignSlot::STATUS_SCHEDULED,
            AiCampaignSlot::STATUS_CANCELLED,
            AiCampaignSlot::STATUS_FAILED,
        ];
        if ($fresh && in_array($status, $busyStatuses, true)) {
            if ($callbackId !== '') {
                $this->bot->answerCallbackQuery($callbackId, 'Already handled');
            }
            try {
                $this->slotApprovals->lockTelegramActions(
                    $fresh,
                    match ($status) {
                        AiCampaignSlot::STATUS_REGEN_REQUESTED, AiCampaignSlot::STATUS_GENERATING => TelegramHtml::join([
                            '⏳ '.TelegramHtml::bold('Still generating…'),
                            '🧩 Slot '.TelegramHtml::code('#'.$slotId),
                            '',
                            'Please wait — buttons stay locked.',
                        ]),
                        AiCampaignSlot::STATUS_PUBLISHING => TelegramHtml::join([
                            '✅ '.TelegramHtml::bold('Already accepting…'),
                            '🧩 Slot '.TelegramHtml::code('#'.$slotId),
                        ]),
                        AiCampaignSlot::STATUS_SCHEDULED => TelegramHtml::join([
                            '✅ '.TelegramHtml::bold('Already scheduled'),
                            '🧩 Slot '.TelegramHtml::code('#'.$slotId),
                        ]),
                        AiCampaignSlot::STATUS_CANCELLED => TelegramHtml::join([
                            '❌ '.TelegramHtml::bold('Already cancelled'),
                            '🧩 Slot '.TelegramHtml::code('#'.$slotId),
                        ]),
                        default => TelegramHtml::join([
                            '🧩 Slot '.TelegramHtml::code('#'.$slotId),
                            'Status: '.TelegramHtml::escape($status),
                        ]),
                    },
                );
            } catch (Throwable) {
            }

            return;
        }

        if ($action === 'ok' || $action === 're') {
            if ($status !== AiCampaignSlot::STATUS_AWAITING_APPROVAL) {
                if ($callbackId !== '') {
                    $this->bot->answerCallbackQuery($callbackId, 'Not awaiting approval');
                }

                return;
            }
        }

        $lock = \Illuminate\Support\Facades\Cache::lock('tg:slot:cb:'.$slotId, 45);
        if (! $lock->get()) {
            if ($callbackId !== '') {
                $this->bot->answerCallbackQuery($callbackId, 'Already working…');
            }

            return;
        }

        try {
            $ack = match ($action) {
                'ok' => 'Accepting…',
                'no' => 'Cancelling…',
                're' => 'Regenerating…',
                default => 'Working…',
            };
            if ($callbackId !== '') {
                $this->bot->answerCallbackQuery($callbackId, $ack);
            }

            // Strip buttons + status text BEFORE long accept/regen work.
            $shop = $business->name ?: 'Shop';
            $lockText = match ($action) {
                'ok' => "✅ Accepting…\nShop: {$shop}\nSlot #{$slotId}\n\nButtons locked — scheduling…",
                'no' => "❌ Cancelling…\nSlot #{$slotId}\n\nButtons locked.",
                're' => "⏳ Generating a stronger version…\nShop: {$shop}\nSlot #{$slotId}\n\nButtons locked — please wait.",
                default => "Working…\nSlot #{$slotId}",
            };
            try {
                $this->slotApprovals->lockTelegramActions($fresh ?? $slot, $lockText);
            } catch (Throwable $e) {
                Log::warning('telegram.slot_lock_failed', [
                    'slot_id' => $slotId,
                    'error' => $e->getMessage(),
                ]);
            }

            match ($action) {
                'ok' => $this->slotApprovals->accept($slot->fresh() ?? $slot),
                'no' => $this->slotApprovals->cancel($slot->fresh() ?? $slot),
                're' => $this->slotApprovals->regenerate($slot->fresh() ?? $slot),
                default => null,
            };
        } catch (Throwable $e) {
            Log::warning('telegram.slot_callback_failed', [
                'error' => $e->getMessage(),
                'slot_id' => $slotId,
                'action' => $action,
            ]);
            try {
                $this->slotApprovals->lockTelegramActions(
                    $slot->fresh() ?? $slot,
                    "⚠️ Action failed\nSlot #{$slotId}\n\nTry again from the dashboard if needed.",
                );
            } catch (Throwable) {
            }
        } finally {
            optional($lock)->release();
        }
    }
}
