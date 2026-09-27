<?php

namespace App\AI\Tools;

use App\Models\Business;

interface AgentTool
{
    public function name(): string;

    public function description(): string;

    /**
     * @return array<string, mixed>
     */
    public function parameters(): array;

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function handle(Business $business, array $arguments, array $context = []): array;
}
