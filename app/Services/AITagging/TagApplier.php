<?php

namespace App\Services\AITagging;

use App\Enums\ModelAttribute;
use App\Models\Link;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Applies parsed tag-suggestion records to a user's links.
 *
 * Shared by the offline import command (ai:import-tags) and the online
 * suggestion command (ai:suggest-tags) so tag matching, creation, and the
 * merge/replace semantics live in exactly one place.
 */
class TagApplier
{
    /**
     * @param Collection<int,array{raw:string,id:int|null,url:string|null,tags:array<int,string>}> $records
     * @param array{mode?:string,dry_run?:bool,create_tags?:bool,skip_existing?:bool} $options
     * @param callable|null $log function(string $level, string $message): void — level: line|warn|error
     * @return array<string,int> Stats keyed identically to the previous ImportAITags output.
     */
    public function apply(User $user, Collection $records, array $options = [], ?callable $log = null): array
    {
        $mode = ($options['mode'] ?? 'merge') === 'replace' ? 'replace' : 'merge';
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $createTags = (bool) ($options['create_tags'] ?? true);
        $skipExisting = (bool) ($options['skip_existing'] ?? false);

        $say = $log ?? static fn(string $level, string $message) => null;

        $stats = [
            'records' => 0,
            'links_found' => 0,
            'links_updated' => 0,
            'links_skipped_existing' => 0,
            'links_not_found' => 0,
            'tags_created' => 0,
            'tags_skipped_unknown' => 0,
            'errors' => 0,
        ];

        foreach ($records as $record) {
            $stats['records']++;

            $link = $this->resolveLink($user, $record);
            if (!$link) {
                $stats['links_not_found']++;
                $say('warn', 'Skipping: link not found for input: ' . $record['raw']);
                continue;
            }

            $stats['links_found']++;

            if ($skipExisting && $link->tags()->exists()) {
                $stats['links_skipped_existing']++;
                continue;
            }

            $tagNames = $this->normalizeTagNames($record['tags']);
            if (empty($tagNames)) {
                $say('warn', 'Skipping link #' . $link->id . ': no valid tags.');
                continue;
            }

            $tagIds = [];
            foreach ($tagNames as $tagName) {
                $tag = $this->findTagForUser($user, $tagName);
                if (!$tag) {
                    if (!$createTags) {
                        $stats['tags_skipped_unknown']++;
                        continue;
                    }

                    if ($dryRun) {
                        // Count the would-be creation without touching the database.
                        $stats['tags_created']++;
                        continue;
                    }

                    $tag = $this->createTagForUser($user, $tagName, $say);
                    if (!$tag) {
                        $stats['errors']++;
                        continue;
                    }

                    $stats['tags_created']++;
                }

                if ($tag) {
                    $tagIds[] = $tag->id;
                }
            }

            $tagIds = array_values(array_unique($tagIds));

            if ($dryRun) {
                $say('line', sprintf(
                    'Would %s tags on link #%d (%s): %s',
                    $mode,
                    $link->id,
                    $link->url,
                    implode(', ', $tagNames),
                ));
                $stats['links_updated']++;
                continue;
            }

            if (empty($tagIds)) {
                $say('warn', 'Skipping link #' . $link->id . ': no tags could be resolved/created.');
                continue;
            }

            if ($mode === 'replace') {
                $link->tags()->sync($tagIds);
            } else {
                $link->tags()->syncWithoutDetaching($tagIds);
            }

            // Mark the link as AI-tagged without firing audits/events or bumping updated_at,
            // so incremental re-runs can skip links already processed. toBase() bypasses
            // Eloquent's automatic timestamp handling and model events.
            Link::whereKey($link->id)->toBase()->update(['ai_tagged_at' => now()]);

            $stats['links_updated']++;
        }

        return $stats;
    }

    /**
     * @param array{raw:string,id:int|null,url:string|null,tags:array<int,string>} $record
     */
    private function resolveLink(User $user, array $record): ?Link
    {
        if (!empty($record['id'])) {
            return Link::query()
                ->byUser($user->id)
                ->whereKey($record['id'])
                ->first();
        }

        if (!empty($record['url'])) {
            return Link::query()
                ->byUser($user->id)
                ->where('url', $record['url'])
                ->first();
        }

        return null;
    }

    /**
     * @param array<int,string> $tags
     * @return array<int,string>
     */
    private function normalizeTagNames(array $tags): array
    {
        $out = [];
        $seen = [];

        foreach ($tags as $tag) {
            $tag = trim((string) $tag);
            $tag = preg_replace('/\s+/u', ' ', $tag) ?? $tag;
            $tag = trim($tag, " \t\n\r\0\x0B\"'");
            if ($tag === '') {
                continue;
            }

            $key = mb_strtolower($tag);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $tag;
        }

        return $out;
    }

    private function findTagForUser(User $user, string $name): ?Tag
    {
        $nameLower = mb_strtolower($name);

        return Tag::query()
            ->byUser($user->id)
            ->whereRaw('LOWER(name) = ?', [$nameLower])
            ->first();
    }

    private function createTagForUser(User $user, string $name, callable $say): ?Tag
    {
        $visibility = usersettings('tags_default_visibility', $user->id) ?? ModelAttribute::VISIBILITY_PRIVATE;

        try {
            return Tag::create([
                'user_id' => $user->id,
                'name' => $name,
                'visibility' => (int) $visibility,
            ]);
        } catch (\Throwable $e) {
            $say('error', 'Failed to create tag "' . $name . '": ' . $e->getMessage());
            return null;
        }
    }
}
