<?php

namespace App\Services\Telegram;

use App\Models\AgentPendingAction;
use App\Models\AiCampaignSlot;
use App\Models\Business;
use App\Models\Conversation;
use App\Models\Order;
use App\Models\TelegramOutboundMessage;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Domain emit helpers — fail soft so checkout / campaigns never break.
 */
class TelegramMerchantNotifier
{
    public function __construct(private TelegramAlertService $alerts) {}

    public function orderCreated(Business $business, Order $order): void
    {
        $this->safe(function () use ($business, $order) {
            $total = number_format((float) $order->total, 0, '.', ' ');
            $currency = $order->currency ?: 'DZD';
            $text = "🛒 New order {$order->order_number}\n"
                .($order->phone ? "Phone: {$order->phone}\n" : '')
                .($order->wilaya ? "Wilaya: {$order->wilaya}\n" : '')
                ."Total: {$total} {$currency}";
            $this->alerts->notify($business, TelegramOutboundMessage::KIND_ORDER_CREATED, trim($text), $order);
        });
    }

    public function aiNeedsHuman(Business $business, ?Conversation $conversation = null, ?string $reason = null): void
    {
        $this->safe(function () use ($business, $conversation, $reason) {
            $text = "🙋 AI needs you\n";
            if ($conversation) {
                $text .= 'Conversation #'.$conversation->id."\n";
            }
            if ($reason) {
                $text .= 'Reason: '.mb_substr($reason, 0, 200);
            }
            $this->alerts->notify(
                $business,
                TelegramOutboundMessage::KIND_AI_NEEDS_HUMAN,
                trim($text),
                $conversation,
            );
        });
    }

    public function pendingActionCreated(AgentPendingAction $action): void
    {
        $this->safe(function () use ($action) {
            if (! in_array($action->type, [
                AgentPendingAction::TYPE_CREATE_POST,
                AgentPendingAction::TYPE_AI_CAMPAIGN,
                AgentPendingAction::TYPE_MCP,
            ], true)) {
                return;
            }
            $business = $action->business;
            if (! $business) {
                return;
            }
            $label = match ($action->type) {
                AgentPendingAction::TYPE_AI_CAMPAIGN => 'Campaign',
                AgentPendingAction::TYPE_MCP => 'Social action',
                default => 'Post',
            };
            $summary = trim((string) ($action->summary ?: $label.' ready to confirm'));
            $text = "📝 {$label} needs confirmation #{$action->id}\n{$summary}\n\nApprove or deny below.";
            $this->alerts->notify(
                $business,
                TelegramOutboundMessage::KIND_PENDING_POST,
                $text,
                $action,
                $this->alerts->pendingPostKeyboard((int) $action->id),
            );
        });
    }

    public function pendingActionApproved(AgentPendingAction $action): void
    {
        $this->safe(function () use ($action) {
            $summary = trim((string) ($action->summary ?: $action->type));
            $this->alerts->updateFor(
                $action,
                TelegramOutboundMessage::KIND_PENDING_POST,
                "✅ Approved #{$action->id}\n{$summary}",
            );
        });
    }

    public function pendingActionDenied(AgentPendingAction $action): void
    {
        $this->safe(function () use ($action) {
            $summary = trim((string) ($action->summary ?: $action->type));
            $this->alerts->updateFor(
                $action,
                TelegramOutboundMessage::KIND_PENDING_POST,
                "❌ Denied #{$action->id}\n{$summary}",
            );
        });
    }

    public function pendingActionFailed(AgentPendingAction $action, string $error): void
    {
        $this->safe(function () use ($action, $error) {
            $this->alerts->updateFor(
                $action,
                TelegramOutboundMessage::KIND_PENDING_POST,
                "⚠️ Failed #{$action->id}\n".mb_substr($error, 0, 300),
            );
        });
    }

    public function campaignSlotScheduled(Business $business, AiCampaignSlot $slot): void
    {
        $this->safe(function () use ($business, $slot) {
            $text = '📣 Post scheduled'
                .($slot->campaign_id ? " (campaign #{$slot->campaign_id})" : '')
                ."\nSlot #{$slot->id}";
            $this->alerts->notify($business, TelegramOutboundMessage::KIND_POST_SCHEDULED, $text, $slot);
        });
    }

    private function safe(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            Log::warning('telegram.merchant_notify_failed', ['error' => $e->getMessage()]);
        }
    }
}
