<?php

namespace App\AI\Agents;

use App\AI\Providers\FalLlmProvider;
use App\AI\Tools\ToolRegistry;
use App\Models\Business;
use Throwable;

/**
 * Shared tool-calling loop for the owner assistant, the customer AI and campaign creatives.
 * LLM transport errors propagate; tool errors come back to the model as structured results.
 */
class AgentLoop
{
    public const MAX_ITERATIONS = 8;

    public const MAX_RESULT_CHARS = 6000;

    public const TIME_BUDGET_SECONDS = 150;

    public const MAX_REPEATS = 2;

    public function __construct(private FalLlmProvider $llm) {}

    /**
     * @param  list<array<string, mixed>>  $messages
     * @param  array<string, mixed>  $context  passed to every tool
     * @param  array<string, mixed>  $llmOptions
     * @param  (callable(string, array<string, mixed>, array<string, mixed>, int): (array{stop?: bool, llm_result?: array<string, mixed>}|null))|null  $afterTool
     */
    public function run(
        Business $business,
        array $messages,
        ToolRegistry $tools,
        array $context = [],
        array $llmOptions = [],
        ?callable $afterTool = null,
        int $maxIterations = self::MAX_ITERATIONS,
    ): AgentLoopResult {
        $openaiTools = $tools->openaiTools();
        $usage = ['prompt_tokens' => 0, 'completion_tokens' => 0, 'cost_usd' => 0.0, 'fal_calls' => 0, 'calls_with_cost' => 0];
        $toolLog = [];
        $seen = [];
        $started = microtime(true);

        for ($i = 0; $i < $maxIterations; $i++) {
            $overBudget = (microtime(true) - $started) > self::TIME_BUDGET_SECONDS;
            $response = $this->chat($messages, $overBudget ? [] : $openaiTools, $llmOptions, $usage, $toolLog);

            $choice = $response['choices'][0]['message'] ?? [];
            $toolCalls = is_array($choice['tool_calls'] ?? null) ? $choice['tool_calls'] : [];

            if ($toolCalls === []) {
                return new AgentLoopResult(trim((string) ($choice['content'] ?? '')), $messages, $toolLog, $usage);
            }

            $messages[] = $choice;
            foreach ($toolCalls as $call) {
                $name = (string) ($call['function']['name'] ?? '');
                $rawArgs = $call['function']['arguments'] ?? '{}';
                $args = is_array($rawArgs) ? $rawArgs : json_decode((string) $rawArgs, true);
                $callStarted = microtime(true);

                if (! is_array($args)) {
                    $args = [];
                    $result = ['error' => 'Arguments were not valid JSON. Call the tool again with a JSON object.', 'code' => 'tool.bad_arguments'];
                } else {
                    $signature = $name.':'.json_encode($args);
                    $seen[$signature] = ($seen[$signature] ?? 0) + 1;
                    $result = $seen[$signature] > self::MAX_REPEATS
                        ? ['error' => 'You already called '.$name.' with these arguments. Use the earlier result.', 'code' => 'tool.repeat']
                        : $this->execute($tools, $business, $name, $args, $context);
                }

                $duration = (int) ((microtime(true) - $callStarted) * 1000);
                $toolLog[] = ['tool' => $name, 'arguments' => $args, 'result' => $result, 'duration_ms' => $duration];

                $hook = $afterTool ? ($afterTool($name, $args, $result, $duration) ?? []) : [];
                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $call['id'] ?? $name,
                    'content' => $this->encodeForLlm($hook['llm_result'] ?? $result),
                ];

                if (! empty($hook['stop'])) {
                    return new AgentLoopResult(null, $messages, $toolLog, $usage, stopped: true);
                }
            }
        }

        $messages[] = ['role' => 'user', 'content' => 'Stop calling tools. Answer now with what you already know.'];
        $response = $this->chat($messages, [], $llmOptions, $usage, $toolLog);
        $text = trim((string) ($response['choices'][0]['message']['content'] ?? ''));

        return new AgentLoopResult($text, $messages, $toolLog, $usage, exhausted: true);
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array<string, mixed>>  $tools
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  $usage
     * @param  list<array<string, mixed>>  $toolLog
     * @return array<string, mixed>
     */
    private function chat(array $messages, array $tools, array $options, array &$usage, array $toolLog): array
    {
        try {
            $response = $this->llm->chat($messages, $tools, $options);
        } catch (Throwable $e) {
            throw new AgentLoopException($e, $usage, $toolLog);
        }
        $this->addUsage($usage, $response);

        return $response;
    }

    /**
     * @param  array<string, mixed>  $args
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function execute(ToolRegistry $tools, Business $business, string $name, array $args, array $context): array
    {
        $tool = $tools->find($name);
        if (! $tool) {
            return ['error' => 'Unknown tool: '.$name, 'code' => 'tool.unknown'];
        }

        try {
            return $tool->handle($business, $args, $context);
        } catch (Throwable $e) {
            report($e);

            return ['error' => 'Tool failed: '.mb_substr($e->getMessage(), 0, 200), 'code' => 'tool.failed', 'retryable' => true];
        }
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public function encodeForLlm(array $result): string
    {
        $json = (string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (mb_strlen($json) <= self::MAX_RESULT_CHARS) {
            return $json;
        }

        return (string) json_encode([
            'truncated' => true,
            'note' => 'Result was too long. Narrow the query (limit, filters) if you need more.',
            'preview' => mb_substr($json, 0, self::MAX_RESULT_CHARS),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  array<string, mixed>  $usage
     * @param  array<string, mixed>  $response
     */
    private function addUsage(array &$usage, array $response): void
    {
        $usage['fal_calls']++;
        $raw = is_array($response['usage'] ?? null) ? $response['usage'] : [];
        $usage['prompt_tokens'] += (int) ($raw['prompt_tokens'] ?? 0);
        $usage['completion_tokens'] += (int) ($raw['completion_tokens'] ?? 0);
        if (isset($raw['cost']) && is_numeric($raw['cost']) && (float) $raw['cost'] > 0) {
            $usage['cost_usd'] += (float) $raw['cost'];
            $usage['calls_with_cost']++;
        }
    }
}
