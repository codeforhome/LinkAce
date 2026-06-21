<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Tagging\TagMerger;
use Illuminate\Console\Command;

class MergeTags extends Command
{
    use AsksForUser;

    protected $signature = 'tags:merge
                        {--into= : Target tag name to merge into (created if it does not exist).}
                        {--from= : Comma-separated source tag names to merge into the target.}
                        {--dry-run : Show what would change without applying.}
                        {--user-email= : Operate on tags owned by this user (skips interactive prompt).}';

    protected $description = 'Merge one or more tags into a single tag (moves links, deletes the sources).';

    public function handle(TagMerger $merger): int
    {
        $target = trim((string) $this->option('into'));
        if ($target === '') {
            $this->error('--into is required (the target tag name).');
            return self::INVALID;
        }

        $sources = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('from')))));
        if (empty($sources)) {
            $this->error('--from is required (comma-separated source tag names).');
            return self::INVALID;
        }

        if (!$this->resolveUser()) {
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $result = $merger->merge($this->user, $target, $sources, $dryRun);

        foreach ($result['merged'] as $m) {
            $this->line(sprintf(
                '%s "%s" -> "%s" (%d link%s)',
                $dryRun ? 'Would merge' : 'Merged',
                $m['name'],
                $result['target'],
                $m['links_moved'],
                $m['links_moved'] === 1 ? '' : 's',
            ));
        }

        foreach ($result['missing'] as $name) {
            $this->warn('Source tag not found, skipped: ' . $name);
        }

        $this->newLine();
        if ($result['created_target']) {
            $this->line(($dryRun ? 'Would create' : 'Created') . ' target tag: ' . $result['target']);
        }
        $this->info(sprintf(
            '%s %d tag(s) into "%s" affecting %d link(s)%s.',
            $dryRun ? 'Would merge' : 'Merged',
            count($result['merged']),
            $result['target'],
            $result['links_affected'],
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
