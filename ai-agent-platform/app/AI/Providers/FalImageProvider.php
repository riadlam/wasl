<?php

namespace App\AI\Providers;

use App\AI\ImageModels\ImageModelCatalog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class FalImageProvider
{
    public const ALLOWED_SIZES = [
        'square_hd',
        'portrait_16_9',
        'landscape_16_9',
        'landscape_4_3',
    ];

    /** Models that need Fal Queue (slow) instead of sync fal.run. */
    private const QUEUE_MODELS = ['gpt_image_2', 'nano_banana_2'];

    public function __construct(private ImageModelCatalog $catalog) {}

    /**
     * @return array{url: string, width: ?int, height: ?int, content_type: ?string, seed: ?int, prompt: string, model: string}
     */
    public function generate(
        string $prompt,
        string $modelKey,
        string $imageSize = 'square_hd',
        ?int $seed = null,
        int $timeout = 90,
    ): array {
        $key = (string) config('services.fal.key');
        if ($key === '') {
            throw new RuntimeException('Image generation is not configured.');
        }

        $prompt = trim($prompt);
        if ($prompt === '') {
            throw new RuntimeException('Image prompt is required.');
        }

        if (! in_array($imageSize, self::ALLOWED_SIZES, true)) {
            $imageSize = 'square_hd';
        }

        if (! $this->catalog->isValid($modelKey)) {
            throw new RuntimeException('Image generation failed.');
        }

        $model = $this->catalog->get($modelKey);
        $endpoint = $model['endpoint'];
        if ($endpoint === '') {
            throw new RuntimeException('Image generation failed.');
        }

        $payload = $this->buildPayload($modelKey, $model, $prompt, $imageSize, $seed);

        if (in_array($modelKey, self::QUEUE_MODELS, true)) {
            return $this->generateViaQueue($key, $endpoint, $modelKey, $prompt, $payload, $timeout, $seed);
        }

        return $this->generateSync($key, $endpoint, $modelKey, $prompt, $payload, $timeout, $seed);
    }

    /**
     * Queue a job and return immediately. Fal calls the webhook when done.
     *
     * @return array{request_id: string, status_url: string, response_url: string, endpoint: string}
     */
    public function submit(
        string $prompt,
        string $modelKey,
        string $imageSize = 'square_hd',
        ?int $seed = null,
    ): array {
        $key = (string) config('services.fal.key');
        if ($key === '') {
            throw new RuntimeException('Image generation is not configured.');
        }

        $prompt = trim($prompt);
        if ($prompt === '') {
            throw new RuntimeException('Image prompt is required.');
        }

        if (! in_array($imageSize, self::ALLOWED_SIZES, true)) {
            $imageSize = 'square_hd';
        }

        if (! $this->catalog->isValid($modelKey)) {
            throw new RuntimeException('Image generation failed.');
        }

        $model = $this->catalog->get($modelKey);
        $endpoint = $model['endpoint'];
        if ($endpoint === '') {
            throw new RuntimeException('Image generation failed.');
        }

        $payload = $this->buildPayload($modelKey, $model, $prompt, $imageSize, $seed);
        $webhook = rtrim((string) config('app.url'), '/').'/api/webhooks/fal/image';
        $submit = Http::withHeaders([
            'Authorization' => 'Key '.$key,
            'Content-Type' => 'application/json',
        ])->timeout(30)->post('https://queue.fal.run/'.$endpoint.'?fal_webhook='.urlencode($webhook), $payload);

        if ($submit->failed()) {
            Log::warning('fal.image.queue_submit_failed', [
                'model' => $modelKey,
                'status' => $submit->status(),
                'body' => mb_substr($submit->body(), 0, 500),
            ]);
            throw new RuntimeException('Image generation failed.');
        }

        $submitJson = $submit->json();
        $requestId = is_string($submitJson['request_id'] ?? null) ? trim($submitJson['request_id']) : '';
        if ($requestId === '') {
            throw new RuntimeException('Image generation failed.');
        }

        $statusUrl = 'https://queue.fal.run/'.$endpoint.'/requests/'.$requestId.'/status';
        if (is_string($submitJson['status_url'] ?? null) && $submitJson['status_url'] !== '') {
            $statusUrl = $submitJson['status_url'];
        }
        $responseUrl = 'https://queue.fal.run/'.$endpoint.'/requests/'.$requestId;
        if (is_string($submitJson['response_url'] ?? null) && $submitJson['response_url'] !== '') {
            $responseUrl = $submitJson['response_url'];
        }

        return [
            'request_id' => $requestId,
            'status_url' => $statusUrl,
            'response_url' => $responseUrl,
            'endpoint' => $endpoint,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{url: string, width: ?int, height: ?int, content_type: ?string, seed: ?int, prompt: string, model: string}
     */
    private function generateSync(
        string $key,
        string $endpoint,
        string $modelKey,
        string $prompt,
        array $payload,
        int $timeout,
        ?int $seed,
    ): array {
        $response = Http::withHeaders([
            'Authorization' => 'Key '.$key,
            'Content-Type' => 'application/json',
        ])->timeout($timeout)->post('https://fal.run/'.$endpoint, $payload);

        if ($response->failed()) {
            Log::warning('fal.image.sync_failed', [
                'model' => $modelKey,
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 500),
            ]);
            throw new RuntimeException('Image generation failed.');
        }

        return $this->parseImageResponse($response->json(), $modelKey, $prompt, $seed);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{url: string, width: ?int, height: ?int, content_type: ?string, seed: ?int, prompt: string, model: string}
     */
    private function generateViaQueue(
        string $key,
        string $endpoint,
        string $modelKey,
        string $prompt,
        array $payload,
        int $timeout,
        ?int $seed,
    ): array {
        $headers = [
            'Authorization' => 'Key '.$key,
            'Content-Type' => 'application/json',
        ];

        $submit = Http::withHeaders($headers)
            ->timeout(30)
            ->post('https://queue.fal.run/'.$endpoint, $payload);

        if ($submit->failed()) {
            Log::warning('fal.image.queue_submit_failed', [
                'model' => $modelKey,
                'status' => $submit->status(),
                'body' => mb_substr($submit->body(), 0, 500),
            ]);
            throw new RuntimeException('Image generation failed.');
        }

        $submitJson = $submit->json();
        $requestId = is_string($submitJson['request_id'] ?? null)
            ? trim($submitJson['request_id'])
            : '';
        if ($requestId === '') {
            Log::warning('fal.image.queue_missing_request_id', [
                'model' => $modelKey,
                'body' => mb_substr($submit->body(), 0, 500),
            ]);
            throw new RuntimeException('Image generation failed.');
        }

        $statusUrl = 'https://queue.fal.run/'.$endpoint.'/requests/'.$requestId.'/status';
        if (is_string($submitJson['status_url'] ?? null) && $submitJson['status_url'] !== '') {
            $statusUrl = $submitJson['status_url'];
        }

        $responseUrl = 'https://queue.fal.run/'.$endpoint.'/requests/'.$requestId;
        if (is_string($submitJson['response_url'] ?? null) && $submitJson['response_url'] !== '') {
            $responseUrl = $submitJson['response_url'];
        }

        $deadline = microtime(true) + max(30, $timeout);
        $status = '';

        while (microtime(true) < $deadline) {
            usleep(app()->environment('testing') ? 10000 : 1500000);

            $statusResponse = Http::withHeaders($headers)
                ->timeout(30)
                ->get($statusUrl);

            if ($statusResponse->failed()) {
                Log::warning('fal.image.queue_status_failed', [
                    'model' => $modelKey,
                    'request_id' => $requestId,
                    'status' => $statusResponse->status(),
                    'body' => mb_substr($statusResponse->body(), 0, 500),
                ]);
                throw new RuntimeException('Image generation failed.');
            }

            $statusJson = $statusResponse->json();
            $status = strtoupper((string) ($statusJson['status'] ?? ''));

            if (in_array($status, ['COMPLETED', 'OK', 'SUCCESS'], true)) {
                break;
            }

            if (in_array($status, ['FAILED', 'ERROR', 'CANCELLED'], true)) {
                Log::warning('fal.image.queue_job_failed', [
                    'model' => $modelKey,
                    'request_id' => $requestId,
                    'status' => $status,
                    'body' => mb_substr($statusResponse->body(), 0, 500),
                ]);
                throw new RuntimeException('Image generation failed.');
            }
        }

        if (! in_array($status, ['COMPLETED', 'OK', 'SUCCESS'], true)) {
            Log::warning('fal.image.queue_timeout', [
                'model' => $modelKey,
                'request_id' => $requestId,
                'last_status' => $status,
            ]);
            throw new RuntimeException('Image generation failed.');
        }

        $resultResponse = Http::withHeaders($headers)
            ->timeout(60)
            ->get($responseUrl);

        if ($resultResponse->failed()) {
            Log::warning('fal.image.queue_result_failed', [
                'model' => $modelKey,
                'request_id' => $requestId,
                'status' => $resultResponse->status(),
                'body' => mb_substr($resultResponse->body(), 0, 500),
            ]);
            throw new RuntimeException('Image generation failed.');
        }

        return $this->parseImageResponse($resultResponse->json(), $modelKey, $prompt, $seed);
    }

    /**
     * @param  mixed  $json
     * @return array{url: string, width: ?int, height: ?int, content_type: ?string, seed: ?int, prompt: string, model: string}
     */
    private function parseImageResponse(mixed $json, string $modelKey, string $prompt, ?int $seed): array
    {
        if (! is_array($json)) {
            throw new RuntimeException('Image generation failed.');
        }

        $images = is_array($json['images'] ?? null)
            ? $json['images']
            : (is_array($json['data']['images'] ?? null) ? $json['data']['images'] : []);

        $first = is_array($images[0] ?? null) ? $images[0] : null;
        $url = is_string($first['url'] ?? null) ? trim($first['url']) : '';
        if ($url === '') {
            Log::warning('fal.image.missing_url', [
                'model' => $modelKey,
                'keys' => array_keys($json),
            ]);
            throw new RuntimeException('Image generation failed.');
        }

        return [
            'url' => $url,
            'width' => isset($first['width']) ? (int) $first['width'] : null,
            'height' => isset($first['height']) ? (int) $first['height'] : null,
            'content_type' => isset($first['content_type']) ? (string) $first['content_type'] : 'image/jpeg',
            'seed' => isset($json['seed']) ? (int) $json['seed'] : $seed,
            'prompt' => (string) ($json['prompt'] ?? $prompt),
            'model' => $modelKey,
        ];
    }

    /**
     * @param  array{options: array<string, mixed>}  $model
     * @return array<string, mixed>
     */
    private function buildPayload(
        string $modelKey,
        array $model,
        string $prompt,
        string $imageSize,
        ?int $seed,
    ): array {
        $options = $model['options'];

        return match ($modelKey) {
            'gpt_image_2' => $this->gptPayload($prompt, $imageSize, $options),
            'nano_banana_2' => $this->nanoBananaPayload($prompt, $imageSize, $seed, $options),
            'flux' => $this->fluxPayload($prompt, $imageSize, $seed, $options),
            default => throw new RuntimeException('Image generation failed.'),
        };
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function gptPayload(string $prompt, string $imageSize, array $options): array
    {
        // GPT Image 2 schema has no seed — sending it causes 422.
        return [
            'prompt' => $prompt,
            'image_size' => $imageSize,
            'quality' => 'medium',
            'num_images' => (int) ($options['num_images'] ?? 1),
            'output_format' => (string) ($options['output_format'] ?? 'jpeg'),
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function nanoBananaPayload(string $prompt, string $imageSize, ?int $seed, array $options): array
    {
        $payload = [
            'prompt' => $prompt,
            'aspect_ratio' => $this->aspectRatioForSize($imageSize),
            'num_images' => (int) ($options['num_images'] ?? 1),
            'output_format' => (string) ($options['output_format'] ?? 'jpeg'),
            'resolution' => (string) ($options['resolution'] ?? '1K'),
        ];
        if ($seed !== null) {
            $payload['seed'] = $seed;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function fluxPayload(string $prompt, string $imageSize, ?int $seed, array $options): array
    {
        $payload = [
            'prompt' => $prompt,
            'image_size' => $imageSize,
            'num_images' => (int) ($options['num_images'] ?? 1),
            'num_inference_steps' => (int) ($options['num_inference_steps'] ?? 4),
            'output_format' => (string) ($options['output_format'] ?? 'jpeg'),
            'enable_safety_checker' => (bool) ($options['enable_safety_checker'] ?? true),
        ];
        if ($seed !== null) {
            $payload['seed'] = $seed;
        }

        return $payload;
    }

    private function aspectRatioForSize(string $imageSize): string
    {
        return match ($imageSize) {
            'portrait_16_9' => '9:16',
            'landscape_16_9' => '16:9',
            'landscape_4_3' => '4:3',
            default => '1:1',
        };
    }
}
