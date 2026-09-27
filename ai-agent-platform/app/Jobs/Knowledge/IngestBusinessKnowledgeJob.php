<?php

namespace App\Jobs\Knowledge;

use App\AI\Runtime\SkAgentClient;
use App\Models\Business;
use App\Models\Product;
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

    public function handle(SkAgentClient $client): void
    {
        $business = Business::query()->with(['agentSettings'])->find($this->businessId);
        if (! $business) {
            return;
        }

        if ($this->namespace === 'products' || $this->sourceType === 'product_catalog') {
            $this->ingestProducts($business, $client);

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
        }
    }

    private function ingestProducts(Business $business, SkAgentClient $client): void
    {
        Product::query()
            ->where('business_id', $business->id)
            ->orderBy('id')
            ->chunkById(50, function ($products) use ($business, $client): void {
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
                    $client->ingestKnowledge(
                        $business,
                        'products',
                        'product',
                        (string) $product->id,
                        $text,
                        ['product_id' => $product->id],
                    );
                }
            });
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
