<?php

namespace App\Services\AITagging;

use Illuminate\Support\Facades\Log;

/**
 * Thin client for the OpenRouter chat-completions API (OpenAI compatible).
 *
 * Mirrors the Microlink integration pattern in App\Helper\HtmlMeta: a Guzzle
 * client configured from config('services.openrouter.*'), with all failures
 * logged and turned into a null return so callers can degrade gracefully.
 */
class OpenRouterClient
{
    /**
     * Whether online suggestions are enabled and a key is present.
     */
    public function isConfigured(): bool
    {
        return (bool) config('services.openrouter.enabled', false)
            && !empty(config('services.openrouter.api_key'));
    }

    /**
     * Send a chat completion request and return the assistant message content.
     *
     * @param array<int,array{role:string,content:string}> $messages
     */
    public function chat(array $messages, ?string $model = null): ?string
    {
        $apiKey = config('services.openrouter.api_key');
        if (empty($apiKey)) {
            Log::warning('OpenRouter request skipped: no API key configured.');
            return null;
        }

        $client = new \GuzzleHttp\Client([
            'base_uri' => rtrim((string) config('services.openrouter.base_url', 'https://openrouter.ai/api/v1'), '/') . '/',
            'timeout' => config('services.openrouter.timeout', 60),
        ]);

        try {
            $headers = [
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
            ];

            // Optional OpenRouter attribution headers.
            if (!empty($referer = config('services.openrouter.referer'))) {
                $headers['HTTP-Referer'] = $referer;
            }
            if (!empty($title = config('services.openrouter.title'))) {
                $headers['X-Title'] = $title;
            }

            $response = $client->post('chat/completions', [
                'headers' => $headers,
                'json' => [
                    'model' => $model ?: config('services.openrouter.model', 'deepseek/deepseek-chat-v3-0324'),
                    'messages' => $messages,
                    'temperature' => 0.2,
                ],
            ]);

            $payload = json_decode((string) $response->getBody(), true);
            $content = $payload['choices'][0]['message']['content'] ?? null;

            if (!is_string($content) || trim($content) === '') {
                Log::warning('OpenRouter returned an empty completion.');
                return null;
            }

            return $content;
        } catch (\Throwable $e) {
            Log::warning('OpenRouter request failed: ' . $e->getMessage());
            return null;
        }
    }
}
