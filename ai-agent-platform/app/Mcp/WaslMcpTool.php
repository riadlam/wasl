<?php

namespace App\Mcp;

use App\Models\Business;

interface WaslMcpTool
{
    /**
     * Must match ^[a-z0-9_]{1,64}$ so the same name works as an LLM function name.
     */
    public function name(): string;

    public function description(): string;

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array;

    /**
     * @return list<string>
     */
    public function scopes(): array;

    public function mutates(): bool;

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function handle(Business $business, array $arguments, McpContext $context): array;
}
