<?php

namespace App\AI\Tools\Owner;

use App\AI\Tools\AgentTool;
use App\Models\AgentPendingAction;
use App\Models\Business;

class CancelPendingAction implements AgentTool
{
    public function name(): string
    {
        return 'cancel_pending_action';
    }

    public function description(): string
    {
        return 'Cancel a pending action the user no longer wants.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action_id' => [
                    'type' => 'integer',
                    'description' => 'Pending action id to cancel',
                ],
            ],
            'required' => ['action_id'],
        ];
    }

    public function handle(Business $business, array $arguments, array $context = []): array
    {
        $actionId = (int) ($arguments['action_id'] ?? 0);
        $action = AgentPendingAction::query()
            ->where('business_id', $business->id)
            ->where('id', $actionId)
            ->first();

        if (! $action) {
            return ['error' => 'Pending action not found.'];
        }
        if ($action->status !== AgentPendingAction::STATUS_PENDING) {
            return ['error' => 'Action is not pending (status: '.$action->status.').'];
        }

        $action->update(['status' => AgentPendingAction::STATUS_CANCELLED]);

        try {
            app(\App\Services\Telegram\TelegramMerchantNotifier::class)->pendingActionDenied($action->fresh());
        } catch (\Throwable) {
        }

        return [
            'ok' => true,
            'action_id' => $action->id,
            'status' => AgentPendingAction::STATUS_CANCELLED,
        ];
    }
}
