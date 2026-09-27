<?php

namespace App\AI\Tools;

use App\Mcp\McpContext;
use App\Mcp\WaslMcpServer;
use App\Mcp\WaslMcpTool;
use App\Models\Business;

/**
 * Exposes a Wasl MCP tool to the LLM loop under the same name, routed through the server
 * so scopes, gates and call logging apply everywhere.
 */
class WaslToolAdapter implements AgentTool
{
    public function __construct(
        private WaslMcpTool $tool,
        private string $surface,
        private WaslMcpServer $server,
    ) {}

    public function name(): string
    {
        return $this->tool->name();
    }

    public function description(): string
    {
        return $this->tool->description();
    }

    public function parameters(): array
    {
        return $this->tool->inputSchema();
    }

    public function handle(Business $business, array $arguments, array $context = []): array
    {
        return $this->server->callTool(
            $business,
            $this->tool->name(),
            $arguments,
            McpContext::fromAgentContext($this->surface, $context),
        );
    }
}
