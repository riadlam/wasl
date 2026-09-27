<?php

namespace App\Http\Controllers\Api\Internal;

use App\AI\Agents\McpToolPolicy;
use App\AI\Agents\OwnerAgent;
use App\Http\Controllers\Controller;
use App\Mcp\McpContext;
use App\Models\AgentPendingAction;
use App\Models\Business;
use App\Support\CurrentBusiness;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InternalAiController extends Controller
{
    public function __construct(
        private McpToolPolicy $policy,
        private OwnerAgent $ownerAgent,
    ) {}

    public function invokeTool(Request $request): JsonResponse
    {
        $data = $request->validate([
            'surface' => ['required', 'string', 'in:owner,customer,campaign,campaign_brief,campaign_tease'],
            'tool' => ['required', 'string', 'max:120'],
            'arguments' => ['nullable', 'array'],
            'context' => ['nullable', 'array'],
        ]);

        $business = Business::query()->findOrFail((int) CurrentBusiness::id());
        $toolName = (string) $data['tool'];
        $arguments = is_array($data['arguments'] ?? null) ? $data['arguments'] : [];
        $context = is_array($data['context'] ?? null) ? $data['context'] : [];
        $userId = $request->header('X-User-Id');
        if ($userId) {
            $context['user_id'] = (int) $userId;
        }

        if ($toolName === 'prepare_social_post') {
            return response()->json($this->prepareSocialPost($business, $arguments, $context));
        }

        // SK may call SocialAPI list_posts; route to capped list_recent_posts (max 20).
        if ($toolName === 'list_posts') {
            $toolName = 'list_recent_posts';
        }
        if ($toolName === 'list_recent_posts' && isset($arguments['limit'])) {
            $arguments['limit'] = max(1, min(20, (int) $arguments['limit']));
        }

        $surface = match ($data['surface']) {
            'customer' => McpContext::SURFACE_CUSTOMER,
            'campaign', 'campaign_brief', 'campaign_tease' => McpContext::SURFACE_OWNER,
            default => McpContext::SURFACE_OWNER,
        };
        // Campaign brief/tease still need catalog reads; owner registry includes Wasl + SocialAPI.
        if (in_array($data['surface'], ['campaign', 'campaign_brief', 'campaign_tease'], true)
            && in_array($toolName, ['search_products', 'get_product', 'list_delivery_zones'], true)) {
            $surface = McpContext::SURFACE_CAMPAIGN;
        }

        // Customer DMs may read recent page posts (availability / live offers) — owner tool, read-only.
        if ($data['surface'] === 'customer' && $toolName === 'list_recent_posts') {
            $arguments['limit'] = max(1, min(5, (int) ($arguments['limit'] ?? 5)));
            $registry = $this->policy->for(McpContext::SURFACE_OWNER, $business);
            $tool = $registry->find($toolName);
            if (! $tool) {
                return response()->json([
                    'ok' => false,
                    'error' => 'unknown_tool',
                    'tool' => $toolName,
                ], 404);
            }

            return response()->json($tool->handle($business, $arguments, $context));
        }

        $registry = $this->policy->for($surface, $business);
        $tool = $registry->find($toolName);
        if (! $tool) {
            // Allow calling raw SocialAPI MCP names without sapi_ prefix from SK plugins.
            $tool = $registry->find('sapi_'.$toolName);
        }
        if (! $tool) {
            return response()->json([
                'ok' => false,
                'error' => 'unknown_tool',
                'tool' => $toolName,
            ], 404);
        }

        $result = $tool->handle($business, $arguments, $context);

        // Normalize SocialAPI pending shape for the sidecar.
        if (! empty($result['pending']) && ! empty($result['action_id'])) {
            $result['pending_action'] = $this->ownerAgent->openPending($business);
        }

        return response()->json($result);
    }

    public function createPendingAction(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string', 'max:40'],
            'summary' => ['nullable', 'string', 'max:500'],
            'payload' => ['required', 'array'],
        ]);

        $business = Business::query()->findOrFail((int) CurrentBusiness::id());
        AgentPendingAction::query()
            ->where('business_id', $business->id)
            ->where('status', AgentPendingAction::STATUS_PENDING)
            ->update(['status' => AgentPendingAction::STATUS_CANCELLED]);

        $action = AgentPendingAction::query()->create([
            'business_id' => $business->id,
            'user_id' => $request->header('X-User-Id') ? (int) $request->header('X-User-Id') : null,
            'type' => $data['type'],
            'payload' => $data['payload'],
            'status' => AgentPendingAction::STATUS_PENDING,
            'summary' => $data['summary'] ?? 'Pending AI action',
        ]);

        try {
            app(\App\Services\Telegram\TelegramMerchantNotifier::class)->pendingActionCreated($action);
        } catch (\Throwable) {
        }

        return response()->json([
            'ok' => true,
            'pending_action' => $this->ownerAgent->openPending($business),
            'action_id' => $action->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function prepareSocialPost(Business $business, array $arguments, array $context): array
    {
        $caption = trim((string) ($arguments['caption'] ?? ''));
        $accountId = (string) ($arguments['socialapi_account_id'] ?? '');
        if ($caption === '' || $accountId === '') {
            return ['ok' => false, 'error' => 'caption_and_socialapi_account_id_required'];
        }

        $mcpTool = (string) ($arguments['mcp_tool'] ?? 'create_post');
        $mcpTool = str_starts_with($mcpTool, 'sapi_') ? substr($mcpTool, 5) : $mcpTool;

        $mcpArguments = [
            'account_id' => $accountId,
            'text' => $caption,
            'caption' => $caption,
        ];
        if (! empty($arguments['schedule_at'])) {
            $mcpArguments['schedule_at'] = $arguments['schedule_at'];
            if ($mcpTool === 'create_post') {
                $mcpTool = 'schedule_post';
            }
        }
        if (! empty($arguments['asset_ids']) && is_array($arguments['asset_ids'])) {
            $mcpArguments['asset_ids'] = array_values(array_map('intval', $arguments['asset_ids']));
        }

        $registry = $this->policy->for(McpContext::SURFACE_OWNER, $business);
        $tool = $registry->find('sapi_'.$mcpTool);
        if ($tool) {
            $result = $tool->handle($business, $mcpArguments, $context);
            if (! empty($result['pending']) && ! empty($result['action_id'])) {
                $result['pending_action'] = $this->ownerAgent->openPending($business);
            }

            return $result;
        }

        // Fallback: create a generic MCP pending row when the dynamic tool is unavailable.
        AgentPendingAction::query()
            ->where('business_id', $business->id)
            ->where('status', AgentPendingAction::STATUS_PENDING)
            ->update(['status' => AgentPendingAction::STATUS_CANCELLED]);

        $action = AgentPendingAction::query()->create([
            'business_id' => $business->id,
            'user_id' => $context['user_id'] ?? null,
            'type' => AgentPendingAction::TYPE_MCP,
            'payload' => [
                'mcp_tool' => $mcpTool,
                'mcp_arguments' => $mcpArguments,
                'category' => 'posts',
            ],
            'status' => AgentPendingAction::STATUS_PENDING,
            'summary' => mb_substr($caption, 0, 120),
        ]);

        try {
            app(\App\Services\Telegram\TelegramMerchantNotifier::class)->pendingActionCreated($action);
        } catch (\Throwable) {
        }

        return [
            'ok' => true,
            'pending' => true,
            'action_id' => $action->id,
            'pending_action' => $this->ownerAgent->openPending($business),
            'ask_user' => 'Ask the user to confirm this action in chat. Do not say it already happened.',
        ];
    }
}
