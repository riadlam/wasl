<?php

namespace App\AI\Mcp;

use App\AI\Tools\Owner\SocialApiMcpTool;
use App\Services\SocialApi\SocialApiMcpClient;
use Illuminate\Support\Facades\Log;
use Throwable;

class SocialApiMcpToolRegistry
{
    public function __construct(
        private SocialApiMcpClient $client,
        private SocialApiMcpClassifier $classifier,
    ) {}

    /**
     * @return list<SocialApiMcpTool>
     */
    public function tools(): array
    {
        if (! $this->client->enabled()) {
            return [];
        }

        try {
            $listed = $this->client->listTools();
        } catch (Throwable $e) {
            Log::warning('socialapi.mcp.list_failed', ['message' => $e->getMessage()]);

            return [];
        }

        $tools = [];
        foreach ($listed as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '' || ! preg_match('/^[a-zA-Z0-9_\-]+$/', $name)) {
                continue;
            }
            $schema = is_array($row['inputSchema'] ?? null) ? $row['inputSchema'] : ['type' => 'object', 'properties' => []];
            $tools[] = new SocialApiMcpTool(
                $name,
                (string) ($row['description'] ?? $name),
                $schema,
                $this->client,
                $this->classifier,
            );
        }

        return $tools;
    }
}
