<?php

namespace App\Services\SocialApi;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SocialApiClient
{
    public function request(): PendingRequest
    {
        $key = (string) config('services.socialapi.key');
        if ($key === '') {
            throw new RuntimeException('SOCAPI_KEY is not configured.');
        }

        return Http::baseUrl(rtrim((string) config('services.socialapi.base_url'), '/'))
            ->withToken($key)
            ->acceptJson()
            ->asJson()
            ->timeout(30);
    }

    public function get(string $path, array $query = []): array
    {
        return $this->decode($this->request()->get($path, $query));
    }

    public function post(string $path, array $payload = []): array
    {
        return $this->decode($this->request()->post($path, $payload));
    }

    public function patch(string $path, array $payload = []): array
    {
        return $this->decode($this->request()->patch($path, $payload));
    }

    public function delete(string $path): array
    {
        return $this->decode($this->request()->delete($path));
    }

    /**
     * Multipart upload (SocialAPI server-side media path, max 50MB).
     *
     * @return array<string, mixed>
     */
    public function upload(string $path, string $field, string $contents, string $filename, ?string $mime = null): array
    {
        $key = (string) config('services.socialapi.key');
        if ($key === '') {
            throw new RuntimeException('SOCAPI_KEY is not configured.');
        }

        $pending = Http::baseUrl(rtrim((string) config('services.socialapi.base_url'), '/'))
            ->withToken($key)
            ->acceptJson()
            ->timeout(120)
            ->attach($field, $contents, $filename, $mime ? ['Content-Type' => $mime] : []);

        return $this->decode($pending->post($path));
    }

    private function decode(Response $response): array
    {
        if ($response->failed()) {
            throw new RuntimeException('SocialAPI request failed: '.$response->body());
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }
}
