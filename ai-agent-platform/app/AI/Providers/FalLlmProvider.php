<?php

namespace App\AI\Providers;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class FalLlmProvider
{
    /**
     * @param  array<string, mixed>  $options  temperature, max_tokens, json_object, timeout
     */
    public function chat(array $messages, array $tools = [], array $options = []): array
    {
        $key = (string) config('services.fal.key');
        if ($key === '') {
            throw new RuntimeException('FAL_KEY is not configured.');
        }

        $model = (string) ($options['model'] ?? config('services.fal.model', 'google/gemini-2.5-flash'));
        $payload = [
            'model' => $model !== '' ? $model : 'google/gemini-2.5-flash',
            'messages' => $messages,
        ];

        if (isset($options['temperature'])) {
            $payload['temperature'] = $options['temperature'];
        }
        if (isset($options['max_tokens'])) {
            $payload['max_tokens'] = $options['max_tokens'];
        }
        if (! empty($options['json_object'])) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        if ($tools !== []) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = 'auto';
        }

        $timeout = (int) ($options['timeout'] ?? 60);
        $response = $this->postChat($key, $payload, $timeout);

        if ($response->failed() && ! empty($options['json_object']) && str_contains(strtolower($response->body()), 'response_format')) {
            unset($payload['response_format']);
            $response = $this->postChat($key, $payload, $timeout);
        }

        if ($response->failed()) {
            throw new RuntimeException('fal.ai request failed: '.$response->body());
        }

        return $response->json();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postChat(string $key, array $payload, int $timeout): \Illuminate\Http\Client\Response
    {
        return Http::withHeaders([
            'Authorization' => 'Key '.$key,
            'Content-Type' => 'application/json',
        ])->timeout($timeout)->post('https://fal.run/openrouter/router/openai/v1/chat/completions', $payload);
    }
}
