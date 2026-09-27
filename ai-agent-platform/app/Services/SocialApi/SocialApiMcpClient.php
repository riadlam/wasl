<?php

namespace App\Services\SocialApi;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class SocialApiMcpClient
{
    /**
     * @return list<array<string, mixed>>
     */
    public function listTools(): array
    {
        if (! $this->enabled()) {
            return [];
        }

        return Cache::remember('socialapi.mcp.tools', 3600, function () {
            $result = $this->rpc('tools/list', new \stdClass);
            $tools = $result['tools'] ?? [];

            return is_array($tools) ? $tools : [];
        });
    }

    /**
     * Drop the cached tools/list (call after SocialAPI renames tools).
     */
    public function forgetToolsCache(): void
    {
        Cache::forget('socialapi.mcp.tools');
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function callTool(string $name, array $arguments): array
    {
        $result = $this->rpc('tools/call', [
            'name' => $name,
            'arguments' => $arguments === [] ? new \stdClass : $arguments,
        ]);

        return is_array($result) ? $result : ['raw' => $result];
    }

    public function enabled(): bool
    {
        return (bool) config('services.socialapi.mcp_enabled')
            && (string) config('services.socialapi.key') !== ''
            && (string) config('services.socialapi.mcp_url') !== '';
    }

    /**
     * @param  array<string, mixed>|\stdClass  $params
     * @return array<string, mixed>
     */
    private function rpc(string $method, array|\stdClass $params): array
    {
        $key = (string) config('services.socialapi.key');
        $url = (string) config('services.socialapi.mcp_url');
        if ($key === '' || $url === '') {
            throw new RuntimeException('SocialAPI MCP is not configured.');
        }

        // SocialAPI MCP requires both Accept types and returns SSE (text/event-stream).
        $timeout = 45;
        if ($method === 'tools/call' && is_array($params) && ($params['name'] ?? '') === 'list_posts') {
            $timeout = 20;
        }

        $response = Http::withToken($key)
            ->withHeaders([
                'Accept' => 'application/json, text/event-stream',
                'MCP-Protocol-Version' => '2025-03-26',
            ])
            ->asJson()
            ->connectTimeout(5)
            ->timeout($timeout)
            ->post($url, [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => $method,
                'params' => $params,
            ]);

        if ($response->failed()) {
            $detail = trim(mb_substr($response->body(), 0, 300));
            Log::warning('socialapi.mcp.failed', [
                'method' => $method,
                'status' => $response->status(),
                'body' => $detail,
            ]);
            throw new RuntimeException(
                $detail !== ''
                    ? 'SocialAPI MCP request failed (HTTP '.$response->status().'): '.$detail
                    : 'SocialAPI MCP request failed.'
            );
        }

        $json = $this->decodeResponse($response->body(), $response->json());
        if (! is_array($json)) {
            throw new RuntimeException('SocialAPI MCP returned an empty response.');
        }
        if (isset($json['error'])) {
            $message = is_array($json['error']) ? (string) ($json['error']['message'] ?? 'MCP error') : 'MCP error';
            throw new RuntimeException($message);
        }

        $result = $json['result'] ?? [];

        return is_array($result) ? $result : [];
    }

    /**
     * MCP streamable HTTP returns `event: message\ndata: {json}` — or plain JSON.
     *
     * @param  array<string, mixed>|null  $parsed
     * @return array<string, mixed>|null
     */
    private function decodeResponse(string $body, ?array $parsed): ?array
    {
        if (is_array($parsed) && (isset($parsed['result']) || isset($parsed['error']) || isset($parsed['jsonrpc']))) {
            return $parsed;
        }

        if (preg_match_all('/^data:\s*(\{.*\})\s*$/m', $body, $matches)) {
            $last = end($matches[1]);
            $decoded = json_decode((string) $last, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : null;
    }
}
