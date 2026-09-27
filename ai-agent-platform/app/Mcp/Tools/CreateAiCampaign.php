<?php

namespace App\Mcp\Tools;

use App\Mcp\McpContext;
use App\Models\AgentPendingAction;
use App\Models\Business;
use App\Services\Campaigns\CampaignPayloadValidator;
use Illuminate\Validation\ValidationException;

class CreateAiCampaign extends BaseTool
{
    public function __construct(private CampaignPayloadValidator $payloads) {}

    public function name(): string
    {
        return 'create_ai_campaign';
    }

    public function description(): string
    {
        return 'Queue an AI campaign (channels, up to 7 days, posts/stories/times, ai_recent or product_images). Does not launch until the user confirms the card. Use local channel ids from list_channels and existing agent asset ids for product images.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'Optional campaign name'],
                'channel_ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                    'description' => 'Local social account ids for this shop',
                ],
                'day_count' => ['type' => 'integer', 'description' => '1-7'],
                'days' => [
                    'type' => 'array',
                    'description' => 'Per day: day_index, posts, stories, times (HH:mm, one per post)',
                    'items' => ['type' => 'object'],
                ],
                'content_mode' => ['type' => 'string', 'enum' => ['ai_recent', 'product_images']],
                'focus_prompt' => ['type' => 'string', 'description' => 'Required for ai_recent, at least 10 characters'],
                'asset_ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                    'description' => 'Product image agent asset ids. Launch needs ceil(posts * 0.8).',
                ],
            ],
            'required' => ['channel_ids', 'day_count', 'days', 'content_mode'],
        ];
    }

    public function scopes(): array
    {
        return [McpContext::SURFACE_OWNER];
    }

    public function mutates(): bool
    {
        return true;
    }

    public function handle(Business $business, array $arguments, McpContext $context): array
    {
        try {
            $data = $this->payloads->validate($arguments, $business->id);
        } catch (ValidationException $e) {
            return ['error' => 'Validation failed', 'details' => $e->errors()];
        }

        AgentPendingAction::query()
            ->where('business_id', $business->id)
            ->where('status', AgentPendingAction::STATUS_PENDING)
            ->update(['status' => AgentPendingAction::STATUS_CANCELLED]);

        $name = trim((string) ($data['name'] ?? ''));
        $summary = $name !== ''
            ? 'Launch AI campaign "'.$name.'"'
            : 'Launch AI campaign ('.$data['content_mode'].', '.$data['day_count'].' days)';

        $action = AgentPendingAction::query()->create([
            'business_id' => $business->id,
            'user_id' => $context->userId,
            'type' => AgentPendingAction::TYPE_AI_CAMPAIGN,
            'payload' => $data,
            'status' => AgentPendingAction::STATUS_PENDING,
            'summary' => $summary,
        ]);

        try {
            app(\App\Services\Telegram\TelegramMerchantNotifier::class)->pendingActionCreated($action);
        } catch (\Throwable) {
        }

        return [
            'ok' => true,
            'pending' => true,
            'action_id' => $action->id,
            'ask_user' => 'Ask the user to confirm this campaign in chat. Do not say it already launched.',
        ];
    }
}
