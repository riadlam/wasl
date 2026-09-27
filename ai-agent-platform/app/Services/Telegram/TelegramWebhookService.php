<?php

namespace App\Services\Telegram;

use App\AI\Tools\Owner\CancelPendingAction;
use App\AI\Tools\Owner\ConfirmPendingAction;
use App\Models\AgentPendingAction;
use App\Models\AiCampaignSlot;
use App\Services\Campaigns\CampaignSlotApprovalService;
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
                $this->bot->sendMessage($chatId, 'Open Settings → Integrations in Wasl and tap Connect Telegram to get your link.');

                return;
            }

            $business = $this->links->bindFromStart($code, $chatId, $username);
            if (! $business) {
                $this->bot->sendMessage($chatId, 'This link expired or is invalid. Generate a new one from Wasl Settings → Integrations.');

                return;
            }

            $fromName = trim((string) (($message['from']['first_name'] ?? '').' '.($message['from']['last_name'] ?? '')));
            $shop = $business->name;
            $hello = $fromName !== '' ? "Welcome, {$fromName}!" : 'Welcome!';
            $this->bot->sendMessage(
                $chatId,
                "{$hello}\n\n"
                ."You are connected to {$shop} on Wasl.\n"
                ."Alerts for new orders, AI handoffs, and post approvals will land here.\n\n"
                .'Connection established — you can keep this chat open for shop alerts.',
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

        // Pin outbound to the exact message the owner tapped (avoids editing a stale id / sending a duplicate).
        $clickedMessageId = (string) ($callback['message']['message_id'] ?? '');
        if ($clickedMessageId !== '') {
            $this->slotApprovals->bindTelegramMessage($business, $slot, $chatId, $clickedMessageId);
        }

        // Answer immediately so Telegram stops the loading spinner; then lock buttons.
        $ack = match ($action) {
            'ok' => 'Accepting…',
            'no' => 'Cancelling…',
            're' => 'Regenerating…',
            default => 'Working…',
        };
        if ($callbackId !== '') {
            $this->bot->answerCallbackQuery($callbackId, $ack);
        }

        try {
            match ($action) {
                'ok' => $this->slotApprovals->accept($slot),
                'no' => $this->slotApprovals->cancel($slot),
                're' => $this->slotApprovals->regenerate($slot),
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
                    "⚠️ Action failed\nSlot #{$slotId}\n\n".$e->getMessage()."\n\nTry again from the dashboard if needed.",
                );
            } catch (Throwable) {
            }
        }
    }
}
