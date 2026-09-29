<?php

namespace App\Services\Wallet;

use App\AI\ImageModels\ImageModelCatalog;
use App\AI\LlmModels\LlmModelCatalog;
use App\Models\AgentChatMessage;
use App\Models\AgentImageJob;
use App\Models\AiTaskCharge;
use App\Models\Business;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AiTaskBillingService
{
    public function __construct(
        private WalletService $wallets,
        private LlmModelCatalog $llm,
        private ImageModelCatalog $images,
    ) {}

    /**
     * Resolve chat USD to bill from Fal usage. Returns null when nothing billable (no Fal work).
     *
     * @param  array<string, mixed>  $usage
     * @return array{cost_usd: float, billed_from: string, da_mode: string, meta: array<string, mixed>}|null
     */
    public function resolveChatBill(?string $modelKey, array $usage): ?array
    {
        $model = $this->llm->resolve($modelKey);
        $falCalls = (int) ($usage['fal_calls'] ?? 0);
        $rawCost = isset($usage['cost_usd']) ? (float) $usage['cost_usd'] : 0.0;
        $promptTokens = (int) ($usage['prompt_tokens'] ?? 0);
        $completionTokens = (int) ($usage['completion_tokens'] ?? 0);
        $callsWithCost = (int) ($usage['calls_with_cost'] ?? ($rawCost > 0 ? $falCalls : 0));

        // No Fal HTTP completed → do not invent a catalog charge.
        if ($falCalls <= 0 && $rawCost <= 0) {
            return null;
        }

        $maxUsd = max(0.01, (float) config('billing.chat_max_cost_usd', 2.0));
        $catalogUsd = max(0.0, (float) $model['price_usd']);
        $meta = [
            'model_label' => $model['label'],
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'fal_calls' => $falCalls,
            'calls_with_cost' => $callsWithCost,
        ];

        if ($rawCost > 0) {
            $costUsd = $rawCost;
            $billedFrom = 'fal_usage_cost';
            $daMode = 'round';

            // Some Fal calls in the loop omitted cost — never invent full catalog on top;
            // keep Fal sum but flag for ops.
            if ($falCalls > 0 && $callsWithCost < $falCalls) {
                $meta['incomplete_fal_cost'] = true;
                $billedFrom = 'fal_usage_cost_partial';
            }

            if ($costUsd > $maxUsd) {
                Log::warning('billing.chat_cost_clamped', [
                    'raw_usd' => $costUsd,
                    'max_usd' => $maxUsd,
                    'model' => $model['id'],
                ]);
                $meta['clamped_from_usd'] = $costUsd;
                $costUsd = $maxUsd;
                $billedFrom = 'fal_usage_cost_clamped';
            }

            return [
                'cost_usd' => round($costUsd, 8),
                'billed_from' => $billedFrom,
                'da_mode' => $daMode,
                'meta' => $meta,
            ];
        }

        // Fal ran but omitted usage.cost — catalog estimate (ceil), never above catalog.
        if ($catalogUsd <= 0) {
            return null;
        }

        Log::warning('billing.chat_fal_cost_missing', [
            'model' => $model['id'],
            'fal_calls' => $falCalls,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
        ]);

        return [
            'cost_usd' => round($catalogUsd, 8),
            'billed_from' => 'catalog_estimate_missing_fal_cost',
            'da_mode' => 'ceil',
            'meta' => $meta,
        ];
    }

    /**
     * @param  array<string, mixed>  $usage
     */
    public function chargeChatTurn(
        Business $business,
        User $actor,
        ?string $modelKey,
        ?int $messageId = null,
        array $usage = [],
    ): ?AiTaskCharge {
        return $this->chargeAiUsage(
            $business,
            $actor,
            AiTaskCharge::TYPE_AGENT_CHAT,
            $modelKey,
            $usage,
            AgentChatMessage::class,
            $messageId,
        );
    }

    /**
     * Charge shop owner wallet from Fal/SK usage for any AI surface.
     * Returns null when usage has no Fal work (never invents a charge).
     *
     * @param  array<string, mixed>  $usage
     * @param  array<string, mixed>  $meta
     */
    public function chargeAiUsage(
        Business $business,
        ?User $actor,
        string $taskType,
        ?string $modelKey,
        array $usage = [],
        ?string $referenceType = null,
        ?int $referenceId = null,
        array $meta = [],
    ): ?AiTaskCharge {
        $bill = $this->resolveChatBill($modelKey, $usage);
        if ($bill === null) {
            return null;
        }

        $model = $this->llm->resolve($modelKey);

        return $this->record(
            $business,
            $actor,
            $taskType,
            (string) $model['id'],
            (string) $model['model'],
            $bill['cost_usd'],
            $referenceType ?? '',
            $referenceId,
            array_merge($bill['meta'], ['billed_from' => $bill['billed_from']], $meta),
            $bill['da_mode'],
        );
    }

    /**
     * Merge multiple Fal/SK usage payloads into one billable bag.
     *
     * @param  list<array<string, mixed>|null>  $parts
     * @return array{prompt_tokens: int, completion_tokens: int, cost_usd: float, fal_calls: int, calls_with_cost: int}
     */
    public function mergeUsage(array ...$parts): array
    {
        $out = [
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'cost_usd' => 0.0,
            'fal_calls' => 0,
            'calls_with_cost' => 0,
        ];

        foreach ($parts as $part) {
            if (! is_array($part)) {
                continue;
            }
            $out['prompt_tokens'] += (int) ($part['prompt_tokens'] ?? 0);
            $out['completion_tokens'] += (int) ($part['completion_tokens'] ?? 0);
            $out['cost_usd'] += (float) ($part['cost_usd'] ?? 0);
            $out['fal_calls'] += (int) ($part['fal_calls'] ?? 0);
            $out['calls_with_cost'] += (int) ($part['calls_with_cost'] ?? 0);
        }

        $out['cost_usd'] = round($out['cost_usd'], 8);

        return $out;
    }

    public function chargeImageSuccess(Business $business, AgentImageJob $job, ?User $actor = null): AiTaskCharge
    {
        $catalogUsd = (float) $this->images->resolve($job->model_key)['price_usd'];
        $falUsd = $job->cost_usd !== null ? (float) $job->cost_usd : 0.0;
        $maxUsd = max(0.01, (float) config('billing.image_max_cost_usd', 1.0));
        $meta = [
            'fal_request_id' => $job->fal_request_id,
            'image_size' => $job->image_size,
        ];

        if ($falUsd > 0) {
            $usd = $falUsd;
            $billedFrom = 'fal_billing_events';
            $daMode = 'round';
            if ($usd > $maxUsd) {
                Log::warning('billing.image_cost_clamped', [
                    'raw_usd' => $usd,
                    'max_usd' => $maxUsd,
                    'request_id' => $job->fal_request_id,
                ]);
                $meta['clamped_from_usd'] = $usd;
                $usd = $maxUsd;
                $billedFrom = 'fal_billing_events_clamped';
            }
        } else {
            $usd = $catalogUsd;
            $billedFrom = 'catalog_estimate_missing_fal_cost';
            $daMode = 'ceil';
            Log::warning('billing.image_fal_cost_missing', [
                'request_id' => $job->fal_request_id,
                'model' => $job->model_key,
            ]);
        }

        $meta['billed_from'] = $billedFrom;

        return $this->record(
            $business,
            $actor,
            AiTaskCharge::TYPE_AGENT_IMAGE,
            (string) $job->model_key,
            (string) ($job->endpoint ?: $this->images->resolve($job->model_key)['endpoint']),
            $usd,
            AgentImageJob::class,
            $job->id,
            $meta,
            $daMode,
        );
    }

    /**
     * Pre-flight DA required before starting a chat turn.
     */
    public function chatAuthorizeDa(?string $modelKey): float
    {
        $model = $this->llm->resolve($modelKey);
        $mult = max(1.0, (float) config('billing.chat_authorize_usd_multiplier', 5));

        return $this->wallets->usdToDa((float) $model['price_usd'] * $mult, 'ceil');
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function record(
        Business $business,
        ?User $actor,
        string $taskType,
        string $modelKey,
        string $providerModel,
        float $costUsd,
        string $referenceType,
        ?int $referenceId,
        array $meta,
        string $daMode = 'ceil',
    ): AiTaskCharge {
        return DB::transaction(function () use ($business, $actor, $taskType, $modelKey, $providerModel, $costUsd, $referenceType, $referenceId, $meta, $daMode) {
            $refType = $referenceType !== '' ? $referenceType : null;

            $ledger = $this->wallets->chargeFromUsd(
                $business,
                $costUsd,
                $taskType,
                $actor,
                $refType,
                $referenceId,
                $meta,
                $daMode,
            );

            $owner = $this->wallets->ownerForBusiness($business);

            return AiTaskCharge::query()->create([
                'business_id' => $business->id,
                'user_id' => $owner->id,
                'actor_user_id' => $actor?->id,
                'task_type' => $taskType,
                'model_key' => $modelKey,
                'provider_model' => $providerModel,
                'cost_usd' => round($costUsd, 8),
                'cost_da' => (float) $ledger->amount_da,
                'usd_to_da' => $this->wallets->usdToDaRate(),
                'status' => AiTaskCharge::STATUS_CHARGED,
                'reference_type' => $refType,
                'reference_id' => $referenceId,
                'wallet_ledger_id' => $ledger->id,
                'meta' => $meta,
            ]);
        });
    }
}
