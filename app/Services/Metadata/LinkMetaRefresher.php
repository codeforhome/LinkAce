<?php

namespace App\Services\Metadata;

use App\Helper\HtmlMeta;
use App\Models\Link;

/**
 * Re-fetches metadata for an existing link via the HtmlMeta provider chain and writes back an
 * improved title/description/thumbnail. Shared by the `links:refresh-meta` command and the
 * per-link "Refresh metadata" button so the safety rules live in one place.
 *
 * Safety rules:
 * - Without $force, only links whose CURRENT meta looks weak/placeholder are touched (never
 *   clobber good or hand-edited titles).
 * - The fresh result is never applied if the fetch failed or is itself weak (e.g. a bot-wall),
 *   so we don't replace one junk value with another.
 */
class LinkMetaRefresher
{
    /**
     * @return array{changed:bool,reason:string,old:array{title:?string,description:?string},new:array{title:?string,description:?string}}
     */
    public function refresh(Link $link, bool $force = false, bool $dryRun = false): array
    {
        $meta = new HtmlMeta();

        $result = static fn(bool $changed, string $reason, ?string $newTitle = null, ?string $newDescription = null): array => [
            'changed' => $changed,
            'reason' => $reason,
            'old' => ['title' => $link->title, 'description' => $link->description],
            'new' => ['title' => $newTitle, 'description' => $newDescription],
        ];

        // Skip links that already have a usable title, unless explicitly forced. A weak/placeholder
        // title (hostname, bot-wall, or "@user on X") warrants a refresh even if a stale
        // description exists — the title is the visible label we want to fix.
        if (!$force && !$meta->titleLooksWeak($link->url, $link->title)) {
            return $result(false, 'current_ok');
        }

        $fresh = $meta->getFromUrl($link->url);

        if ($fresh['success'] === false) {
            return $result(false, 'fetch_failed');
        }

        // Don't overwrite with another junk result (e.g. a site that blocks the reader too).
        if ($meta->looksWeak($link->url, $fresh['title'], $fresh['description'])) {
            return $result(false, 'fresh_weak');
        }

        $newTitle = $fresh['title'];
        $newDescription = $fresh['description'];

        $titleChanged = $newTitle !== null && $newTitle !== '' && $newTitle !== $link->title;
        $descriptionChanged = $newDescription !== $link->description;
        $thumbnailChanged = !empty($fresh['thumbnail']) && $fresh['thumbnail'] !== $link->thumbnail;

        if (!$titleChanged && !$descriptionChanged && !$thumbnailChanged) {
            return $result(false, 'no_change', $newTitle, $newDescription);
        }

        if (!$dryRun) {
            if ($titleChanged) {
                $link->title = $newTitle;
            }
            $link->description = $newDescription;
            if (!empty($fresh['thumbnail'])) {
                $link->thumbnail = $fresh['thumbnail'];
            }
            $link->save();
        }

        return $result(true, 'refreshed', $newTitle, $newDescription);
    }
}
