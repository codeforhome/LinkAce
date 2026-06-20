<?php

namespace App\Helper;

use App\Services\Metadata\FxTwitterProvider;
use App\Services\Metadata\JinaReaderProvider;
use Illuminate\Support\Facades\Log;
use Kovah\HtmlMeta\Exceptions\DisallowedIpException;
use Kovah\HtmlMeta\Exceptions\InvalidUrlException;
use Kovah\HtmlMeta\Exceptions\UnreachableUrlException;

class HtmlMeta
{
    protected string $url;
    protected array $fallback;
    protected array $meta;

    /**
     * Get the title and description of a URL.
     *
     * Returned array:
     * array [
     *   'success' => bool,
     *   'title' => string,
     *   'description' => string|null,
     *   'thumbnail' => string|null,
     * ]
     *
     * @param string $url
     * @param bool   $flashAlerts
     * @return array{success: bool, title: string, description: string|null, thumbnail: string|null}
     */
    public function getFromUrl(string $url, bool $flashAlerts = false): array
    {
        $this->url = $url;
        $this->buildFallback();

        // Provider chain: a specialized provider for known-hard hosts (tweets) is tried first;
        // otherwise the standard fetch runs, and a general reader fallback fills in when the
        // result is too weak (e.g. only a hostname title and no description).
        $fxTwitter = app(FxTwitterProvider::class);

        try {
            if ($fxTwitter->handles($url)) {
                $this->meta = $fxTwitter->fetch($url)
                    ?? \Kovah\HtmlMeta\Facades\HtmlMeta::forUrl($url)->getMeta();
            } else {
                $this->meta = \Kovah\HtmlMeta\Facades\HtmlMeta::forUrl($url)->getMeta();
            }
        } catch (InvalidUrlException $e) {
            Log::warning($url . ': ' . $e->getMessage());
            if ($flashAlerts) {
                flash(trans('link.added_connection_error'), 'warning');
            }
            return $this->fallback;
        } catch (DisallowedIpException|UnreachableUrlException $e) {
            // DisallowedIpException catches all private and loopback IPs as well as hostnames resolving to those IPs.
            // Do NOT fall through to remote readers here — that would leak an internal URL to a third party.
            Log::warning($url . ': ' . $e->getMessage());
            if ($flashAlerts) {
                flash(trans('link.added_request_error'), 'warning');
            }
            return $this->fallback;
        }

        // General fallback for scrape-resistant pages: only when the result is weak.
        if ($this->isWeakMeta()) {
            $jina = app(JinaReaderProvider::class);
            if ($jina->isEnabled() && ($jinaMeta = $jina->fetch($url)) !== null) {
                $this->applyFallbackMeta($jinaMeta);
            }
        }

        // Optional legacy fallback: Microlink, only if an API key is configured and still weak.
        if ($this->isWeakMeta() && !empty(config('services.microlink.api_key'))) {
            if (($microlinkMeta = $this->getMetaFromMicrolink($url)) !== null) {
                $this->applyFallbackMeta($microlinkMeta);
            }
        }

        return $this->buildLinkMeta();
    }

    /**
     * Merge meta from a fallback provider. Because we only reach here when the current meta is
     * weak, the provider's title/description take precedence; other keys (e.g. og:image) only
     * fill gaps so a good thumbnail from the standard fetch is never clobbered.
     *
     * @param array<string,mixed> $extra
     */
    protected function applyFallbackMeta(array $extra): void
    {
        foreach (['title', 'description'] as $key) {
            if (!empty($extra[$key])) {
                $this->meta[$key] = $extra[$key];
            }
        }

        $this->meta = $this->fillMissing($this->meta, $extra);
    }

    /**
     * Whether the resolved meta is too weak to be useful (no real title, no description),
     * which is the signal to try a general reader fallback.
     */
    protected function isWeakMeta(): bool
    {
        $title = trim((string) ($this->meta['title'] ?? ''));
        $description = $this->meta['description']
            ?? $this->meta['og:description']
            ?? $this->meta['twitter:description']
            ?? null;

        $host = parse_url($this->url, PHP_URL_HOST) ?: '';
        $titleIsWeak = $title === '' || $title === $host;

        return $titleIsWeak && empty($description);
    }

    /**
     * Fill keys missing/empty in $base from $extra without overwriting good values.
     *
     * @param array<string,mixed> $base
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    protected function fillMissing(array $base, array $extra): array
    {
        foreach ($extra as $key => $value) {
            if ($value !== null && $value !== '' && empty($base[$key])) {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    // Build a response array containing the link meta including a success flag.
    protected function buildLinkMeta(): array
    {
        $this->meta['description'] ??= $this->meta['og:description']
            ?? $this->meta['twitter:description']
            ?? null;

        return [
            'success' => true,
            'title' => $this->meta['title'] ?? $this->fallback['title'],
            'description' => $this->meta['description'],
            'thumbnail' => $this->getThumbnail(),
        ];
    }

    // The fallback is used in case of errors while trying to get the link meta.
    protected function buildFallback(): void
    {
        $this->fallback = [
            'success' => false,
            'title' => parse_url($this->url, PHP_URL_HOST) ?? $this->url,
            'description' => null,
            'thumbnail' => null,
        ];
    }

    /**
     * Try to get the thumbnail from the meta tags and handle specific cases
     * where we know how to get a proper image from the website.
     *
     * @return string|null
     */
    protected function getThumbnail(): ?string
    {
        // For Twitter/X domains, prioritize twitter:image over og:image for better results
        if ($this->isTwitterUrl($this->url)) {
            $thumbnail = $this->meta['twitter:image'] ?? $this->meta['og:image'] ?? null;
        } else {
            $thumbnail = $this->meta['og:image'] ?? $this->meta['twitter:image'] ?? null;
        }

        if (!is_null($thumbnail) && parse_url($thumbnail, PHP_URL_HOST) === null) {
            // If the thumbnail does not contain the domain, add it in front of it
            $urlInfo = parse_url($this->url);
            $baseUrl = sprintf('%s://%s/', $urlInfo['scheme'], $urlInfo['host']);
            $thumbnail = $baseUrl . trim($thumbnail, '/');
        }

        /*
         * Special handling for Twitter/X: If no meta thumbnail found, provide fallback
         * Twitter requires JavaScript execution to populate meta tags
         */
        if (is_null($thumbnail) && $this->isTwitterUrl($this->url)) {
            // For Twitter profiles, try to construct avatar URL (not reliable)
            if (preg_match('/(?:twitter\.com|x\.com)\/([a-zA-Z0-9_]+)/', $this->url, $matches)) {
                $username = $matches[1];
                // This is a fallback - Twitter avatars are at pbs.twimg.com/profile_images/
                // But we don't have the specific image filename
                Log::info('Twitter/X thumbnail not available - requires JavaScript execution: ' . $this->url);
            } else {
                Log::info('Twitter/X thumbnail not available due to authentication/JS requirements: ' . $this->url);
            }
        }

        /*
         * Edge case of YouTube only (because of YouTube EU cookie consent)
         * Formula based on https://stackoverflow.com/a/2068371, returns Youtube image url
         * https://img.youtube.com/vi/[video-id]/mqdefault.jpg
         */
        if (is_null($thumbnail)) {
            if (str_contains($this->url, 'youtube.com') && str_contains($this->url, 'v=')) {
                preg_match('/v=([a-zA-Z0-9_]+)/', $this->url, $matched);
                $thumbnail = isset($matched[1]) ? 'https://img.youtube.com/vi/' . $matched[1] . '/mqdefault.jpg' : null;
            }

            if (str_contains($this->url, 'youtu.be')) {
                preg_match('/youtu.be\/([a-zA-Z0-9_]+)/', $this->url, $matched);
                $thumbnail = isset($matched[1]) ? 'https://img.youtube.com/vi/' . $matched[1] . '/mqdefault.jpg' : null;
            }
        }

        return $thumbnail;
    }

    /**
     * Try to get meta data for a URL using Microlink (optional legacy fallback).
     */
    protected function getMetaFromMicrolink(string $url): ?array
    {
        if (!config('services.microlink.enabled', false)) {
            return null;
        }

        $apiKey = config('services.microlink.api_key');
        $client = new \GuzzleHttp\Client([
            'base_uri' => config('services.microlink.base_url', 'https://api.microlink.io'),
            'timeout' => config('services.microlink.timeout', 8),
        ]);

        try {
            $headers = ['Accept' => 'application/json'];
            if (!empty($apiKey)) {
                $headers['X-Api-Key'] = $apiKey;
            }

            $response = $client->get('', [
                'query' => ['url' => $url],
                'headers' => $headers,
            ]);

            $payload = json_decode((string) $response->getBody(), true);
            if (!is_array($payload) || ($payload['status'] ?? null) !== 'success') {
                return null;
            }

            $data = $payload['data'] ?? [];
            $meta = [];

            if (!empty($data['title'])) {
                $meta['title'] = $data['title'];
            }

            if (!empty($data['description'])) {
                $meta['description'] = $data['description'];
            }

            $image = null;
            if (isset($data['image'])) {
                if (is_array($data['image'])) {
                    $image = $data['image']['url'] ?? null;
                } elseif (is_string($data['image'])) {
                    $image = $data['image'];
                }
            }

            if (!empty($image)) {
                $meta['og:image'] = $image;
            }

            return $meta ?: null;
        } catch (\Throwable $e) {
            Log::warning('Microlink request failed: ' . $e->getMessage());
            return null;
        }
    }

    protected function isTwitterUrl(string $url): bool
    {
        return str_contains($url, 'twitter.com') || str_contains($url, 'x.com');
    }
}
