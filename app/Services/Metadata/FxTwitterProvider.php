<?php

namespace App\Services\Metadata;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Resolves metadata for Twitter/X status URLs via the free, open FxTwitter API
 * (https://github.com/FixTweet/FxTwitter). Twitter/X serves a login wall / JS-only
 * page to plain server fetches, so its real <meta> tags are unavailable; FxTwitter
 * returns the tweet's author, text and media as JSON without authentication.
 */
class FxTwitterProvider
{
    /**
     * Whether this provider can resolve the given URL (a specific tweet/status).
     */
    public function handles(string $url): bool
    {
        return preg_match('#https?://(?:www\.)?(?:twitter\.com|x\.com)/[^/]+/status/\d+#i', $url) === 1;
    }

    /**
     * @return array<string,string>|null Meta keyed like the HtmlMeta helper expects
     *                                    (title, description, og:image, twitter:image).
     */
    public function fetch(string $url): ?array
    {
        if (!preg_match('#/status/(\d+)#', $url, $m)) {
            return null;
        }
        $statusId = $m[1];

        $base = rtrim((string) config('services.fxtwitter.base_url', 'https://api.fxtwitter.com'), '/');

        try {
            $response = Http::timeout((int) config('services.fxtwitter.timeout', 8))
                ->acceptJson()
                ->get($base . '/status/' . $statusId);

            if (!$response->successful()) {
                return null;
            }

            $tweet = $response->json('tweet');
            if (!is_array($tweet)) {
                return null;
            }

            $author = $tweet['author'] ?? [];
            $name = $author['name'] ?? null;
            $handle = $author['screen_name'] ?? null;

            $title = $name
                ? trim($name . ($handle ? ' (@' . $handle . ')' : ''))
                : ($handle ? '@' . $handle . ' on X' : 'Post on X');

            $meta = ['title' => $title];

            if (!empty($tweet['text'])) {
                $meta['description'] = (string) $tweet['text'];
            }

            $image = $this->extractImage($tweet);
            if ($image !== null) {
                $meta['og:image'] = $image;
                $meta['twitter:image'] = $image;
            }

            return $meta;
        } catch (\Throwable $e) {
            Log::warning('FxTwitter request failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * @param array<string,mixed> $tweet
     */
    private function extractImage(array $tweet): ?string
    {
        $photos = $tweet['media']['photos'] ?? null;
        if (is_array($photos) && !empty($photos[0]['url'])) {
            return (string) $photos[0]['url'];
        }

        $videos = $tweet['media']['videos'] ?? null;
        if (is_array($videos) && !empty($videos[0]['thumbnail_url'])) {
            return (string) $videos[0]['thumbnail_url'];
        }

        return null;
    }
}
