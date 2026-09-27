<?php

namespace App\Mcp;

use App\Models\Business;
use App\Models\McpToolCall;
use Illuminate\Support\Facades\Log;
use Throwable;

class WaslMcpServer
{
    public const PROTOCOL_VERSION = '2025-03-26';

    public function __construct(private WaslMcpRegistry $registry) {}

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>|null  null for notifications
     */
    public function handle(Business $business, array $request, McpContext $context): ?array
    {
        $id = $request['id'] ?? null;
        $method = (string) ($request['method'] ?? '');
        $params = is_array($request['params'] ?? null) ? $request['params'] : [];

        if (($request['jsonrpc'] ?? null) !== '2.0' || $method === '') {
            return $this->error($id, -32600, 'Invalid request');
        }

        if (str_starts_with($method, 'notifications/')) {
            return null;
        }

        return match ($method) {
            'initialize' => $this->result($id, [
                'protocolVersion' => self::PROTOCOL_VERSION,
                'serverInfo' => ['name' => 'wasl', 'version' => '1.0.0'],
                'capabilities' => ['tools' => ['listChanged' => false]],
                'instructions' => 'Wasl shop data: catalog, delivery zones and wilayas, orders, customers and AI campaigns for '.$business->name.'. Read before you claim any price, stock or delivery fee.',
            ]),
            'ping' => $this->result($id, new \stdClass),
            'tools/list' => $this->result($id, ['tools' => $this->listTools($context)]),
            'tools/call' => $this->toolsCall($business, $id, $params, $context),
            default => $this->error($id, -32601, 'Method not found: '.$method),
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listTools(McpContext $context): array
    {
        return array_map(fn (WaslMcpTool $tool) => $this->registry->describe($tool), $this->registry->forContext($context));
    }

    /**
     * Runs one tool with scope checks, gates and logging. Never throws.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function callTool(Business $business, string $name, array $arguments, McpContext $context): array
    {
        $started = microtime(true);
        $tool = $this->registry->find($name);

        if (! $tool || ! $context->allows($tool)) {
            $result = ['error' => 'Unknown tool: '.$name, 'code' => 'tool.unknown'];
        } elseif (($blocked = $this->gate($business, $tool, $context)) !== null) {
            $result = ['error' => $blocked, 'code' => 'tool.disabled'];
        } else {
            try {
                $result = $tool->handle($business, $arguments, $context);
            } catch (Throwable $e) {
                report($e);
                $result = ['error' => 'Tool failed. Try again or continue without it.', 'code' => 'tool.failed', 'retryable' => true];
            }
        }

        $this->log($business, $name, $arguments, $result, $context, $started);

        return $result;
    }

    private function gate(Business $business, WaslMcpTool $tool, McpContext $context): ?string
    {
        if ($tool->name() === 'create_order' && ! CustomerAiPolicy::allowsOrders($business)) {
            return 'Order capture is turned off for this shop. Tell the client a team member will confirm the order.';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function toolsCall(Business $business, mixed $id, array $params, McpContext $context): array
    {
        $name = (string) ($params['name'] ?? '');
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
        if ($name === '') {
            return $this->error($id, -32602, 'Missing tool name');
        }
        $tool = $this->registry->find($name);
        if (! $tool || ! $context->allows($tool)) {
            return $this->error($id, -32602, 'Unknown tool: '.$name);
        }

        $result = $this->callTool($business, $name, $arguments, $context);

        return $this->result($id, [
            'content' => [['type' => 'text', 'text' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]],
            'structuredContent' => $result,
            'isError' => isset($result['error']),
        ]);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $result
     */
    private function log(Business $business, string $name, array $arguments, array $result, McpContext $context, float $started): void
    {
        try {
            McpToolCall::query()->create([
                'business_id' => $business->id,
                'surface' => $context->surface,
                'mcp_token_id' => $context->tokenId,
                'agent_run_id' => $context->agentRunId,
                'tool' => mb_substr($name, 0, 120),
                'arguments' => $arguments,
                'status' => isset($result['error']) ? 'error' : 'ok',
                'error' => isset($result['error']) ? mb_substr((string) $result['error'], 0, 500) : null,
                'duration_ms' => (int) ((microtime(true) - $started) * 1000),
            ]);
        } catch (Throwable $e) {
            Log::warning('wasl_mcp.log_failed', ['tool' => $name, 'error' => $e->getMessage()]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function result(mixed $id, mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /**
     * @return array<string, mixed>
     */
    private function error(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}
