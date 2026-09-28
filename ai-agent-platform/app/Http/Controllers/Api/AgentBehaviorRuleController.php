<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AgentBehaviorRule;
use App\Support\CurrentBusiness;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AgentBehaviorRuleController extends Controller
{
    public function index(): JsonResponse
    {
        $business = CurrentBusiness::require();

        $rules = AgentBehaviorRule::query()
            ->forBusiness($business->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (AgentBehaviorRule $rule) => $rule->toApiArray())
            ->values();

        return response()->json(['rules' => $rules]);
    }

    public function store(Request $request): JsonResponse
    {
        $business = CurrentBusiness::require();

        $data = $request->validate([
            'polarity' => ['required', Rule::in([AgentBehaviorRule::POLARITY_SHOULD, AgentBehaviorRule::POLARITY_MUST_NOT])],
            'body' => ['required', 'string', 'max:1000'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65000'],
        ]);

        $maxSort = (int) AgentBehaviorRule::query()
            ->forBusiness($business->id)
            ->where('polarity', $data['polarity'])
            ->max('sort_order');

        $rule = AgentBehaviorRule::query()->create([
            'business_id' => $business->id,
            'polarity' => $data['polarity'],
            'body' => trim($data['body']),
            'sort_order' => $data['sort_order'] ?? ($maxSort + 1),
        ]);

        return response()->json(['rule' => $rule->toApiArray()], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $business = CurrentBusiness::require();

        $rule = AgentBehaviorRule::query()
            ->forBusiness($business->id)
            ->whereKey($id)
            ->firstOrFail();

        $data = $request->validate([
            'polarity' => ['sometimes', Rule::in([AgentBehaviorRule::POLARITY_SHOULD, AgentBehaviorRule::POLARITY_MUST_NOT])],
            'body' => ['sometimes', 'string', 'max:1000'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65000'],
        ]);

        if (isset($data['body'])) {
            $data['body'] = trim($data['body']);
        }

        $rule->update($data);

        return response()->json(['rule' => $rule->fresh()->toApiArray()]);
    }

    public function destroy(int $id): JsonResponse
    {
        $business = CurrentBusiness::require();

        $rule = AgentBehaviorRule::query()
            ->forBusiness($business->id)
            ->whereKey($id)
            ->firstOrFail();

        $rule->delete();

        return response()->json(['ok' => true]);
    }
}
