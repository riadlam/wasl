<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SocialApi\SocialApiWebhookService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SocialApiWebhookController extends Controller
{
    public function __invoke(Request $request, SocialApiWebhookService $webhooks): Response
    {
        $raw = $request->getContent();
        $eventType = (string) ($request->header('X-SocialAPI-Event') ?: data_get(json_decode($raw, true), 'event', ''));
        $deliveryId = $request->header('X-SocialAPI-Delivery');

        if ($webhooks->isVerificationPing($eventType, $deliveryId)) {
            $payload = json_decode($raw, true) ?: [];
            $webhooks->store($eventType ?: 'webhook.test', $payload, $deliveryId, true);

            return response('ok', 200);
        }

        $signature = $request->header('X-SocialAPI-Signature');
        if (! $webhooks->signatureValid($raw, $signature)) {
            return response('Invalid signature', 401);
        }

        $payload = json_decode($raw, true) ?: [];
        $event = $webhooks->store($eventType, $payload, $deliveryId, true);
        $webhooks->dispatch($event);

        return response('ok', 200);
    }
}
