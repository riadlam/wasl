<?php

namespace App\Mcp\Tools;

use App\Mcp\McpContext;
use App\Models\AiCampaign;
use App\Models\Business;

class GetAiCampaign extends BaseTool
{
    public function name(): string
    {
        return 'get_ai_campaign';
    }

    public function description(): string
    {
        return 'Load one AI campaign including slots, per-channel targets and errors. Read-only. Requires campaign_id from list_ai_campaigns.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'campaign_id' => ['type' => 'integer', 'description' => 'AI campaign id'],
            ],
            'required' => ['campaign_id'],
        ];
    }

    public function scopes(): array
    {
        return [McpContext::SURFACE_OWNER, McpContext::SURFACE_CAMPAIGN, McpContext::SURFACE_EXTERNAL];
    }

    public function handle(Business $business, array $arguments, McpContext $context): array
    {
        $campaign = AiCampaign::query()
            ->where('business_id', $business->id)
            ->with(['channels', 'slots'])
            ->whereKey((int) ($arguments['campaign_id'] ?? 0))
            ->first();

        if (! $campaign) {
            return ['error' => 'Campaign not found for this shop.'];
        }

        return ['campaign' => $campaign->toApiArray(true)];
    }
}
