<?php

namespace App\Console\Commands;

use App\Models\Tag;
use App\Models\User;
use Illuminate\Console\Command;

class ManageCanonicalTags extends Command
{
    use AsksForUser;

    protected $signature = 'tags:canonical
                        {--add= : Comma-separated tag names to mark as canonical.}
                        {--remove= : Comma-separated tag names to unmark as canonical.}
                        {--list : List the current canonical tags with usage counts.}
                        {--user-email= : Operate on tags owned by this user (skips interactive prompt).}';

    protected $description = 'Manage the small canonical tag set used for browsing (constrains AI tagging).';

    public function handle(): int
    {
        if (!$this->resolveUser()) {
            return self::FAILURE;
        }

        $add = $this->splitNames($this->option('add'));
        $remove = $this->splitNames($this->option('remove'));

        if (empty($add) && empty($remove) && !$this->option('list')) {
            $this->error('Nothing to do. Use --add, --remove, or --list.');
            return self::INVALID;
        }

        if (!empty($add)) {
            $this->setCanonical($add, true);
        }

        if (!empty($remove)) {
            $this->setCanonical($remove, false);
        }

        $this->listCanonical();

        return self::SUCCESS;
    }

    /**
     * @param array<int,string> $names
     */
    private function setCanonical(array $names, bool $value): void
    {
        $verb = $value ? 'Marked' : 'Unmarked';

        foreach ($names as $name) {
            $tag = Tag::query()
                ->byUser($this->user->id)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                ->first();

            if (!$tag) {
                $this->warn('Tag not found, skipping: ' . $name);
                continue;
            }

            if ($tag->is_canonical === $value) {
                $this->line($tag->name . ' already ' . ($value ? 'canonical' : 'non-canonical') . '.');
                continue;
            }

            $tag->is_canonical = $value;
            $tag->save();
            $this->info($verb . ' canonical: ' . $tag->name);
        }
    }

    private function listCanonical(): void
    {
        $tags = Tag::query()
            ->byUser($this->user->id)
            ->canonical()
            ->withCount('links')
            ->orderByDesc('links_count')
            ->orderBy('name')
            ->get();

        $this->newLine();
        if ($tags->isEmpty()) {
            $this->line('No canonical tags set for ' . $this->user->email . '.');
            return;
        }

        $this->info('Canonical tags for ' . $this->user->email . ' (' . $tags->count() . '):');
        $this->table(
            ['Tag', 'Links'],
            $tags->map(fn(Tag $t) => [$t->name, $t->links_count])->all(),
        );
    }

    /**
     * @return array<int,string>
     */
    private function splitNames(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }

    private function resolveUser(): bool
    {
        $userEmail = $this->option('user-email');
        if ($userEmail !== null) {
            $this->user = User::where('email', $userEmail)->first();
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
