<?php

namespace App\Services\Tagging;

use App\Enums\ModelAttribute;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Merges one or more source tags into a single target tag for a user: every link carrying a
 * source tag is re-attached to the target (de-duplicated), then the source tag is deleted.
 * Used by the `tags:merge` command and the apply step of `tags:suggest-merges`.
 */
class TagMerger
{
    /**
     * @param array<int,string> $sourceNames
     * @return array{target:string,created_target:bool,merged:array<int,array{name:string,links_moved:int}>,missing:array<int,string>,links_affected:int}
     */
    public function merge(User $user, string $targetName, array $sourceNames, bool $dryRun = false): array
    {
        $targetName = trim($targetName);

        $target = $this->findTag($user, $targetName);
        $createdTarget = false;
        if (!$target) {
            $createdTarget = true;
            $target = $dryRun
                ? new Tag(['user_id' => $user->id, 'name' => $targetName])
                : $this->createTag($user, $targetName);
        }

        $merged = [];
        $missing = [];
        $affectedLinkIds = [];

        foreach ($sourceNames as $name) {
            $name = trim($name);
            if ($name === '' || mb_strtolower($name) === mb_strtolower($targetName)) {
                continue; // never merge a tag into itself
            }

            $source = $this->findTag($user, $name);
            if (!$source) {
                $missing[] = $name;
                continue;
            }

            $linkIds = $source->links()->pluck('links.id')->all();
            $affectedLinkIds = array_merge($affectedLinkIds, $linkIds);
            $merged[] = ['name' => $source->name, 'links_moved' => count($linkIds)];

            if ($dryRun) {
                continue;
            }

            DB::transaction(function () use ($source, $target, $linkIds) {
                // Attach the target to every link the source was on (no duplicates), then remove
                // the source from those links and delete the now-orphaned source tag.
                foreach ($linkIds as $linkId) {
                    $target->links()->syncWithoutDetaching([$linkId]);
                }
                $source->links()->detach();
                $source->delete();
            });
        }

        return [
            'target' => $target->name,
            'created_target' => $createdTarget,
            'merged' => $merged,
            'missing' => $missing,
            'links_affected' => count(array_unique($affectedLinkIds)),
        ];
    }

    private function findTag(User $user, string $name): ?Tag
    {
        return Tag::query()
            ->byUser($user->id)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();
    }

    private function createTag(User $user, string $name): Tag
    {
        $visibility = usersettings('tags_default_visibility', $user->id) ?? ModelAttribute::VISIBILITY_PRIVATE;

        return Tag::create([
            'user_id' => $user->id,
            'name' => $name,
            'visibility' => (int) $visibility,
        ]);
    }
}
