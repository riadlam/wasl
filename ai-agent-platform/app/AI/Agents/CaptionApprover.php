<?php

namespace App\AI\Agents;

use App\AI\Providers\FalLlmProvider;
use App\AI\Tools\Owner\GetBusinessContext;
use App\Models\Business;
use Throwable;

/**
 * Shop-brand caption critic (author–critic / AI-judge pattern).
 * Approves or rejects captions before they are shown to the shop owner.
 */
class CaptionApprover
{
    public const MAX_ROUNDS = 3;

    public function __construct(
        private FalLlmProvider $llm,
        private GetBusinessContext $businessContext,
    ) {}

    /**
     * @return array{
     *   decision: string,
     *   score: float,
     *   reasons: list<string>,
     *   feedback: string,
     *   usage: array{prompt_tokens: int, completion_tokens: int}
     * }
     */
    public function review(Business $business, string $caption, string $ownerBrief = '', ?string $model = null): array
    {
        $brand = $this->brandContext($business);
        $brief = trim($ownerBrief);
        // Campaign teases often have a solid owner brief before identity is fully trained —
        // only treat knowledge as insufficient when BOTH brand and brief are empty.
        $insufficient = trim($brand) === '' && $brief === '';

        $messages = [
            [
                'role' => 'system',
                'content' => 'You are CaptionApprover for a Maghreb shop SaaS. Judge ONE social caption against shop brand and owner brief. '
                    .'Return ONLY JSON keys: decision (approved|rejected), score (0..1), reasons (string[]), feedback (string). '
                    .'Reject invented prices/claims. If brand context and owner brief are both empty, reject with insufficient_shop_knowledge. '
                    .'If brand is thin but owner brief is clear, judge against the brief and approve when the caption is honest and on-brief.',
            ],
            [
                'role' => 'user',
                'content' => json_encode([
                    'caption' => $caption,
                    'owner_brief' => $brief !== '' ? $brief : '(empty)',
                    'shop_brand_context' => trim($brand) !== '' ? $brand : '(empty)',
                    'insufficient_shop_knowledge' => $insufficient,
                ], JSON_UNESCAPED_UNICODE) ?: '{}',
            ],
        ];

        $options = ['temperature' => 0.1, 'json_object' => true, 'timeout' => 60];
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
                'feedback' => 'Rewrite with a safer caption and no invented facts.',
                'usage' => ['prompt_tokens' => 0, 'completion_tokens' => 0],
            ];
        }

        $content = (string) ($response['choices'][0]['message']['content'] ?? '');
        $parsed = $this->parse($content);
        if ($insufficient && ($parsed['decision'] ?? '') === 'approved') {
            $parsed['decision'] = 'rejected';
            $parsed['score'] = 0.2;
            $parsed['reasons'] = ['insufficient_shop_knowledge'];
            $parsed['feedback'] = 'Shop brand knowledge is empty. Rewrite with a safe generic caption and no invented prices or claims.';
        }

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
     * Draft → review loop until approved or max rounds.
     *
     * @return array{
     *   caption: string,
     *   approved: bool,
     *   needs_owner_edit: bool,
     *   rounds: list<array<string, mixed>>,
     *   owner_message: string,
     *   usage: array{prompt_tokens: int, completion_tokens: int, fal_calls: int}
     * }
     */
    public function reviewLoop(Business $business, string $ownerBrief, callable $drafter, ?string $model = null): array
    {
        $usage = ['prompt_tokens' => 0, 'completion_tokens' => 0, 'fal_calls' => 0];
        $rounds = [];
        $caption = '';
        $feedback = null;
        $lastReview = [];

        for ($round = 1; $round <= self::MAX_ROUNDS; $round++) {
            $draft = $drafter($ownerBrief, $feedback, $caption !== '' ? $caption : null);
            $caption = trim((string) ($draft['caption'] ?? ''));
            $this->mergeUsage($usage, $draft['usage'] ?? []);

            $review = $this->review($business, $caption, $ownerBrief, $model);
            $this->mergeUsage($usage, $review['usage'] ?? []);
            $usage['fal_calls'] = (int) $usage['fal_calls'] + 1;
            $lastReview = $review;
            $rounds[] = [
                'round' => $round,
                'caption' => $caption,
                'decision' => $review['decision'],
                'score' => $review['score'],
                'reasons' => $review['reasons'],
                'feedback' => $review['feedback'],
            ];

            if (($review['decision'] ?? '') === 'approved') {
                return [
                    'caption' => $caption,
                    'approved' => true,
                    'needs_owner_edit' => false,
                    'rounds' => $rounds,
                    'owner_message' => $this->ownerMessage($caption, true, false),
                    'usage' => $usage,
                ];
            }
            $feedback = (string) ($review['feedback'] ?: 'Improve brand fit; remove invented claims.');
        }

        return [
            'caption' => $caption,
            'approved' => false,
            'needs_owner_edit' => true,
            'rounds' => $rounds,
            'owner_message' => $this->ownerMessage($caption, false, true),
            'usage' => $usage,
            'review' => $lastReview,
        ];
    }

    public function looksLikePostIntent(string $text): bool
    {
        return (bool) preg_match(
            '/(post|caption|بوست|منشور|نشر|كابشن|légende|legende|publier|create\s+a\s+post|write\s+a\s+caption)/iu',
            $text,
        );
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
                'feedback' => 'Rewrite the caption more clearly for the shop brand.',
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

    private function brandContext(Business $business): string
    {
        try {
            $result = $this->businessContext->handle($business, ['include' => ['identity']], []);
            $json = json_encode($result, JSON_UNESCAPED_UNICODE);

            return is_string($json) ? mb_substr($json, 0, 4000) : '';
        } catch (Throwable $e) {
            report($e);

            return '';
        }
    }

    private function ownerMessage(string $caption, bool $approved, bool $needsEdit): string
    {
        if ($needsEdit) {
            return "هاذا مسودة الكابشن بعد مراجعة داخلية (مازال يحتاج تعديل منك):\n\n{$caption}\n\nقدر تعدّل النص، ولا قول نعم باش نكمّلو (صورة ولا نص فقط).";
        }
        if ($approved) {
            return "الكابشن واجد بعد مراجعة البراند:\n\n{$caption}\n\nتقبلو؟ إلا نعم، تحب نزيدو صورة ولا نص فقط؟";
        }

        return $caption;
    }

    /**
     * @param  array<string, mixed>  $total
     * @param  array<string, mixed>  $part
     */
    private function mergeUsage(array &$total, array $part): void
    {
        $total['prompt_tokens'] = (int) ($total['prompt_tokens'] ?? 0) + (int) ($part['prompt_tokens'] ?? 0);
        $total['completion_tokens'] = (int) ($total['completion_tokens'] ?? 0) + (int) ($part['completion_tokens'] ?? 0);
    }
}
