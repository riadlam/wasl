<?php

namespace App\Http\Controllers\Api;

use App\AI\LlmModels\LlmModelCatalog;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\CustomerAiSetting;
use App\Support\CurrentBusiness;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CustomerAiSettingController extends Controller
{
    public function __construct(private LlmModelCatalog $llmModels) {}

    public function show(): JsonResponse
    {
        $business = CurrentBusiness::require();

        return response()->json($this->payload($business, CustomerAiSetting::forBusiness($business)));
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'llm_model' => ['nullable', 'string', Rule::in($this->llmModels->keys())],
            'language' => ['nullable', 'string', Rule::in(['Darija', 'French'])],
            'tone' => ['nullable', 'string', 'max:40'],
            'persona' => ['nullable', 'string', 'max:1000'],
            'response_length' => ['sometimes', 'string', Rule::in(CustomerAiSetting::RESPONSE_LENGTHS)],
            'allow_order_creation' => ['sometimes', 'boolean'],
            'max_reply_chars' => ['sometimes', 'integer', 'min:120', 'max:2000'],
            'emoji_policy' => ['sometimes', 'string', Rule::in(CustomerAiSetting::EMOJI_POLICIES)],
            'handoff_keywords' => ['nullable', 'array', 'max:30'],
            'handoff_keywords.*' => ['string', 'max:60'],
            'reply_comments' => ['sometimes', 'boolean'],
            'reply_dms' => ['sometimes', 'boolean'],
            'reply_reviews' => ['sometimes', 'boolean'],
        ]);

        $business = CurrentBusiness::require();
        $settings = CustomerAiSetting::forBusiness($business);
        $settings->fill(array_intersect_key($data, array_flip([
            'llm_model', 'language', 'tone', 'persona', 'response_length',
            'allow_order_creation', 'max_reply_chars', 'emoji_policy', 'handoff_keywords',
        ])))->save();

        $business->loadMissing(['agent', 'agentSettings']);
        $agentFields = array_filter([
            'auto_reply_comments' => $data['reply_comments'] ?? null,
            'auto_reply_dms' => $data['reply_dms'] ?? null,
        ], fn ($v) => $v !== null);
        if ($agentFields !== [] && $business->agent) {
            $business->agent->fill($agentFields)->save();
        }
        if ($business->agentSettings) {
            $legacy = array_filter([
                'auto_reply_comments' => $data['reply_comments'] ?? null,
                'auto_reply_dms' => $data['reply_dms'] ?? null,
                'auto_reply_reviews' => $data['reply_reviews'] ?? null,
                'allow_order_creation' => $data['allow_order_creation'] ?? null,
            ], fn ($v) => $v !== null);
            if ($legacy !== []) {
                $business->agentSettings->fill($legacy)->save();
            }
        }

        return response()->json($this->payload($business->fresh(['agent', 'agentSettings']), $settings->fresh()));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Business $business, CustomerAiSetting $settings): array
    {
        $business->loadMissing(['agent', 'agentSettings']);

        return [
            'settings' => [
                'llm_model' => $settings->llm_model,
                'language' => $settings->language,
                'tone' => $settings->tone,
                'persona' => $settings->persona,
                'response_length' => $settings->response_length,
                'allow_order_creation' => (bool) $settings->allow_order_creation,
                'max_reply_chars' => (int) $settings->max_reply_chars,
                'emoji_policy' => $settings->emoji_policy,
                'handoff_keywords' => $settings->handoffKeywords(),
                'reply_comments' => (bool) ($business->agent?->auto_reply_comments ?? false),
                'reply_dms' => (bool) ($business->agent?->auto_reply_dms ?? false),
                'reply_reviews' => (bool) ($business->agentSettings?->auto_reply_reviews ?? false),
            ],
            'inherits' => [
                'language' => $business->agentSettings?->language ?: $business->agent?->language,
                'tone' => $business->agentSettings?->tone ?: $business->agent?->tone,
                'llm_model' => 'default',
            ],
            'llm_models' => $this->llmModels->publicCatalog(),
        ];
    }
}
