<?php

namespace App\AI\Tools\Owner;

use App\AI\Tools\AgentTool;
use App\Models\Business;
use App\Services\ProfileInterviewService;

class GetProfileInterviewState implements AgentTool
{
    public function __construct(private ProfileInterviewService $interviews) {}

    public function name(): string
    {
        return 'get_profile_interview_state';
    }

    public function description(): string
    {
        return 'Read the active Complete profile interview: current question index, question text, and remaining count. Read-only.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => (object) [],
        ];
    }

    public function handle(Business $business, array $arguments, array $context = []): array
    {
        return $this->interviews->toolState($business);
    }
}
