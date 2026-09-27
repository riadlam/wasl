<?php

namespace App\Services\SocialApi;

use App\Jobs\Social\ProcessSocialWebhookJob;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Bus;

class SocialApiWebhookService
{
    public function isVerificationPing(?string $eventType, ?string $deliveryId): bool
    {
        return $eventType === 'webhook.test' || $deliveryId === null || $deliveryId === '';
    }

    public function signatureValid(string $rawBody, ?string $signatureHeader): bool
    {
        $secret = (string) config('services.socialapi.webhook_secret');
        if ($secret === '' || ! $signatureHeader) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $signatureHeader);
    }

    public function store(string $eventType, array $payload, ?string $deliveryId, bool $signatureValid, ?int $businessId = null): WebhookEvent
    {
        if ($deliveryId) {
            $existing = WebhookEvent::query()
                ->where('provider', 'socialapi')
                ->where('event_id', $deliveryId)
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        return WebhookEvent::query()->create([
            'business_id' => $businessId,
            'provider' => 'socialapi',
            'event_id' => $deliveryId ?: ('ping-'.uniqid()),
            'event_type' => $eventType,
            'payload' => $payload,
            'signature_valid' => $signatureValid,
        ]);
    }

    public function dispatch(WebhookEvent $event): void
    {
        if ($event->processed_at) {
            return;
        }

        Bus::dispatch(new ProcessSocialWebhookJob($event->id));
    }
}
