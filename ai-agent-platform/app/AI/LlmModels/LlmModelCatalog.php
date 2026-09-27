<?php

namespace App\AI\LlmModels;

use InvalidArgumentException;

class LlmModelCatalog
{
    public function defaultKey(): string
    {
        return (string) config('ai_llm_models.default', 'claude_sonnet');
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
        $models = config('ai_llm_models.models', []);

        return is_array($models) ? $models : [];
    }

    /**
     * @return array{id: string, label: string, recommended: bool, price_da: int, model: string, price_usd: float}
     */
    public function get(string $key): array
    {
        $models = $this->all();
        if (! isset($models[$key]) || ! is_array($models[$key])) {
            throw new InvalidArgumentException('Unknown chat model.');
        }

        $row = $models[$key];
        $usd = (float) ($row['price_usd'] ?? 0);
        $rate = (int) config('billing.usd_to_da', 250);

        return [
            'id' => $key,
            'label' => (string) ($row['label'] ?? $key),
            'recommended' => (bool) ($row['recommended'] ?? false),
            'price_usd' => $usd,
            'price_da' => $usd > 0 ? (int) ceil($usd * $rate) : 0,
            'model' => (string) ($row['model'] ?? ''),
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
     * @return list<array{id: string, label: string, recommended: bool, price_da: int}>
     */
    public function publicCatalog(): array
    {
        $rows = [];
        foreach ($this->keys() as $key) {
            $model = $this->get($key);
            $rows[] = [
                'id' => $model['id'],
                'label' => $model['label'],
                'recommended' => $model['recommended'],
                'price_da' => $model['price_da'],
            ];
        }

        usort($rows, fn ($a, $b) => ($b['recommended'] <=> $a['recommended']) ?: strcmp($a['label'], $b['label']));

        return $rows;
    }
}
