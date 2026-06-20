<?php

namespace App\Services\Metadata;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * General fallback for JS-heavy or scrape-resistant pages whose real <meta> tags
 * are not served to a plain fetch. Uses the free Jina AI Reader (https://r.jina.ai),
 * which renders the page and returns a clean title + content as JSON. No API key is
 * required (an optional key raises rate limits).
 *
 * Note: the target URL is fetched by Jina's servers, not ours — callers must only
 * pass public URLs (private/loopback URLs are rejected earlier by the standard fetch).
 */
class JinaReaderProvider
{
    public function isEnabled(): bool
    {
        return (bool) config('services.jina.enabled', true);
    }

    /**
     * @return array<string,string>|null Meta keyed like the HtmlMeta helper expects.
     */
    public function fetch(string $url): ?array
    {
        $base = rtrim((string) config('services.jina.base_url', 'https://r.jina.ai'), '/');

        try {
            $request = Http::timeout((int) config('services.jina.timeout', 15))
                ->acceptJson();

            if (!empty($apiKey = config('services.jina.api_key'))) {
                $request = $request->withToken($apiKey);
            }

            // Jina Reader takes the full target URL appended to its base.
            $response = $request->get($base . '/' . $url);

            if (!$response->successful()) {
                return null;
            }

            $data = $response->json('data');
            if (!is_array($data)) {
                return null;
            }

            $meta = [];

            if (!empty($data['title'])) {
                $meta['title'] = (string) $data['title'];
            }

            $description = $data['description'] ?? null;
            if (empty($description) && !empty($data['content'])) {
                // Fall back to a trimmed excerpt of the readable content.
                $description = $this->excerpt((string) $data['content']);
            }
            if (!empty($description)) {
                $meta['description'] = (string) $description;
            }

            return $meta ?: null;
        } catch (\Throwable $e) {
            Log::warning('Jina Reader request failed: ' . $e->getMessage());
            return null;
        }
    }

    private function excerpt(string $content, int $limit = 300): string
    {
        $content = trim(preg_replace('/\s+/u', ' ', $content) ?? $content);

        if (mb_strlen($content) <= $limit) {
            return $content;
        }

        return rtrim(mb_substr($content, 0, $limit)) . '…';
    }
}
