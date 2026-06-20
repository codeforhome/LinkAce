<?php

namespace App\Helper;

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

        try {
            // For Twitter/X URLs, try with browser headers to bypass login wall
            if ($this->isMicrolinkUrl($url)) {
                $this->meta = $this->getMetaFromMicrolink($url) ?? $this->getMetaWithBrowserHeaders($url);
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
            // DisallowedIpException catches all private and loopback IPs as well as hostnames resolving to those IPs
            Log::warning($url . ': ' . $e->getMessage());
            if ($flashAlerts) {
                flash(trans('link.added_request_error'), 'warning');
            }
            return $this->fallback;
        }

        return $this->buildLinkMeta();
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
     * Get meta data for Twitter/X URLs using browser headers to bypass login wall
     */
    protected function getMetaWithBrowserHeaders(string $url): array
    {
        $client = new \GuzzleHttp\Client([
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.5',
                'Accept-Encoding' => 'gzip, deflate',
                'Connection' => 'keep-alive',
                'Upgrade-Insecure-Requests' => '1',
            ],
            'timeout' => 10,
        ]);

        try {
            $response = $client->get($url);
            $html = $response->getBody()->getContents();

            // Parse meta tags manually
            $meta = [];
            if (preg_match_all('/<meta[^>]+property=["\']([^"\']+)["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i', $html, $matches)) {
                foreach ($matches[1] as $index => $property) {
                    $meta[$property] = $matches[2][$index];
                }
            }

            // Also check name attributes
            if (preg_match_all('/<meta[^>]+name=["\']([^"\']+)["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i', $html, $matches)) {
                foreach ($matches[1] as $index => $name) {
                    $meta[$name] = $matches[2][$index];
                }
            }

            // For Twitter/X, if we don't have meta data, try to extract from HTML content
            if (empty($meta['og:title']) && empty($meta['title'])) {
                // Try to extract username from URL
                if (preg_match('/(?:twitter\.com|x\.com)\/([a-zA-Z0-9_]+)/', $url, $usernameMatch)) {
                    $username = $usernameMatch[1];
                    $meta['title'] = '@' . $username . ' on X';
                } else {
                    $meta['title'] = 'X (formerly Twitter)';
                }
            }

            return $meta;
        } catch (\Exception $e) {
            Log::warning('Failed to fetch Twitter meta with browser headers: ' . $e->getMessage());
            // Fallback to regular method
            return \Kovah\HtmlMeta\Facades\HtmlMeta::forUrl($url)->getMeta();
        }
    }

    /**
     * Try to get meta data for Twitter/X URLs using Microlink.
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

    protected function isMicrolinkUrl(string $url): bool
    {
        return $this->isTwitterUrl($url) || str_contains($url, 'facebook.com');
    }
}
