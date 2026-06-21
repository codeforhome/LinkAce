<?php

namespace App\Console\Commands;

use App\Models\Tag;
use App\Models\User;
use Illuminate\Console\Command;

class PruneTags extends Command
{
    use AsksForUser;

    protected $signature = 'tags:prune
                        {--max-links=0 : Delete tags with at most this many links (0 = unused only).}
                        {--include-canonical : Also prune canonical tags (off by default).}
                        {--dry-run : Show what would be deleted without applying.}
                        {--user-email= : Operate on tags owned by this user (skips interactive prompt).}';

    protected $description = 'Delete rarely-used tags (default: only unused tags). Canonical tags are protected.';

    public function handle(): int
    {
        if (!$this->resolveUser()) {
            return self::FAILURE;
        }

        $max = max(0, (int) $this->option('max-links'));
        $dryRun = (bool) $this->option('dry-run');
        $includeCanonical = (bool) $this->option('include-canonical');

        $query = Tag::query()
            ->byUser($this->user->id)
            ->withCount('links')
            ->orderBy('name');

        if (!$includeCanonical) {
            $query->where('is_canonical', false);
        }

        // Filter on the link count in PHP — HAVING on a withCount subquery isn't portable (SQLite).
        $tags = $query->get()
            ->filter(fn(Tag $t) => $t->links_count <= $max)
            ->sortBy('links_count')
            ->values();

        if ($tags->isEmpty()) {
            $this->info('No tags matched (max-links=' . $max . ').');
            return self::SUCCESS;
        }

        foreach ($tags as $tag) {
            $this->line(sprintf(
                '%s "%s" (%d link%s)%s',
                $dryRun ? 'Would delete' : 'Deleted',
                $tag->name,
                $tag->links_count,
                $tag->links_count === 1 ? '' : 's',
                $tag->is_canonical ? ' [canonical]' : '',
            ));

            if (!$dryRun) {
                $tag->links()->detach();
                $tag->delete();
            }
        }

        $this->newLine();
        $this->info(sprintf(
            '%s %d tag(s)%s.',
            $dryRun ? 'Would delete' : 'Deleted',
            $tags->count(),
            $dryRun ? ' (dry-run)' : '',
        ));

        return self::SUCCESS;
    }

    private function resolveUser(): bool
    {
        $email = $this->option('user-email');
        if ($email !== null) {
            $this->user = User::where('email', $email)->first();
            if (!$this->user) {
                $this->error('No user found for --user-email.');
                return false;
            }
            return true;
        }

        $this->info('You will be asked to select a user who owns the tags.');
        $this->askForUser();
        return true;
    }
}
