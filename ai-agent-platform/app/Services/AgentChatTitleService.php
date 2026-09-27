<?php

namespace App\Services;

use App\AI\LlmModels\LlmModelCatalog;
use App\AI\Providers\FalLlmProvider;
use App\AI\ReplyLanguage;
use App\Models\Business;
use Illuminate\Support\Facades\Log;
use Throwable;

class AgentChatTitleService
{
    public function __construct(
        private FalLlmProvider $llm,
        private LlmModelCatalog $llmModels,
    ) {}

    /**
     * @return array{title: ?string, usage: array{prompt_tokens: int, completion_tokens: int, cost_usd: float, fal_calls: int, calls_with_cost: int}}
     */
    public function generate(Business $business, string $userText, bool $hasAttachments = false): array
    {
        $emptyUsage = $this->usagePayload(0, 0, 0.0, 0, 0);
        $text = trim($userText);
        if ($text === '' || $text === '(attachment)') {
            $text = $hasAttachments ? 'attachment upload' : '';
        }
        if ($text === '') {
            return ['title' => null, 'usage' => $emptyUsage];
        }

        $business->loadMissing('agentSettings');
        $language = ReplyLanguage::forBusiness($business);
        $label = $language === ReplyLanguage::FRENCH ? 'French' : 'Algerian Darija';
        $scriptHint = $language === ReplyLanguage::FRENCH
            ? 'Use French in Latin script only.'
            : 'Use Algerian Darija in Arabic script only.';

        $userSnippet = mb_substr($text, 0, 500);
        $attachmentNote = $hasAttachments ? ' The user also attached a file.' : '';

        $system = <<<TEXT
You name shop-owner chat threads. Read the user's first message, infer their intent, and output ONE short title only.
Language: {$label}. {$scriptHint}
Rules: 3–6 words; no quotes; no punctuation at the end; no emoji; describe the task not the reply.
TEXT;

        $user = "First message: {$userSnippet}{$attachmentNote}";

        try {
            $model = $this->llmModels->resolve($business->agentSettings?->llm_model);
            $response = $this->llm->chat([
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ], [], [
                'model' => $model['model'],
                'temperature' => 0.3,
                'max_tokens' => 40,
                'timeout' => 25,
            ]);

            $falCalls = 1;
            $usage = is_array($response['usage'] ?? null) ? $response['usage'] : [];
            $promptTokens = (int) ($usage['prompt_tokens'] ?? 0);
            $completionTokens = (int) ($usage['completion_tokens'] ?? 0);
            $costUsd = 0.0;
            $callsWithCost = 0;
            if (isset($usage['cost']) && is_numeric($usage['cost']) && (float) $usage['cost'] > 0) {
                $costUsd = (float) $usage['cost'];
                $callsWithCost = 1;
            }

            $raw = trim((string) ($response['choices'][0]['message']['content'] ?? ''));
            $title = $this->sanitizeTitle($raw);

            return [
                'title' => $title !== '' ? $title : null,
                'usage' => $this->usagePayload($promptTokens, $completionTokens, $costUsd, $falCalls, $callsWithCost),
            ];
        } catch (Throwable $e) {
            Log::warning('agent_chat.title_failed', ['message' => $e->getMessage()]);

            return ['title' => null, 'usage' => $emptyUsage];
        }
    }

    public function sanitizeTitle(string $raw): string
    {
        $title = trim($raw);
        $title = trim($title, "\"'“”‘’`");
        $title = preg_replace('/[\r\n]+/u', ' ', $title) ?? $title;
        $title = preg_replace('/\s+/u', ' ', $title) ?? $title;

        return mb_substr(trim($title), 0, 40);
    }

    /**
     * @return array{prompt_tokens: int, completion_tokens: int, cost_usd: float, fal_calls: int, calls_with_cost: int}
     */
    private function usagePayload(int $promptTokens, int $completionTokens, float $costUsd, int $falCalls, int $callsWithCost): array
    {
        return [
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'cost_usd' => round($costUsd, 8),
            'fal_calls' => $falCalls,
            'calls_with_cost' => $callsWithCost,
        ];
    }
}
