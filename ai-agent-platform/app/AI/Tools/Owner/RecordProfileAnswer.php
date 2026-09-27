<?php

namespace App\AI\Tools\Owner;

use App\AI\Tools\AgentTool;
use App\Models\Business;
use App\Services\ProfileInterviewService;

class RecordProfileAnswer implements AgentTool
{
    public function __construct(private ProfileInterviewService $interviews) {}

    public function name(): string
    {
        return 'record_profile_answer';
    }

    public function description(): string
    {
        return 'Save the owner answer for the CURRENT profile interview question only. question_index must match current_index from get_profile_interview_state. Do not call this for off-topic chat.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'question_index' => [
                    'type' => 'integer',
                    'description' => 'Zero-based index of the question being answered. Must equal the active current_index.',
                ],
                'answer' => [
                    'type' => 'string',
                    'description' => 'The owner answer, in their words.',
                ],
            ],
            'required' => ['question_index', 'answer'],
        ];
    }

    public function handle(Business $business, array $arguments, array $context = []): array
    {
        return $this->interviews->record(
            $business,
            (int) ($arguments['question_index'] ?? -1),
            (string) ($arguments['answer'] ?? ''),
        );
    }
}
