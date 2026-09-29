<?php

namespace App\Services\Telegram;

use App\Models\AgentPendingAction;
use App\Models\AiCampaignSlot;
use App\Models\Business;
use App\Models\Conversation;
use App\Models\Order;
use App\Models\TelegramOutboundMessage;
use App\Support\TelegramHtml;
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
            $order->loadMissing(['items', 'customer']);
            $total = number_format((float) $order->total, 0, '.', ' ');
            $currency = $order->currency ?: 'DZD';
            $customer = trim((string) ($order->customer?->name ?: ''));
            $lines = [
                '🛒 '.TelegramHtml::bold('New order'),
                '📦 '.TelegramHtml::code((string) $order->order_number),
                '',
            ];
            if ($customer !== '') {
                $lines[] = '👤 '.$this->e($customer);
            }
            if ($order->phone) {
                $lines[] = '📞 '.$this->e((string) $order->phone);
            }
            $place = trim(implode(' · ', array_filter([
                $order->wilaya ? (string) $order->wilaya : null,
                $order->commune ? (string) $order->commune : null,
            ])));
            if ($place !== '') {
                $lines[] = '📍 '.$this->e($place);
            }
            if ($order->address) {
                $lines[] = '🏠 '.$this->e(mb_substr((string) $order->address, 0, 120));
            }
            if ($order->delivery_type) {
                $lines[] = '🚚 '.$this->e((string) $order->delivery_type);
            }

            $itemLines = [];
            foreach ($order->items as $item) {
                $name = trim((string) ($item->product_name ?: 'Item'));
                $qty = max(1, (int) $item->quantity);
                $itemLines[] = '• '.$this->e($name).' ×'.$qty;
            }
            if ($itemLines !== []) {
                $lines[] = '';
                $lines[] = TelegramHtml::bold('Items');
                $lines = array_merge($lines, array_slice($itemLines, 0, 8));
                if (count($itemLines) > 8) {
                    $lines[] = '… +'.(count($itemLines) - 8).' more';
                }
            }

            $lines[] = '';
            $lines[] = '💰 '.TelegramHtml::bold($total.' '.$currency);
            if ($order->delivery_fee && (float) $order->delivery_fee > 0) {
                $fee = number_format((float) $order->delivery_fee, 0, '.', ' ');
                $lines[] = TelegramHtml::italic('incl. delivery '.$fee.' '.$currency);
            }

            $this->alerts->notify(
                $business,
                TelegramOutboundMessage::KIND_ORDER_CREATED,
                TelegramHtml::join($lines),
                $order,
            );
        });
    }

    public function aiNeedsHuman(Business $business, ?Conversation $conversation = null, ?string $reason = null): void
    {
        $this->safe(function () use ($business, $conversation, $reason) {
            $lines = [
                '🙋 '.TelegramHtml::bold('AI needs you'),
            ];
            if ($conversation) {
                $customer = trim((string) ($conversation->customer?->name ?: ''));
                $lines[] = '💬 Conversation '.TelegramHtml::code('#'.$conversation->id)
                    .($customer !== '' ? ' · '.$this->e($customer) : '');
                $platform = trim((string) ($conversation->platform ?: ''));
                if ($platform !== '') {
                    $lines[] = '📱 '.$this->e(ucfirst($platform));
                }
            }
            if ($reason) {
                $lines[] = '';
                $lines[] = '📝 '.$this->e(mb_substr($reason, 0, 220));
            }
            $lines[] = '';
            $lines[] = TelegramHtml::italic('Open Wasl inbox to take over.');

            $this->alerts->notify(
                $business,
                TelegramOutboundMessage::KIND_AI_NEEDS_HUMAN,
                TelegramHtml::join($lines),
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
            $emoji = match ($action->type) {
                AgentPendingAction::TYPE_AI_CAMPAIGN => '🚀',
                AgentPendingAction::TYPE_MCP => '🔌',
                default => '📝',
            };
            $summary = trim((string) ($action->summary ?: $label.' ready to confirm'));
            $text = TelegramHtml::join([
                $emoji.' '.TelegramHtml::bold($label.' needs confirmation'),
                '🆔 '.TelegramHtml::code('#'.$action->id),
                '',
                $this->e(mb_substr($summary, 0, 400)),
                '',
                '👇 Approve or deny below.',
            ]);
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
                TelegramHtml::join([
                    '✅ '.TelegramHtml::bold('Approved'),
                    '🆔 '.TelegramHtml::code('#'.$action->id),
                    '',
                    $this->e(mb_substr($summary, 0, 300)),
                ]),
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
                TelegramHtml::join([
                    '❌ '.TelegramHtml::bold('Denied'),
                    '🆔 '.TelegramHtml::code('#'.$action->id),
                    '',
                    $this->e(mb_substr($summary, 0, 300)),
                ]),
            );
        });
    }

    public function pendingActionFailed(AgentPendingAction $action, string $error): void
    {
        $this->safe(function () use ($action, $error) {
            $this->alerts->updateFor(
                $action,
                TelegramOutboundMessage::KIND_PENDING_POST,
                TelegramHtml::join([
                    '⚠️ '.TelegramHtml::bold('Failed'),
                    '🆔 '.TelegramHtml::code('#'.$action->id),
                    '',
                    $this->e(mb_substr($error, 0, 300)),
                ]),
            );
        });
    }

    public function campaignSlotScheduled(Business $business, AiCampaignSlot $slot): void
    {
        $this->safe(function () use ($business, $slot) {
            $isStory = $slot->slot_kind === AiCampaignSlot::KIND_STORY;
            $kindLabel = $isStory ? 'Story' : 'Post';
            $emoji = $isStory ? '📱' : '📣';
            $text = TelegramHtml::join([
                $emoji.' '.TelegramHtml::bold($kindLabel.' scheduled'),
                $slot->campaign_id ? '🚀 Campaign '.TelegramHtml::code('#'.$slot->campaign_id) : null,
                '🧩 Slot '.TelegramHtml::code('#'.$slot->id),
            ]);
            $this->alerts->notify($business, TelegramOutboundMessage::KIND_POST_SCHEDULED, $text, $slot);
        });
    }

    private function e(?string $value): string
    {
        return TelegramHtml::escape($value);
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
