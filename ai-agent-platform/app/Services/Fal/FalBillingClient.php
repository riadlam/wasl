<?php

namespace App\Services\Fal;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Resolves actual Fal USD charges via Platform billing-events API.
 */
class FalBillingClient
{
    /**
     * Look up cost_total (USD) for a Fal request_id. Retries briefly — billing can lag completion.
     */
    public function costUsdForRequest(string $requestId, int $attempts = 4, int $sleepMs = 400): ?float
    {
        $requestId = trim($requestId);
        if ($requestId === '') {
            return null;
        }

        $key = (string) config('services.fal.key');
        if ($key === '') {
            return null;
        }

        $attempts = app()->environment('testing') ? 1 : $attempts;
        $sleepMs = app()->environment('testing') ? 0 : $sleepMs;

        for ($i = 0; $i < max(1, $attempts); $i++) {
            if ($i > 0) {
                usleep($sleepMs * 1000);
            }

            $cost = $this->fetchOnce($key, $requestId);
            if ($cost !== null) {
                return $cost;
            }
        }

        Log::warning('fal.billing.cost_missing', ['request_id' => $requestId]);

        return null;
    }

    private function fetchOnce(string $key, string $requestId): ?float
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Key '.$key,
                'Accept' => 'application/json',
            ])
                ->timeout(15)
                ->get('https://api.fal.ai/v1/models/billing-events', [
                    'request_id' => $requestId,
                    'limit' => 10,
                ]);

            if ($response->failed()) {
                Log::warning('fal.billing.request_failed', [
                    'request_id' => $requestId,
                    'status' => $response->status(),
                ]);

                return null;
            }

            $json = $response->json();
            $events = $json['events'] ?? $json['data'] ?? $json['items'] ?? null;
            if (! is_array($events)) {
                // Some responses nest under "billing_events"
                $events = $json['billing_events'] ?? [];
            }
            if (! is_array($events) || $events === []) {
                return null;
            }

            $total = 0.0;
            $found = false;
            foreach ($events as $event) {
                if (! is_array($event)) {
                    continue;
                }
                $rid = (string) ($event['request_id'] ?? '');
                if ($rid !== '' && $rid !== $requestId) {
                    continue;
                }
                if (isset($event['cost_total']) && is_numeric($event['cost_total'])) {
                    $total += (float) $event['cost_total'];
                    $found = true;
                } elseif (isset($event['cost_estimate_nano_usd']) && is_numeric($event['cost_estimate_nano_usd'])) {
                    $total += ((float) $event['cost_estimate_nano_usd']) / 1_000_000_000;
                    $found = true;
                }
            }

            return $found && $total > 0 ? round($total, 8) : null;
        } catch (Throwable $e) {
            Log::warning('fal.billing.exception', [
                'request_id' => $requestId,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
