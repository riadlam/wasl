<?php

namespace App\AI\Tools\Owner;

use App\AI\Mcp\SocialApiMcpClassifier;
use App\AI\Tools\AgentTool;
use App\Models\AgentPendingAction;
use App\Models\Business;
use App\Services\SocialApi\SocialApiMcpClient;
use Throwable;

class SocialApiMcpTool implements AgentTool
{
    /**
     * @param  array<string, mixed>  $schema
     */
    public function __construct(
        private string $toolName,
        private string $toolDescription,
        private array $schema,
        private SocialApiMcpClient $mcp,
        private SocialApiMcpClassifier $classifier,
    ) {}

    public function name(): string
    {
        return 'sapi_'.$this->toolName;
    }

    public function description(): string
    {
        return 'SocialAPI: '.$this->toolDescription.' Do not invent ids. Reads run now. Writes wait for chat confirm unless the shop enabled auto for that action.';
    }

    public function parameters(): array
    {
        $schema = $this->schema;
        if (! isset($schema['type'])) {
            $schema['type'] = 'object';
        }

        return $schema;
    }

    public function handle(Business $business, array $arguments, array $context = []): array
    {
        $class = $this->classifier->classify($this->toolName);
        if (! $class['mutate']) {
            try {
                $result = $this->mcp->callTool($this->toolName, $arguments);

                return ['ok' => true, 'result' => $this->trim($result)];
            } catch (Throwable $e) {
                return ['error' => $e->getMessage()];
            }
        }

        $business->loadMissing('agentSettings');
        $column = (string) ($class['setting'] ?? 'ai_auto_moderate');
        $auto = (bool) ($business->agentSettings?->{$column} ?? false);

        if ($auto) {
            try {
                $result = $this->mcp->callTool($this->toolName, $arguments);

                return ['ok' => true, 'executed' => true, 'result' => $this->trim($result)];
            } catch (Throwable $e) {
                return ['error' => $e->getMessage()];
            }
        }

        AgentPendingAction::query()
            ->where('business_id', $business->id)
            ->where('status', AgentPendingAction::STATUS_PENDING)
            ->update(['status' => AgentPendingAction::STATUS_CANCELLED]);

        $summary = $this->pendingSummary($this->toolName, $arguments);
        $action = AgentPendingAction::query()->create([
            'business_id' => $business->id,
            'user_id' => $context['user_id'] ?? null,
            'type' => AgentPendingAction::TYPE_MCP,
            'payload' => [
                'mcp_tool' => $this->toolName,
                'mcp_arguments' => $arguments,
                'category' => $class['category'],
            ],
            'status' => AgentPendingAction::STATUS_PENDING,
            'summary' => $summary,
        ]);

        if (($class['category'] ?? '') === 'posts') {
            try {
                app(\App\Services\Telegram\TelegramMerchantNotifier::class)->pendingActionCreated($action);
            } catch (\Throwable) {
            }
        }

        return [
            'ok' => true,
            'pending' => true,
            'action_id' => $action->id,
            'ask_user' => 'Ask the user to confirm this action in chat. Do not say it already happened.',
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function trim(array $result): array
    {
        $json = json_encode($result, JSON_UNESCAPED_UNICODE);
        if (! is_string($json) || strlen($json) <= 4000) {
            return $result;
        }

        return ['truncated' => true, 'preview' => mb_substr($json, 0, 4000)];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function pendingSummary(string $tool, array $arguments): string
    {
        $bits = [];
        foreach (['text', 'caption', 'message', 'body', 'content'] as $key) {
            if (! empty($arguments[$key]) && is_string($arguments[$key])) {
                $bits[] = '"'.$this->clip($arguments[$key], 80).'"';
                break;
            }
        }
        foreach (['account_id', 'post_id', 'conversation_id'] as $key) {
            if (isset($arguments[$key]) && (is_string($arguments[$key]) || is_numeric($arguments[$key]))) {
                $bits[] = $key.'='.$arguments[$key];
            }
        }

        $detail = $bits === [] ? '' : ' — '.implode(', ', $bits);

        return 'Confirm SocialAPI '.$tool.$detail;
    }

    private function clip(string $text, int $max): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);

        return mb_strlen($text) <= $max ? $text : mb_substr($text, 0, $max - 1).'…';
    }
}
