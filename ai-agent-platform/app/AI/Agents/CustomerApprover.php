<?php

namespace App\AI\Agents;

use App\AI\Providers\FalLlmProvider;
use App\Models\Business;
use Throwable;

/**
 * Metacognition critic for CustomerAgent (client DM/comment replies).
 * Approves or rejects drafts before they are sent to the end customer.
 */
class CustomerApprover
{
    public function __construct(private FalLlmProvider $llm) {}

    /**
     * @param  list<string>  $evidence
     * @return array{
     *   decision: string,
     *   score: float,
     *   reasons: list<string>,
     *   feedback: string,
     *   usage: array{prompt_tokens: int, completion_tokens: int}
     * }
     */
    public function review(string $draftReply, string $customerText, array $evidence = [], ?string $model = null): array
    {
        $messages = [
            [
                'role' => 'system',
                'content' => 'You are CustomerApprover for a shop assistant. Approve or reject the draft reply '
                    .'before it is sent to the end customer. Return ONLY JSON: decision (approved|rejected), '
                    .'score (0..1), reasons (string[]), feedback (string). Reject invented prices/stock/fees.',
            ],
            [
                'role' => 'user',
                'content' => json_encode([
                    'customer_text' => $customerText,
                    'draft_reply' => $draftReply,
                    'evidence' => array_slice($evidence, 0, 16),
                ], JSON_UNESCAPED_UNICODE) ?: '{}',
            ],
        ];

        $options = ['temperature' => 0.0, 'json_object' => true, 'timeout' => 45];
        if ($model) {
            $options['model'] = $model;
        }

        try {
            $response = $this->llm->chat($messages, [], $options);
        } catch (Throwable $e) {
            report($e);

            return [
                'decision' => 'rejected',
                'score' => 0.0,
                'reasons' => ['approver_error'],
                'feedback' => 'Rewrite without invented prices or stock; stay grounded.',
                'usage' => ['prompt_tokens' => 0, 'completion_tokens' => 0],
            ];
        }

        $content = (string) ($response['choices'][0]['message']['content'] ?? '');
        $parsed = $this->parse($content);
        $usage = $response['usage'] ?? [];

        return [
            'decision' => $parsed['decision'],
            'score' => $parsed['score'],
            'reasons' => $parsed['reasons'],
            'feedback' => $parsed['feedback'],
            'usage' => [
                'prompt_tokens' => (int) ($usage['prompt_tokens'] ?? 0),
                'completion_tokens' => (int) ($usage['completion_tokens'] ?? 0),
            ],
        ];
    }

    /**
     * @param  list<string>  $evidence
     * @return array{reply: string, approved: bool, retried: bool, rounds: list<array<string, mixed>>, usage: array<string, int>}
     */
    public function reviewUntilApproved(
        string $draftReply,
        string $customerText,
        array $evidence,
        callable $revise,
        ?string $model = null,
        int $maxRounds = 2,
    ): array {
        $usage = ['prompt_tokens' => 0, 'completion_tokens' => 0, 'fal_calls' => 0];
        $rounds = [];
        $current = $draftReply;
        $last = [];

        for ($round = 1; $round <= max(1, $maxRounds); $round++) {
            $review = $this->review($current, $customerText, $evidence, $model);
            $usage['prompt_tokens'] += (int) ($review['usage']['prompt_tokens'] ?? 0);
            $usage['completion_tokens'] += (int) ($review['usage']['completion_tokens'] ?? 0);
            $usage['fal_calls']++;
            $last = $review;
            $rounds[] = [
                'round' => $round,
                'draft' => $current,
                'decision' => $review['decision'],
                'feedback' => $review['feedback'],
            ];

            if (($review['decision'] ?? '') === 'approved') {
                return [
                    'reply' => $current,
                    'approved' => true,
                    'retried' => $round > 1,
                    'rounds' => $rounds,
                    'usage' => $usage,
                ];
            }

            if ($round >= $maxRounds) {
                break;
            }

            $revised = $revise((string) ($review['feedback'] ?? ''), $current);
            $current = is_array($revised)
                ? (string) ($revised['reply'] ?? $current)
                : (string) ($revised ?: $current);
            if (is_array($revised) && isset($revised['usage'])) {
                $usage['prompt_tokens'] += (int) ($revised['usage']['prompt_tokens'] ?? 0);
                $usage['completion_tokens'] += (int) ($revised['usage']['completion_tokens'] ?? 0);
                $usage['fal_calls']++;
            }
        }

        return [
            'reply' => $current,
            'approved' => false,
            'retried' => true,
            'rounds' => $rounds,
            'usage' => $usage,
            'review' => $last,
        ];
    }

    /**
     * @return array{decision: string, score: float, reasons: list<string>, feedback: string}
     */
    public function parse(string $raw): array
    {
        $text = trim($raw);
        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```(?:json)?\s*/', '', $text) ?? $text;
            $text = preg_replace('/\s*```$/', '', $text) ?? $text;
        }
        $data = json_decode($text, true);
        if (! is_array($data) && preg_match('/\{[\s\S]*\}/', $text, $m)) {
            $data = json_decode($m[0], true);
        }
        if (! is_array($data)) {
            return [
                'decision' => 'rejected',
                'score' => 0.0,
                'reasons' => ['invalid_approver_json'],
                'feedback' => 'Rewrite without invented prices or stock.',
            ];
        }
        $decision = strtolower(trim((string) ($data['decision'] ?? 'rejected')));
        if (! in_array($decision, ['approved', 'rejected'], true)) {
            $decision = 'rejected';
        }
        $reasons = is_array($data['reasons'] ?? null) ? array_values(array_map('strval', $data['reasons'])) : [];

        return [
            'decision' => $decision,
            'score' => max(0.0, min(1.0, (float) ($data['score'] ?? 0))),
            'reasons' => array_slice($reasons, 0, 12),
            'feedback' => (string) ($data['feedback'] ?? ''),
        ];
    }
}
