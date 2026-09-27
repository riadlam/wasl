<?php

namespace App\AI\ImageModels;

use InvalidArgumentException;

class ImageModelCatalog
{
    public function defaultKey(): string
    {
        return (string) config('ai_image_models.default', 'gpt_image_2');
    }

    public function usdToDa(): int
    {
        return (int) config('billing.usd_to_da', 250);
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->all());
    }

    public function isValid(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        $models = config('ai_image_models.models', []);

        return is_array($models) ? $models : [];
    }

    /**
     * @return array{id: string, label: string, recommended: bool, price_da: int, endpoint: string, price_usd: float, options: array<string, mixed>}
     */
    public function get(string $key): array
    {
        $models = $this->all();
        if (! isset($models[$key]) || ! is_array($models[$key])) {
            throw new InvalidArgumentException('Unknown image model.');
        }

        $row = $models[$key];
        $usd = (float) ($row['price_usd'] ?? 0);

        return [
            'id' => $key,
            'label' => (string) ($row['label'] ?? $key),
            'recommended' => (bool) ($row['recommended'] ?? false),
            'price_usd' => $usd,
            'price_da' => $usd > 0 ? (int) ceil($usd * $this->usdToDa()) : 0,
            'endpoint' => (string) ($row['endpoint'] ?? ''),
            'options' => is_array($row['options'] ?? null) ? $row['options'] : [],
        ];
    }

    public function resolve(?string $key): array
    {
        $key = is_string($key) && $key !== '' ? $key : $this->defaultKey();
        if (! $this->isValid($key)) {
            $key = $this->defaultKey();
        }

        return $this->get($key);
    }

    /**
     * Public API shape — no endpoints or provider fields.
     *
     * @return list<array{id: string, label: string, recommended: bool, price_da: int}>
     */
    public function publicCatalog(): array
    {
        $out = [];
        foreach ($this->all() as $id => $row) {
            if (! is_array($row)) {
                continue;
            }
            $out[] = [
                'id' => (string) $id,
                'label' => (string) ($row['label'] ?? $id),
                'recommended' => (bool) ($row['recommended'] ?? false),
                'price_da' => $this->get((string) $id)['price_da'],
            ];
        }

        usort($out, function (array $a, array $b): int {
            if ($a['recommended'] !== $b['recommended']) {
                return $a['recommended'] ? -1 : 1;
            }

            return strcmp($a['label'], $b['label']);
        });

        return $out;
    }
}
