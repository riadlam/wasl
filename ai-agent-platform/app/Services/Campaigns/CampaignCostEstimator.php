<?php

namespace App\Services\Campaigns;

use App\AI\ImageModels\ImageModelCatalog;
use App\AI\LlmModels\LlmModelCatalog;
use App\Models\AiCampaign;
use App\Models\Business;
use App\Models\SocialAccount;
use App\Services\Wallet\WalletService;

/**
 * Upper-bound DA estimate for a campaign: one caption call per slot and platform, plus one image
 * per slot and platform in ai_recent mode. Real charges use Fal usage and are usually lower.
 */
class CampaignCostEstimator
{
    public function __construct(
        private LlmModelCatalog $llm,
        private ImageModelCatalog $images,
        private WalletService $wallets,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated campaign payload
     * @return array{estimated_da: float, per_slot_da: float, slots: int, platforms: int, caption_da: float, image_da: float}
     */
    public function estimate(Business $business, array $data): array
    {
        $business->loadMissing('agentSettings');
        $slots = 0;
        foreach (is_array($data['days'] ?? null) ? $data['days'] : [] as $day) {
            $slots += max(0, (int) ($day['posts'] ?? 0)) + max(0, (int) ($day['stories'] ?? 0));
        }

        $platforms = SocialAccount::query()
            ->forBusiness($business->id)
            ->whereIn('id', array_map('intval', $data['channel_ids'] ?? []))
            ->pluck('platform')
            ->map(fn ($p) => strtolower((string) $p))
            ->unique()
            ->count();
        $platforms = max(1, $platforms);

        $captionDa = $this->captionDa($business);
        $imageDa = ($data['content_mode'] ?? null) === AiCampaign::MODE_AI_RECENT ? $this->imageDa($business) : 0.0;
        $perSlot = round(($captionDa + $imageDa) * $platforms, 2);

        return [
            'estimated_da' => round($perSlot * $slots, 2),
            'per_slot_da' => $perSlot,
            'slots' => $slots,
            'platforms' => $platforms,
            'caption_da' => $captionDa,
            'image_da' => $imageDa,
        ];
    }

    public function captionDa(Business $business): float
    {
        $model = $this->llm->resolve($business->agentSettings?->llm_model);

        return $this->wallets->usdToDa((float) $model['price_usd'], 'ceil');
    }

    public function imageDa(Business $business): float
    {
        $key = (string) ($business->agentSettings?->image_model ?: $this->images->defaultKey());
        if (! $this->images->isValid($key)) {
            $key = $this->images->defaultKey();
        }

        return (float) $this->images->get($key)['price_da'];
    }
}
