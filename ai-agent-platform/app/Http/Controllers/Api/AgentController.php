<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\AI\ImageModels\ImageModelCatalog;
use App\AI\LlmModels\LlmModelCatalog;
use App\Models\AgentAsset;
use App\Models\AgentRun;
use App\Support\CurrentBusiness;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class AgentController extends Controller
{
    public function __construct(
        private ImageModelCatalog $imageModels,
        private LlmModelCatalog $llmModels,
    ) {}

    public function show(): JsonResponse
    {
        $business = CurrentBusiness::require();

        $assets = AgentAsset::query()
            ->forBusiness($business->id)
            ->latest()
            ->limit(40)
            ->get()
            ->map(fn (AgentAsset $asset) => $asset->toApiArray())
            ->values();

        $settings = $business->agentSettings;
        if ($settings && (! $settings->image_model || ! $this->imageModels->isValid((string) $settings->image_model))) {
            $settings->image_model = $this->imageModels->defaultKey();
        }
        if ($settings && (! $settings->llm_model || ! $this->llmModels->isValid((string) $settings->llm_model))) {
            $settings->llm_model = $this->llmModels->defaultKey();
        }

        return response()->json([
            'agent' => $business->agent,
            'settings' => $settings,
            'rules' => $business->agentRules()->orderBy('priority')->get(),
            'assets' => $assets,
        ]);
    }

    public function imageModels(): JsonResponse
    {
        CurrentBusiness::require();

        return response()->json([
            'models' => $this->imageModels->publicCatalog(),
            'default' => $this->imageModels->defaultKey(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'system_prompt' => ['nullable', 'string'],
            'language' => ['sometimes', 'string', Rule::in(['Darija', 'French'])],
            'tone' => ['nullable', 'string', 'max:40'],
            'ai_enabled' => ['sometimes', 'boolean'],
            'auto_reply_dms' => ['sometimes', 'boolean'],
            'auto_reply_whatsapp' => ['sometimes', 'boolean'],
            'auto_reply_comments' => ['sometimes', 'boolean'],
            'human_approval_required' => ['sometimes', 'boolean'],
            'image_model' => ['sometimes', 'string', Rule::in($this->imageModels->keys())],
            'llm_model' => ['sometimes', 'string', Rule::in($this->llmModels->keys())],
            'ai_auto_publish_posts' => ['sometimes', 'boolean'],
            'ai_auto_reply_comments' => ['sometimes', 'boolean'],
            'ai_auto_send_dms' => ['sometimes', 'boolean'],
            'ai_auto_reply_reviews' => ['sometimes', 'boolean'],
            'ai_auto_moderate' => ['sometimes', 'boolean'],
        ]);

        $business = CurrentBusiness::require();
        $agent = $business->agent;
        abort_unless($agent, 404);

        $agentFields = array_intersect_key($data, array_flip([
            'name', 'system_prompt', 'language', 'tone', 'ai_enabled',
            'auto_reply_dms', 'auto_reply_whatsapp', 'auto_reply_comments',
            'human_approval_required',
        ]));
        if ($agentFields !== []) {
            $agent->fill($agentFields)->save();
        }

        if ($settings = $business->agentSettings) {
            $settings->fill(array_intersect_key($data, array_flip([
                'language', 'tone', 'auto_reply_dms', 'auto_reply_comments', 'image_model', 'llm_model',
                'ai_auto_publish_posts', 'ai_auto_reply_comments', 'ai_auto_send_dms',
                'ai_auto_reply_reviews', 'ai_auto_moderate',
            ])))->save();
        }

        return response()->json(['agent' => $agent->fresh(), 'settings' => $business->agentSettings()->first()]);
    }

    public function llmModels(): JsonResponse
    {
        CurrentBusiness::require();

        return response()->json([
            'models' => $this->llmModels->publicCatalog(),
            'default' => $this->llmModels->defaultKey(),
        ]);
    }

    public function updateLlmModel(Request $request): JsonResponse
    {
        $data = $request->validate([
            'llm_model' => ['required', 'string', Rule::in($this->llmModels->keys())],
        ]);

        $business = CurrentBusiness::require();
        $settings = $business->agentSettings;
        abort_unless($settings, 404);

        $settings->llm_model = $data['llm_model'];
        $settings->save();

        return response()->json([
            'settings' => $settings->fresh(),
            'llm_model' => $settings->llm_model,
        ]);
    }

    public function updateImageModel(Request $request): JsonResponse
    {
        $data = $request->validate([
            'image_model' => ['required', 'string', Rule::in($this->imageModels->keys())],
        ]);

        $business = CurrentBusiness::require();
        $settings = $business->agentSettings;
        abort_unless($settings, 404);

        $settings->image_model = $data['image_model'];
        $settings->save();

        return response()->json([
            'settings' => $settings->fresh(),
            'image_model' => $settings->image_model,
        ]);
    }

    public function uploadAsset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:jpeg,jpg,png,webp,gif,pdf', 'max:10240'],
        ]);

        $business = CurrentBusiness::require();
        $file = $data['file'];
        $path = $file->store('agent-assets/'.$business->id, 'public');

        $asset = AgentAsset::query()->create([
            'business_id' => $business->id,
            'agent_id' => $business->agent?->id,
            'disk' => 'public',
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime' => $file->getClientMimeType() ?: $file->getMimeType(),
            'size' => $file->getSize() ?: 0,
        ]);

        return response()->json([
            'asset' => $asset->toApiArray(),
        ], 201);
    }

    public function destroyAsset(int $id): JsonResponse
    {
        $business = CurrentBusiness::require();
        $asset = AgentAsset::query()
            ->forBusiness($business->id)
            ->findOrFail($id);

        Storage::disk($asset->disk ?: 'public')->delete($asset->path);
        $asset->delete();

        return response()->json(['ok' => true]);
    }

    public function run(int $id): JsonResponse
    {
        $run = AgentRun::query()
            ->forBusiness(CurrentBusiness::require()->id)
            ->findOrFail($id);

        return response()->json([
            'run' => [
                'id' => $run->id,
                'status' => $run->status,
                'created_at' => optional($run->created_at)?->toIso8601String(),
                'updated_at' => optional($run->updated_at)?->toIso8601String(),
            ],
        ]);
    }
}
