<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Workflow;
use App\Services\WorkflowService;
use App\Support\CurrentBusiness;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

class WorkflowController extends Controller
{
    public function __construct(private WorkflowService $workflows) {}

    public function templates(): JsonResponse
    {
        return response()->json(['templates' => $this->workflows->templates()]);
    }

    public function index(): JsonResponse
    {
        return response()->json(['workflows' => $this->workflows->list()]);
    }

    public function useTemplate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'template_key' => ['required', 'string', 'in:mark_lead,hot_lead,post_comment,dm_keyword'],
            'activate' => ['sometimes', 'boolean'],
            'name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'config' => ['nullable', 'array'],
            'config.trigger_field' => ['nullable', 'in:phone,wilaya,commune,email,name,custom'],
            'config.trigger_hint' => [
                'nullable',
                'string',
                'max:240',
                Rule::requiredIf(fn () => $request->input('config.trigger_field') === 'custom'),
            ],
            'config.require_clear_match' => ['sometimes', 'boolean'],
            'config.platform' => ['sometimes', 'nullable', 'string', 'max:40'],
            'config.platforms' => ['sometimes', 'array', 'max:12'],
            'config.platforms.*' => ['string', 'max:40'],
            'config.keywords' => ['sometimes', 'array', 'max:40'],
            'config.keywords.*' => ['string', 'max:80'],
            'config.steps' => ['sometimes', 'array'],
            'posts' => ['sometimes', 'array', 'max:50'],
            'posts.*.account_id' => ['required_with:posts', 'integer'],
            'posts.*.platform_post_id' => ['required_with:posts', 'string', 'max:191'],
        ]);

        try {
            $workflow = $this->workflows->useTemplate(
                $data['template_key'],
                $data['config'] ?? [],
                (bool) ($data['activate'] ?? false),
                null,
                $data['posts'] ?? [],
                $data['name'] ?? null,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['workflow' => $workflow]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $business = CurrentBusiness::require();
        $workflow = Workflow::query()->forBusiness($business->id)->with('posts')->findOrFail($id);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'status' => ['sometimes', 'in:draft,active,paused'],
            'config' => ['sometimes', 'array'],
            'config.trigger_field' => ['nullable', 'in:phone,wilaya,commune,email,name,custom'],
            'config.trigger_hint' => [
                'nullable',
                'string',
                'max:240',
                Rule::requiredIf(fn () => $request->input('config.trigger_field') === 'custom'),
            ],
            'config.require_clear_match' => ['sometimes', 'boolean'],
            'config.platform' => ['sometimes', 'nullable', 'string', 'max:40'],
            'config.platforms' => ['sometimes', 'array', 'max:12'],
            'config.platforms.*' => ['string', 'max:40'],
            'config.keywords' => ['sometimes', 'array', 'max:40'],
            'config.keywords.*' => ['string', 'max:80'],
            'config.steps' => ['sometimes', 'array'],
            'config.steps.*.type' => ['required_with:config.steps', 'in:public_reply,private_dm,dm_reply'],
            'config.steps.*.enabled' => ['sometimes', 'boolean'],
            'config.steps.*.mode' => ['sometimes', 'in:agent,fixed'],
            'config.steps.*.text' => ['nullable', 'string', 'max:2000'],
            'config.steps.*.image_path' => ['nullable', 'string', 'max:500'],
            'posts' => ['sometimes', 'array', 'max:50'],
            'posts.*.account_id' => ['required_with:posts', 'integer'],
            'posts.*.platform_post_id' => ['required_with:posts', 'string', 'max:191'],
        ]);

        try {
            return response()->json(['workflow' => $this->workflows->update($workflow, $data)]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
