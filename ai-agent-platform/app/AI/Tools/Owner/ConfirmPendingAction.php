<?php

namespace App\AI\Tools\Owner;

use App\AI\Tools\AgentTool;
use App\Models\AgentPendingAction;
use App\Models\Business;
use App\Services\Campaigns\AiCampaignService;
use App\Services\ScheduledPostService;
use App\Services\SocialApi\SocialApiMcpClient;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Throwable;

class ConfirmPendingAction implements AgentTool
{
    public function __construct(
        private ScheduledPostService $posts,
        private SocialApiMcpClient $mcp,
        private AiCampaignService $campaigns,
    ) {}

    public function name(): string
    {
        return 'confirm_pending_action';
    }

    public function description(): string
    {
        return 'Execute a pending action after the user explicitly confirmed. Requires action_id and confirmed=true. Runs pending SocialAPI MCP writes (and legacy create_post rows if any). This is the ONLY tool that finalizes a gated social action.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action_id' => [
                    'type' => 'integer',
                    'description' => 'Pending action id from the confirm card / MCP write tool',
                ],
                'confirmed' => [
                    'type' => 'boolean',
                    'description' => 'Must be true',
                ],
            ],
            'required' => ['action_id', 'confirmed'],
        ];
    }

    public function handle(Business $business, array $arguments, array $context = []): array
    {
        if (! ($arguments['confirmed'] ?? false)) {
            return ['error' => 'confirmed must be true. Do not call this until the user confirms.'];
        }

        $actionId = (int) ($arguments['action_id'] ?? 0);
        $action = AgentPendingAction::query()
            ->where('business_id', $business->id)
            ->where('id', $actionId)
            ->first();

        if (! $action) {
            return ['error' => 'Pending action not found.'];
        }
        if ($action->status !== AgentPendingAction::STATUS_PENDING) {
            return ['error' => 'Action is not pending (status: '.$action->status.').'];
        }

        try {
            $result = match ($action->type) {
                AgentPendingAction::TYPE_CREATE_POST => $this->executeCreatePost($action),
                AgentPendingAction::TYPE_MCP => $this->executeMcp($action),
                AgentPendingAction::TYPE_AI_CAMPAIGN => $this->executeCampaign($action),
                default => throw new \RuntimeException('Unsupported action type: '.$action->type),
            };
        } catch (ValidationException $e) {
            $action->update([
                'status' => AgentPendingAction::STATUS_FAILED,
                'result' => ['error' => $e->errors()],
            ]);
            try {
                app(\App\Services\Telegram\TelegramMerchantNotifier::class)->pendingActionFailed($action->fresh(), 'Validation failed');
            } catch (\Throwable) {
            }

            return ['error' => 'Validation failed', 'details' => $e->errors()];
        } catch (Throwable $e) {
            $action->update([
                'status' => AgentPendingAction::STATUS_FAILED,
                'result' => ['error' => $e->getMessage()],
            ]);
            try {
                app(\App\Services\Telegram\TelegramMerchantNotifier::class)->pendingActionFailed($action->fresh(), $e->getMessage());
            } catch (\Throwable) {
            }

            return ['error' => $e->getMessage()];
        }

        $action->update([
            'status' => AgentPendingAction::STATUS_CONFIRMED,
            'result' => $result,
        ]);

        try {
            app(\App\Services\Telegram\TelegramMerchantNotifier::class)->pendingActionApproved($action->fresh());
        } catch (\Throwable) {
        }

        return [
            'ok' => true,
            'action_id' => $action->id,
            'type' => $action->type,
            'result' => $result,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function executeMcp(AgentPendingAction $action): array
    {
        $payload = is_array($action->payload) ? $action->payload : [];
        $name = trim((string) ($payload['mcp_tool'] ?? ''));
        $args = is_array($payload['mcp_arguments'] ?? null) ? $payload['mcp_arguments'] : [];
        if ($name === '') {
            throw new \RuntimeException('Missing MCP tool name.');
        }

        return $this->mcp->callTool($name, $args);
    }

    /**
     * @return array<string, mixed>
     */
    private function executeCampaign(AgentPendingAction $action): array
    {
        $payload = is_array($action->payload) ? $action->payload : [];
        $business = $action->business;
        $user = $action->user;
        if (! $business || ! $user) {
            throw new \RuntimeException('Campaign confirm is missing the shop or user.');
        }
        try {
            $campaign = $this->campaigns->launch($business, $user, $payload);
        } catch (\App\Exceptions\InsufficientWalletException $e) {
            return [
                'ok' => false,
                'error' => $e->getMessage(),
                'code' => 'wallet.insufficient',
                'required_da' => $e->required,
                'available_da' => $e->available,
            ];
        }

        return ['ok' => true, 'campaign_id' => $campaign->id, 'estimated_da' => $campaign->estimated_da];
    }

    /**
     * @return array<string, mixed>
     */
    private function executeCreatePost(AgentPendingAction $action): array
    {
        $payload = is_array($action->payload) ? $action->payload : [];
        $publishNow = (bool) ($payload['publish_now'] ?? false);
        $scheduledAt = $payload['scheduled_at'] ?? null;

        if ($publishNow || ! $scheduledAt) {
            $scheduledAt = Carbon::now()->addMinute()->utc()->toIso8601String();
        }

        $created = $this->posts->create([
            'account_ids' => $payload['account_ids'] ?? [],
            'text' => (string) ($payload['text'] ?? ''),
            'media_ids' => $payload['media_ids'] ?? [],
            'scheduled_at' => $scheduledAt,
        ]);

        $published = null;
        if ($publishNow && ! empty($created['id'])) {
            $published = $this->posts->publish((string) $created['id']);
        }

        return [
            'post' => $created,
            'published' => $published,
            'publish_now' => $publishNow,
        ];
    }
}
