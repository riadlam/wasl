<?php

namespace App\Jobs\Knowledge;

use App\AI\Runtime\SkAgentClient;
use App\Models\AiTaskCharge;
use App\Models\Business;
use App\Models\Product;
use App\Services\Wallet\AiTaskBillingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class IngestBusinessKnowledgeJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $businessId,
        public string $namespace = 'brand',
        public ?string $sourceType = null,
        public ?string $sourceId = null,
    ) {
        $this->onQueue('ai');
    }

    public function handle(SkAgentClient $client, AiTaskBillingService $billing): void
    {
        $business = Business::query()->with(['agentSettings'])->find($this->businessId);
        if (! $business) {
            return;
        }

        try {
            app(\App\Services\Wallet\WalletService::class)->authorizeBusinessAi(
                $business,
                $billing->chatAuthorizeDa($business->agentSettings?->llm_model),
            );
        } catch (\App\Exceptions\InsufficientWalletException|\App\Exceptions\WalletOwnerMissingException $e) {
            Log::warning('Knowledge ingest skipped: wallet', [
                'business_id' => $business->id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        if ($this->namespace === 'products' || $this->sourceType === 'product_catalog') {
            $this->ingestProducts($business, $client, $billing);

            return;
        }

        $content = $this->brandBundle($business);
        if ($content === '') {
            return;
        }

        $result = $client->ingestKnowledge(
            $business,
            $this->namespace ?: 'brand',
            $this->sourceType ?: 'shop_profile',
            $this->sourceId ?: (string) $business->id,
            $content,
            ['ingested_at' => now()->toIso8601String()],
        );

        if (! ($result['ok'] ?? false)) {
            Log::warning('Knowledge ingest failed', [
                'business_id' => $business->id,
                'error' => $result['error'] ?? 'unknown',
            ]);

            return;
        }

        $this->chargeIngest($billing, $business, $result, [
            'namespace' => $this->namespace ?: 'brand',
            'source_type' => $this->sourceType ?: 'shop_profile',
        ]);
    }

    private function ingestProducts(Business $business, SkAgentClient $client, AiTaskBillingService $billing): void
    {
        Product::query()
            ->where('business_id', $business->id)
            ->orderBy('id')
            ->chunkById(50, function ($products) use ($business, $client, $billing): void {
                $merged = [
                    'prompt_tokens' => 0,
                    'completion_tokens' => 0,
                    'cost_usd' => 0.0,
                    'fal_calls' => 0,
                    'calls_with_cost' => 0,
                ];
                $ok = 0;
                foreach ($products as $product) {
                    $text = trim(implode("\n", array_filter([
                        'Product: '.$product->name,
                        $product->sku ? 'SKU: '.$product->sku : null,
                        $product->description ? 'Description: '.$product->description : null,
                        isset($product->price) ? 'Price: '.$product->price : null,
                    ])));
                    if ($text === '') {
                        continue;
                    }
                    $result = $client->ingestKnowledge(
                        $business,
                        'products',
                        'product',
                        (string) $product->id,
                        $text,
                        ['product_id' => $product->id],
                    );
                    if (! ($result['ok'] ?? false)) {
                        continue;
                    }
                    $ok++;
                    $merged = $billing->mergeUsage($merged, is_array($result['usage'] ?? null) ? $result['usage'] : []);
                }
                if ($ok > 0) {
                    $this->chargeIngest($billing, $business, ['usage' => $merged], [
                        'namespace' => 'products',
                        'source_type' => 'product_catalog',
                        'products_ingested' => $ok,
                    ]);
                }
            });
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $meta
     */
    private function chargeIngest(AiTaskBillingService $billing, Business $business, array $result, array $meta): void
    {
        try {
            $billing->chargeAiUsage(
                $business,
                null,
                AiTaskCharge::TYPE_AGENT_RAG,
                $business->agentSettings?->llm_model,
                is_array($result['usage'] ?? null) ? $result['usage'] : [],
                Business::class,
                $business->id,
                array_merge(['surface' => 'knowledge_ingest'], $meta),
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function brandBundle(Business $business): string
    {
        $parts = [
            'Shop: '.($business->name ?? ''),
            $business->description ? 'Description: '.$business->description : null,
        ];

        $settings = $business->agentSettings;
        if ($settings) {
            foreach (['system_prompt', 'tone', 'language', 'brand_voice', 'shop_language', 'reply_language'] as $field) {
                if (isset($settings->{$field}) && filled($settings->{$field})) {
                    $parts[] = $field.': '.$settings->{$field};
                }
            }
            if (is_array($settings->metadata ?? null)) {
                $parts[] = 'metadata: '.json_encode($settings->metadata, JSON_UNESCAPED_UNICODE);
            }
        }

        return trim(implode("\n", array_filter($parts)));
    }
}
