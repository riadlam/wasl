<?php

namespace App\Mcp\Tools;

use App\Mcp\McpContext;
use App\Models\AiCampaign;
use App\Models\Business;

class ListAiCampaigns extends BaseTool
{
    public function name(): string
    {
        return 'list_ai_campaigns';
    }

    public function description(): string
    {
        return 'List AI campaigns for this shop (status, mode, start date, progress). Read-only. Use when the owner asks what campaigns exist.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'limit' => ['type' => 'integer', 'description' => 'Max campaigns to return (1-25, default 10)'],
            ],
        ];
    }

    public function scopes(): array
    {
        return [McpContext::SURFACE_OWNER, McpContext::SURFACE_CAMPAIGN, McpContext::SURFACE_EXTERNAL];
    }

    public function handle(Business $business, array $arguments, McpContext $context): array
    {
        $limit = max(1, min(25, (int) ($arguments['limit'] ?? 10)));
        $rows = AiCampaign::query()
            ->where('business_id', $business->id)
            ->with(['channels', 'slots'])
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (AiCampaign $c) => $c->toApiArray())
            ->values()
            ->all();

        return ['campaigns' => $rows];
    }
}
