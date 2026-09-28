<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiCampaign;
use App\Models\AiCampaignSlot;
use App\Services\Campaigns\AiCampaignService;
use App\Services\Campaigns\CampaignPayloadValidator;
use App\Support\CurrentBusiness;
use App\Support\MerchantSafeMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class AiCampaignController extends Controller
{
    public function __construct(
        private AiCampaignService $campaigns,
        private CampaignPayloadValidator $payloads,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $business = CurrentBusiness::require();
        $rows = AiCampaign::query()
            ->where('business_id', $business->id)
            ->with(['channels', 'slots'])
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (AiCampaign $c) => $c->toApiArray())
            ->values();

        return response()->json(['campaigns' => $rows]);
    }

    public function show(int $id): JsonResponse
    {
        $business = CurrentBusiness::require();

        return response()->json(['campaign' => $this->find($business->id, $id)->toApiArray(true)]);
    }

    public function estimate(Request $request): JsonResponse
    {
        $business = CurrentBusiness::require();
        $data = $this->payloads->validate($request->all(), $business->id, estimate: true);

        return response()->json(['estimate' => $this->campaigns->estimate($business, $data)]);
    }

    public function store(Request $request): JsonResponse
    {
        $business = CurrentBusiness::require();
        $data = $this->payloads->validate($request->all(), $business->id);
        $campaign = $this->campaigns->launch($business, $request->user(), $data);

        return response()->json(['campaign' => $campaign->toApiArray(true)], 201);
    }

    public function example(Request $request): JsonResponse
    {
        // SK tease: identity + caption author–critic + image queue can exceed default 60s.
        set_time_limit(180);
        ini_set('max_execution_time', '180');

        $business = CurrentBusiness::require();
        $data = $this->payloads->validate($request->all(), $business->id, example: true);
        $briefNotes = trim((string) $request->input('brief_notes', ''));
        if ($briefNotes === '') {
            $briefNotes = app(\App\Services\Campaigns\CampaignExampleBriefService::class)
                ->notesFromMessages(is_array($request->input('brief_messages')) ? $request->input('brief_messages') : []);
        }
        if ($briefNotes !== '') {
            $data['brief_notes'] = $briefNotes;
        }
        if (is_array($request->input('plan_meta'))) {
            $data['plan_meta'] = $request->input('plan_meta');
        }
        try {
            $preview = $this->campaigns->example($business, $request->user(), $data);
        } catch (RuntimeException $e) {
            \App\Services\Campaigns\CampaignTrace::error('campaigns.example.http_422', [
                'business_id' => $business->id,
                'message' => $e->getMessage(),
            ], $e);

            return response()->json([
                'message' => MerchantSafeMessage::of($e->getMessage(), 'Could not generate preview.'),
            ], 422);
        }

        return response()->json(MerchantSafeMessage::publicCampaignPreview(
            is_array($preview) ? $preview : []
        ));
    }

    public function exampleBrief(Request $request): JsonResponse
    {
        set_time_limit(180);
        ini_set('max_execution_time', '180');

        $business = CurrentBusiness::require();
        $data = $this->payloads->validate($request->all(), $business->id, example: true);
        $messages = is_array($request->input('messages')) ? $request->input('messages') : [];
        try {
            $turn = app(\App\Services\Campaigns\CampaignExampleBriefService::class)
                ->turn($business, $request->user(), $data, $messages);
        } catch (RuntimeException $e) {
            \App\Services\Campaigns\CampaignTrace::error('campaigns.brief.http_422', [
                'business_id' => $business->id,
                'message' => $e->getMessage(),
            ], $e);

            return response()->json([
                'message' => MerchantSafeMessage::of($e->getMessage(), 'Could not generate preview.'),
            ], 422);
        }

        return response()->json(MerchantSafeMessage::publicCampaignPreview(
            is_array($turn) ? $turn : []
        ));
    }

    public function cancel(int $id): JsonResponse
    {
        $business = CurrentBusiness::require();
        $campaign = $this->campaigns->cancel($this->find($business->id, $id));

        return response()->json(['campaign' => $campaign->toApiArray(true)]);
    }

    public function retrySlot(int $id, int $slotId): JsonResponse
    {
        $business = CurrentBusiness::require();
        $campaign = $this->find($business->id, $id);
        $slot = AiCampaignSlot::query()->where('ai_campaign_id', $campaign->id)->findOrFail($slotId);

        return response()->json(['campaign' => $this->campaigns->retrySlot($campaign, $slot)->toApiArray(true)]);
    }

    public function acceptSlot(int $id, int $slotId): JsonResponse
    {
        $business = CurrentBusiness::require();
        $campaign = $this->find($business->id, $id);
        $slot = AiCampaignSlot::query()->where('ai_campaign_id', $campaign->id)->findOrFail($slotId);
        app(\App\Services\Campaigns\CampaignSlotApprovalService::class)->accept($slot);

        return response()->json(['campaign' => $this->find($business->id, $id)->toApiArray(true)]);
    }

    public function editAcceptSlot(Request $request, int $id, int $slotId): JsonResponse
    {
        $business = CurrentBusiness::require();
        $campaign = $this->find($business->id, $id);
        $slot = AiCampaignSlot::query()->where('ai_campaign_id', $campaign->id)->findOrFail($slotId);

        $data = $request->validate([
            'caption' => ['required', 'string', 'max:2200'],
            'title' => ['nullable', 'string', 'max:160'],
            'agent_asset_id' => ['nullable', 'integer'],
        ]);

        if (! empty($data['agent_asset_id'])) {
            $owns = \App\Models\AgentAsset::query()
                ->where('business_id', $business->id)
                ->whereKey((int) $data['agent_asset_id'])
                ->exists();
            if (! $owns) {
                return response()->json(['message' => 'Image asset not found for this shop.'], 422);
            }
        }

        app(\App\Services\Campaigns\CampaignSlotApprovalService::class)->editAndAccept($slot, [
            'caption' => $data['caption'],
            'title' => $data['title'] ?? null,
            'agent_asset_id' => array_key_exists('agent_asset_id', $data) ? ($data['agent_asset_id'] ?? null) : $slot->agent_asset_id,
        ]);

        return response()->json(['campaign' => $this->find($business->id, $id)->toApiArray(true)]);
    }

    public function editSlot(Request $request, int $id, int $slotId): JsonResponse
    {
        $business = CurrentBusiness::require();
        $campaign = $this->find($business->id, $id);
        $slot = AiCampaignSlot::query()->where('ai_campaign_id', $campaign->id)->findOrFail($slotId);

        $data = $request->validate([
            'caption' => ['required', 'string', 'max:2200'],
            'title' => ['nullable', 'string', 'max:160'],
            'agent_asset_id' => ['nullable', 'integer'],
        ]);

        if (! empty($data['agent_asset_id'])) {
            $owns = \App\Models\AgentAsset::query()
                ->where('business_id', $business->id)
                ->whereKey((int) $data['agent_asset_id'])
                ->exists();
            if (! $owns) {
                return response()->json(['message' => 'Image asset not found for this shop.'], 422);
            }
        }

        app(\App\Services\Campaigns\CampaignSlotApprovalService::class)->editContent($slot, [
            'caption' => $data['caption'],
            'title' => $data['title'] ?? null,
            'agent_asset_id' => array_key_exists('agent_asset_id', $data) ? ($data['agent_asset_id'] ?? null) : $slot->agent_asset_id,
        ]);

        return response()->json(['campaign' => $this->find($business->id, $id)->toApiArray(true)]);
    }

    public function cancelSlot(int $id, int $slotId): JsonResponse
    {
        $business = CurrentBusiness::require();
        $this->find($business->id, $id);
        $slot = AiCampaignSlot::query()->where('ai_campaign_id', $id)->findOrFail($slotId);
        app(\App\Services\Campaigns\CampaignSlotApprovalService::class)->cancel($slot);

        return response()->json(['campaign' => $this->find($business->id, $id)->toApiArray(true)]);
    }

    public function regenerateSlot(int $id, int $slotId): JsonResponse
    {
        $business = CurrentBusiness::require();
        $this->find($business->id, $id);
        $slot = AiCampaignSlot::query()->where('ai_campaign_id', $id)->findOrFail($slotId);
        app(\App\Services\Campaigns\CampaignSlotApprovalService::class)->regenerate($slot);

        return response()->json(['campaign' => $this->find($business->id, $id)->toApiArray(true)]);
    }

    private function find(int $businessId, int $id): AiCampaign
    {
        return AiCampaign::query()
            ->where('business_id', $businessId)
            ->with(['channels', 'slots.targets.socialAccount', 'slots.asset'])
            ->whereKey($id)
            ->firstOrFail();
    }
}
