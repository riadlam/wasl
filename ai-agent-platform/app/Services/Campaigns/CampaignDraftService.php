<?php

namespace App\Services\Campaigns;

use App\AI\LlmModels\LlmModelCatalog;
use App\AI\Providers\FalLlmProvider;
use App\AI\ReplyLanguage;
use App\Models\AgentAsset;
use App\Models\AiCampaignSlot;
use App\Models\Business;
use RuntimeException;

/**
 * Thin wrapper kept for callers that only need caption drafting.
 * Prefer CampaignCreativeService for full caption + image_prompt.
 */
class CampaignDraftService
{
    public function __construct(private CampaignCreativeService $creatives) {}

    /**
     * @return array{caption: string, hashtags: list<string>, image_prompt: string, usage: array<string, mixed>}
     */
    public function draft(
        Business $business,
        string $kind,
        string $focus,
        string $context,
        ?AgentAsset $asset = null,
        string $platform = 'facebook',
    ): array {
        return $this->creatives->draft($business, $kind, $platform, $focus, $context, $asset);
    }
}
