<?php

namespace App\Mcp\Tools;

use App\Mcp\McpContext;
use App\Models\Business;
use App\Models\Conversation;

class HandoffToHuman extends BaseTool
{
    public function name(): string
    {
        return 'handoff_to_human';
    }

    public function description(): string
    {
        return 'Stop the AI and transfer this conversation to a human. Use for refunds, complaints, or when you cannot help.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'reason' => ['type' => 'string'],
            ],
        ];
    }

    public function scopes(): array
    {
        return [McpContext::SURFACE_CUSTOMER];
    }

    public function mutates(): bool
    {
        return true;
    }

    public function handle(Business $business, array $arguments, McpContext $context): array
    {
        if ($context->conversationId) {
            Conversation::query()->whereKey($context->conversationId)->where('business_id', $business->id)->update([
                'ai_enabled' => false,
                'status' => 'human',
            ]);
        }

        $conversation = $context->conversationId
            ? Conversation::query()->whereKey($context->conversationId)->where('business_id', $business->id)->first()
            : null;
        try {
            app(\App\Services\Telegram\TelegramMerchantNotifier::class)->aiNeedsHuman(
                $business,
                $conversation,
                isset($arguments['reason']) ? (string) $arguments['reason'] : null,
            );
        } catch (\Throwable) {
        }

        return [
            'ok' => true,
            'handoff' => true,
            'reason' => $arguments['reason'] ?? 'Transferred to a human',
        ];
    }
}
