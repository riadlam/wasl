<?php

namespace App\AI\Agents;

final class AgentLoopResult
{
    /**
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array{tool: string, arguments: array<string, mixed>, result: array<string, mixed>, duration_ms: int}>  $toolLog
     * @param  array{prompt_tokens: int, completion_tokens: int, cost_usd: float, fal_calls: int, calls_with_cost: int}  $usage
     */
    public function __construct(
        public readonly ?string $text,
        public readonly array $messages,
        public readonly array $toolLog,
        public readonly array $usage,
        public readonly bool $stopped = false,
        public readonly bool $exhausted = false,
    ) {}
}
