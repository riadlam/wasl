<?php

namespace App\Services;

use App\Exceptions\InsufficientWalletException;
use App\Models\AgentAsset;
use App\Models\AgentChatMessage;
use App\Models\AgentImageJob;
use App\Models\Business;
use App\Models\User;
use App\Services\Fal\FalBillingClient;
use App\Services\Wallet\AiTaskBillingService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class AgentImageJobService
{
    public function __construct(
        private AiTaskBillingService $billing,
        private FalBillingClient $falBilling,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function completeFromWebhook(array $payload): void
    {
        $requestId = $this->requestId($payload);
        if ($requestId === '') {
            return;
        }

        $job = AgentImageJob::query()->where('fal_request_id', $requestId)->first();
        if (! $job) {
            return;
        }

        if ($this->isFailure($payload)) {
            $this->markFailed($job, $this->errorText($payload));

            return;
        }

        $url = $this->imageUrl($payload);
        if ($url === '') {
            $this->markFailed($job, 'Image generation failed.');

            return;
        }

        $this->finish($job, $url, $this->contentType($payload));
    }

    public function linkToMessage(AgentImageJob $job, AgentChatMessage $message): void
    {
        $job->agent_chat_message_id = $message->id;
        $job->save();

        if ($job->status === AgentImageJob::STATUS_COMPLETED && $job->asset) {
            $this->attachAsset($job, $job->asset);
        }
    }

    public function reconcileQueued(Business $business): void
    {
        $jobs = AgentImageJob::query()
            ->forBusiness($business->id)
            ->where('status', AgentImageJob::STATUS_QUEUED)
            ->orderBy('id')
            ->limit(10)
            ->get();

        foreach ($jobs as $job) {
            try {
                $this->reconcileOne($job);
            } catch (Throwable $e) {
                Log::warning('fal.image.reconcile_failed', [
                    'job_id' => $job->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }

    private function reconcileOne(AgentImageJob $job): void
    {
        $key = (string) config('services.fal.key');
        if ($key === '' || ! $job->status_url) {
            return;
        }

        $status = Http::withHeaders(['Authorization' => 'Key '.$key])
            ->timeout(20)
            ->get($job->status_url);

        if ($status->failed()) {
            return;
        }

        $body = $status->json();
        $state = strtoupper((string) ($body['status'] ?? ''));
        if (in_array($state, ['FAILED', 'ERROR', 'CANCELLED'], true)) {
            $this->markFailed($job, $this->errorText(is_array($body) ? $body : []));

            return;
        }

        if (! in_array($state, ['COMPLETED', 'OK', 'SUCCESS'], true)) {
            return;
        }

        $resultUrl = $job->response_url ?: '';
        if ($resultUrl === '') {
            return;
        }

        $result = Http::withHeaders(['Authorization' => 'Key '.$key])
            ->timeout(30)
            ->get($resultUrl);

        if ($result->failed()) {
            return;
        }

        $json = $result->json();
        if (! is_array($json)) {
            return;
        }

        $url = $this->imageUrl($json);
        if ($url === '') {
            $this->markFailed($job, 'Image generation failed.');

            return;
        }

        $this->finish($job, $url, $this->contentType($json));
    }

    private function finish(AgentImageJob $job, string $url, string $contentType): void
    {
        $job->refresh();
        if ($job->status === AgentImageJob::STATUS_COMPLETED) {
            return;
        }

        $business = Business::query()->find($job->business_id);
        if (! $business) {
            $this->markFailed($job, 'Image generation failed.');

            return;
        }

        try {
            $asset = $this->storeAsset($business, $url, $contentType);
        } catch (Throwable $e) {
            $this->markFailed($job, 'Could not save generated image.');

            return;
        }

        $actor = $job->user_id ? User::query()->find($job->user_id) : null;

        // Prefer Fal Platform billing-events cost (may lag a bit after webhook).
        $falUsd = $this->falBilling->costUsdForRequest((string) $job->fal_request_id);
        if ($falUsd !== null && $falUsd > 0) {
            $job->cost_usd = $falUsd;
            $job->save();
        }

        try {
            $charge = $this->billing->chargeImageSuccess($business, $job->fresh(), $actor);
            if ($charge) {
                $job->cost_usd = $charge->cost_usd;
                $job->cost_da = (int) max(1, (int) round((float) $charge->cost_da));
                $job->price_da = $job->cost_da;
                $job->save();
            }
        } catch (InsufficientWalletException) {
            Storage::disk($asset->disk ?: 'public')->delete($asset->path);
            $asset->delete();
            $this->markFailed($job, 'Not enough wallet balance.');

            return;
        }

        $job->status = AgentImageJob::STATUS_COMPLETED;
        $job->asset_id = $asset->id;
        $job->error = null;
        $job->save();

        $this->attachAsset($job, $asset);
    }

    private function attachAsset(AgentImageJob $job, AgentAsset $asset): void
    {
        if (! $job->agent_chat_message_id) {
            return;
        }

        $message = AgentChatMessage::query()->find($job->agent_chat_message_id);
        if (! $message) {
            return;
        }

        $meta = is_array($message->meta) ? $message->meta : [];
        $assets = is_array($meta['assets'] ?? null) ? $meta['assets'] : [];
        $ids = is_array($meta['asset_ids'] ?? null) ? $meta['asset_ids'] : [];
        $row = [
            'id' => $asset->id,
            'url' => $asset->absoluteUrl(),
            'mime' => $asset->mime,
            'original_name' => $asset->original_name,
        ];
        $already = collect($assets)->contains(fn ($a) => is_array($a) && (int) ($a['id'] ?? 0) === $asset->id);
        if (! $already) {
            $assets[] = $row;
            $ids[] = $asset->id;
        }
        $jobs = is_array($meta['image_jobs'] ?? null) ? $meta['image_jobs'] : [];
        $jobs = array_map(function ($entry) use ($job) {
            if (! is_array($entry) || (int) ($entry['id'] ?? 0) !== $job->id) {
                return $entry;
            }

            return ['id' => $job->id, 'status' => AgentImageJob::STATUS_COMPLETED];
        }, $jobs);
        $meta['assets'] = array_values($assets);
        $meta['asset_ids'] = array_values(array_unique(array_map('intval', $ids)));
        $meta['image_jobs'] = $jobs;
        $message->meta = $meta;
        $message->save();
    }

    private function markFailed(AgentImageJob $job, string $error): void
    {
        $job->refresh();
        if ($job->status === AgentImageJob::STATUS_COMPLETED) {
            return;
        }

        $job->status = AgentImageJob::STATUS_FAILED;
        $job->error = mb_substr($error, 0, 500);
        $job->save();

        if (! $job->agent_chat_message_id) {
            return;
        }

        $message = AgentChatMessage::query()->find($job->agent_chat_message_id);
        if (! $message) {
            return;
        }

        $meta = is_array($message->meta) ? $message->meta : [];
        $jobs = is_array($meta['image_jobs'] ?? null) ? $meta['image_jobs'] : [];
        $meta['image_jobs'] = array_map(function ($entry) use ($job) {
            if (! is_array($entry) || (int) ($entry['id'] ?? 0) !== $job->id) {
                return $entry;
            }

            return ['id' => $job->id, 'status' => AgentImageJob::STATUS_FAILED];
        }, $jobs);
        $message->meta = $meta;
        $message->save();
    }

    private function storeAsset(Business $business, string $url, string $contentType): AgentAsset
    {
        $response = Http::timeout(60)->get($url);
        if ($response->failed() || ! is_string($response->body()) || $response->body() === '') {
            throw new \RuntimeException('Could not download generated image.');
        }

        $bytes = $response->body();
        $mime = strtolower(trim($contentType));
        if ($mime === '' || ! str_starts_with($mime, 'image/')) {
            $mime = 'image/jpeg';
        }
        $ext = match (true) {
            str_contains($mime, 'png') => 'png',
            str_contains($mime, 'webp') => 'webp',
            default => 'jpg',
        };

        $name = 'gen-'.now()->format('Ymd-His').'-'.Str::lower(Str::random(6)).'.'.$ext;
        $path = 'agent-assets/'.$business->id.'/'.$name;
        Storage::disk('public')->put($path, $bytes);

        return AgentAsset::query()->create([
            'business_id' => $business->id,
            'agent_id' => $business->agent?->id,
            'disk' => 'public',
            'path' => $path,
            'original_name' => $name,
            'mime' => $mime,
            'size' => strlen($bytes),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function requestId(array $payload): string
    {
        foreach (['request_id', 'requestId'] as $key) {
            if (is_string($payload[$key] ?? null) && trim($payload[$key]) !== '') {
                return trim($payload[$key]);
            }
        }

        $nested = $payload['payload'] ?? $payload['data'] ?? null;
        if (is_array($nested)) {
            return $this->requestId($nested);
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function isFailure(array $payload): bool
    {
        $status = strtoupper((string) ($payload['status'] ?? ''));

        return in_array($status, ['ERROR', 'FAILED', 'CANCELLED'], true);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function errorText(array $payload): string
    {
        $error = $payload['error'] ?? $payload['detail'] ?? null;
        if (is_string($error) && $error !== '') {
            return $error;
        }

        return 'Image generation failed.';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function imageUrl(array $payload): string
    {
        $candidates = [
            $payload['images'] ?? null,
            $payload['payload']['images'] ?? null,
            $payload['data']['images'] ?? null,
            $payload['output']['images'] ?? null,
        ];

        foreach ($candidates as $images) {
            if (! is_array($images) || ! isset($images[0]) || ! is_array($images[0])) {
                continue;
            }
            $url = $images[0]['url'] ?? null;
            if (is_string($url) && $url !== '') {
                return $url;
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function contentType(array $payload): string
    {
        $candidates = [
            $payload['images'][0]['content_type'] ?? null,
            $payload['payload']['images'][0]['content_type'] ?? null,
        ];
        foreach ($candidates as $type) {
            if (is_string($type) && str_starts_with(strtolower($type), 'image/')) {
                return $type;
            }
        }

        return 'image/jpeg';
    }
}
