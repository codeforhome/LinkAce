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
            if ($jina->isEnabled() && ($jinaMeta = $jina->fetch($url)) !== null && $this->isUsefulMeta($jinaMeta)) {
                $this->applyFallbackMeta($jinaMeta);
            }
        }

        // Optional legacy fallback: Microlink, only if an API key is configured and still weak.
        if ($this->isWeakMeta() && !empty(config('services.microlink.api_key'))) {
            $microlinkMeta = $this->getMetaFromMicrolink($url);
            if ($microlinkMeta !== null && $this->isUsefulMeta($microlinkMeta)) {
                $this->applyFallbackMeta($microlinkMeta);
            }
        }

        return $this->buildLinkMeta();
    }

    /**
     * Whether a fallback provider actually returned something better than a bot-wall, so we
     * don't replace one junk result with another (e.g. a reader that also got blocked).
     *
     * @param array<string,mixed> $meta
     */
    protected function isUsefulMeta(array $meta): bool
    {
        $title = trim((string) ($meta['title'] ?? ''));
        $description = trim((string) ($meta['description'] ?? ''));

        $usefulTitle = $title !== '' && !$this->containsJunk($title);
        $usefulDescription = $description !== '' && !$this->containsJunk($description);

        return $usefulTitle || $usefulDescription;
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
     * Bot-wall / placeholder phrases that mean the real page was never served (Cloudflare
     * challenges, login walls, anti-bot checks). Matched case-insensitively as substrings
     * against both the title and the description. Kept specific to avoid flagging legitimate
     * articles that merely mention "login" etc.
     *
     * @var array<int,string>
     */
    protected array $junkPatterns = [
        'just a moment',
        'please wait',
        'attention required',
        'access denied',
        'are you a robot',
        'are you human',
        'verify you are human',
        'verifying you are human',
        'please verify you',
        'checking your browser',
        'enable javascript',
        'security check',
        'you have been blocked',
        "you've been blocked",
        'network security',
        'log in to continue',
        'log in to your',
        'please log in',
        'sign in to continue',
    ];

    /**
     * Whether the resolved meta is too weak to be useful, which is the signal to try a general
     * reader fallback.
     */
    protected function isWeakMeta(): bool
    {
        $description = (string) ($this->meta['description']
            ?? $this->meta['og:description']
            ?? $this->meta['twitter:description']
            ?? '');

        return $this->looksWeak($this->url, $this->meta['title'] ?? '', $description);
    }

    /**
     * Whether a (url, title, description) triple is too weak to be useful: the title is unusable
     * (empty, the bare host, a bot-wall phrase, or a legacy social placeholder like "@user on X")
     * AND the description is missing or itself a bot-wall message.
     *
     * Public so callers can judge an *existing* stored link (e.g. the metadata-refresh command).
     */
    public function looksWeak(string $url, ?string $title, ?string $description): bool
    {
        $description = trim((string) $description);
        $descriptionIsWeak = $description === '' || $this->containsJunk($description);

        return $this->titleLooksWeak($url, $title) && $descriptionIsWeak;
    }

    /**
     * Whether a title alone is unusable: empty, the bare host, a bot-wall phrase, or a legacy
     * social placeholder ("@user on X"). Used as the refresh gate, where a bad title warrants a
     * re-fetch even if a (possibly stale) description is present.
     */
    public function titleLooksWeak(string $url, ?string $title): bool
    {
        $title = trim((string) $title);

        $host = parse_url($url, PHP_URL_HOST) ?: '';
        $hostNoWww = preg_replace('/^www\./', '', $host) ?? $host;

        return $title === ''
            || $title === $host
            || $title === $hostNoWww
            || $this->containsJunk($title)
            || $this->isLegacyPlaceholderTitle($title);
    }

    protected function containsJunk(string $text): bool
    {
        $text = mb_strtolower($text);

        foreach ($this->junkPatterns as $pattern) {
            if (str_contains($text, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Placeholder titles produced by the older Twitter/X handling, before the provider chain
     * (e.g. "@username on X", "Post on X", "X (formerly Twitter)").
     */
    protected function isLegacyPlaceholderTitle(string $title): bool
    {
        $title = mb_strtolower(trim($title));

        return $title !== ''
            && (preg_match('/ on x$/', $title) === 1 || str_contains($title, '(formerly twitter)'));
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
