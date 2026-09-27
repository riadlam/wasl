<?php

namespace App\Services\Campaigns;

use App\Services\SocialApi\SocialApiMcpClient;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reads the live SocialAPI MCP tools/list so campaign calls use the real tool names and argument shapes.
 * When tools/list is unavailable, callers fall back to the REST-verified shapes.
 */
class SocialApiMcpSchema
{
    /**
     * @var array<string, array<string, mixed>>|null
     */
    private ?array $tools = null;

    public function __construct(private SocialApiMcpClient $client) {}

    /**
     * @return array<string, array<string, mixed>>
     */
    public function tools(): array
    {
        if ($this->tools !== null) {
            return $this->tools;
        }

        $this->tools = [];
        try {
            foreach ($this->client->listTools() as $row) {
                if (is_array($row) && ! empty($row['name'])) {
                    $this->tools[(string) $row['name']] = $row;
                }
            }
        } catch (Throwable $e) {
            Log::warning('campaigns.mcp_schema_unavailable', ['error' => $e->getMessage()]);
        }

        return $this->tools;
    }

    public function known(): bool
    {
        return $this->tools() !== [];
    }

    /**
     * Pick the first candidate that exists in tools/list; with no schema, the configured name.
     */
    public function resolveTool(string $key): ?string
    {
        $configured = (string) (config('socialapi_mcp.campaign_tools.'.$key) ?? '');
        $candidates = array_values(array_unique(array_filter(array_merge(
            [$configured],
            (array) config('socialapi_mcp.campaign_tool_candidates.'.$key, []),
        ))));

        if (! $this->known()) {
            return $candidates[0] ?? null;
        }

        foreach ($candidates as $name) {
            if (isset($this->tools()[$name])) {
                return $name;
            }
        }

        return null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function properties(string $tool): array
    {
        $schema = $this->tools()[$tool]['inputSchema'] ?? null;
        $props = is_array($schema) ? ($schema['properties'] ?? []) : [];

        return is_array($props) ? $props : [];
    }

    /**
     * @param  list<string>  $names
     */
    public function firstProperty(string $tool, array $names): ?string
    {
        $props = $this->properties($tool);
        foreach ($names as $name) {
            if (array_key_exists($name, $props)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function itemProperties(string $tool, string $arrayProperty): array
    {
        $prop = $this->properties($tool)[$arrayProperty] ?? [];
        $items = is_array($prop['items'] ?? null) ? $prop['items'] : [];
        $props = $items['properties'] ?? [];

        return is_array($props) ? $props : [];
    }

    /**
     * Find a property whose enum offers a story value, searching the top level, target items and platform_data.
     *
     * @return array{path: list<string>, value: string}|null
     */
    public function storyField(string $tool): ?array
    {
        $found = $this->findStoryEnum($this->properties($tool), []);
        if ($found) {
            return $found;
        }

        foreach (['targets', 'accounts'] as $arrayProp) {
            $found = $this->findStoryEnum($this->itemProperties($tool, $arrayProp), [$arrayProp, '*']);
            if ($found) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $props
     * @param  list<string>  $prefix
     * @return array{path: list<string>, value: string}|null
     */
    private function findStoryEnum(array $props, array $prefix, int $depth = 0): ?array
    {
        foreach ($props as $name => $prop) {
            if (! is_array($prop)) {
                continue;
            }
            foreach ((array) ($prop['enum'] ?? []) as $option) {
                if (is_string($option) && in_array(strtolower($option), ['story', 'stories'], true)) {
                    return ['path' => array_merge($prefix, [(string) $name]), 'value' => $option];
                }
            }
            if ($depth < 2 && is_array($prop['properties'] ?? null)) {
                $found = $this->findStoryEnum($prop['properties'], array_merge($prefix, [(string) $name]), $depth + 1);
                if ($found) {
                    return $found;
                }
            }
        }

        return null;
    }
}
