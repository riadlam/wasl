<?php

namespace App\Mcp;

use App\Models\Business;

/**
 * Same contract as the HTTP endpoint, without the network hop.
 */
class WaslMcpInProcessClient
{
    public function __construct(
        private WaslMcpServer $server,
        private WaslMcpRegistry $registry,
    ) {}

    /**
     * @return list<WaslMcpTool>
     */
    public function tools(McpContext $context): array
    {
        return $this->registry->forContext($context);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function call(Business $business, string $name, array $arguments, McpContext $context): array
    {
        return $this->server->callTool($business, $name, $arguments, $context);
    }
}
