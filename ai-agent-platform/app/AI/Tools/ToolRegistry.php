<?php

namespace App\AI\Tools;

class ToolRegistry
{
    /**
     * @param  list<AgentTool>  $tools
     */
    public function __construct(private array $tools) {}

    /**
     * @return list<AgentTool>
     */
    public function all(): array
    {
        return $this->tools;
    }

    public function find(string $name): ?AgentTool
    {
        foreach ($this->tools as $tool) {
            if ($tool->name() === $name) {
                return $tool;
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function openaiTools(): array
    {
        return array_map(fn (AgentTool $tool) => [
            'type' => 'function',
            'function' => [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'parameters' => $tool->parameters(),
            ],
        ], $this->tools);
    }
}
