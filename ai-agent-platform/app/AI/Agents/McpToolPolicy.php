<?php

namespace App\AI\Agents;

use App\AI\Mcp\SocialApiMcpToolRegistry;
use App\AI\Tools\Owner\CancelPendingAction;
use App\AI\Tools\Owner\ConfirmPendingAction;
use App\AI\Tools\Owner\GenerateImage;
use App\AI\Tools\Owner\GetAgentAutoSettings;
use App\AI\Tools\Owner\GetBusinessContext;
use App\AI\Tools\Owner\GetProfileInterviewState;
use App\AI\Tools\Owner\GetShopReplyLanguage;
use App\AI\Tools\Owner\ListChannels;
use App\AI\Tools\Owner\ListRecentPosts;
use App\AI\Tools\Owner\ListScheduledPosts;
use App\AI\Tools\Owner\RecordProfileAnswer;
use App\AI\Tools\Owner\UpdateAgentAutoSettings;
use App\AI\Tools\ToolRegistry;
use App\AI\Tools\WaslToolAdapter;
use App\Mcp\CustomerAiPolicy;
use App\Mcp\McpContext;
use App\Mcp\WaslMcpRegistry;
use App\Mcp\WaslMcpServer;
use App\Mcp\WaslMcpTool;
use App\Models\Business;
use InvalidArgumentException;

/**
 * Which tools each AI surface may call. The owner assistant and the customer AI never share a tool list.
 */
class McpToolPolicy
{
    public const OWNER_LOCAL = [
        ListChannels::class,
        ListRecentPosts::class,
        ListScheduledPosts::class,
        GetBusinessContext::class,
        GetShopReplyLanguage::class,
        GetAgentAutoSettings::class,
        UpdateAgentAutoSettings::class,
        GetProfileInterviewState::class,
        RecordProfileAnswer::class,
        GenerateImage::class,
        ConfirmPendingAction::class,
        CancelPendingAction::class,
    ];

    public function __construct(
        private WaslMcpRegistry $registry,
        private WaslMcpServer $server,
    ) {}

    public function for(string $surface, Business $business): ToolRegistry
    {
        return match ($surface) {
            McpContext::SURFACE_CUSTOMER => new ToolRegistry($this->customerTools($business)),
            McpContext::SURFACE_OWNER => new ToolRegistry(array_merge(
                array_map(fn (string $class) => app($class), self::OWNER_LOCAL),
                $this->wasl(McpContext::SURFACE_OWNER),
                app(SocialApiMcpToolRegistry::class)->tools(),
            )),
            McpContext::SURFACE_CAMPAIGN => new ToolRegistry(array_values(array_filter(
                $this->wasl(McpContext::SURFACE_CAMPAIGN),
                fn (WaslToolAdapter $t) => ! $this->registry->find($t->name())?->mutates(),
            ))),
            default => throw new InvalidArgumentException('Unknown AI surface: '.$surface),
        };
    }

    /**
     * @return list<WaslToolAdapter>
     */
    private function customerTools(Business $business): array
    {
        $tools = $this->wasl(McpContext::SURFACE_CUSTOMER);
        if (! CustomerAiPolicy::allowsOrders($business)) {
            $tools = array_values(array_filter($tools, fn (WaslToolAdapter $t) => $t->name() !== 'create_order'));
        }

        return $tools;
    }

    /**
     * @return list<WaslToolAdapter>
     */
    private function wasl(string $surface): array
    {
        return array_map(
            fn (WaslMcpTool $tool) => new WaslToolAdapter($tool, $surface, $this->server),
            $this->registry->forContext(new McpContext($surface, [$surface])),
        );
    }
}
