<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AITagging\TagApplier;
use App\Services\AITagging\TagSuggestionParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ImportAITags extends Command
{
    use AsksForUser;

    protected $signature = 'ai:import-tags
                        {filepath : AI response file to import, use absolute paths if stored outside of LinkAce}
                        {--dry-run : Show what would change without applying.}
                        {--merge : Merge tags with existing tags (default).}
                        {--replace : Replace existing tags with the suggested tags.}
                        {--skip-existing : Skip links that already have tags.}
                        {--no-create-tags : Do not create new tags (unknown tags will be skipped).}
                        {--user-email= : Import tags for links owned by this user (skips interactive prompt).}';

    protected $description = 'Import AI-generated tag suggestions and apply them to links.';

    public function handle(): int
    {
        if ($this->option('merge') && $this->option('replace')) {
            $this->error('Use only one of --merge or --replace.');
            return self::INVALID;
        }

        if (!$this->resolveUser()) {
            return self::FAILURE;
        }

        $filepath = $this->argument('filepath');
        $path = $this->resolvePath($filepath);
        if (!File::exists($path)) {
            $this->error('File not found: ' . $path);
            return self::FAILURE;
        }

        $raw = File::get($path);
        if ($raw === '' || $raw === null) {
            $this->error('The provided file is empty or could not be read.');
            return self::FAILURE;
        }

        $records = app(TagSuggestionParser::class)->parse($raw);
        if ($records->isEmpty()) {
            $this->warn('No tag suggestions found in input.');
            return self::SUCCESS;
        }

        $mode = $this->option('replace') ? 'replace' : 'merge';
        $dryRun = (bool) $this->option('dry-run');
        $createTags = !$this->option('no-create-tags');
        $skipExisting = (bool) $this->option('skip-existing');

        $stats = app(TagApplier::class)->apply(
            $this->user,
            $records,
            [
                'mode' => $mode,
                'dry_run' => $dryRun,
                'create_tags' => $createTags,
                'skip_existing' => $skipExisting,
            ],
            fn(string $level, string $message) => match ($level) {
                'warn' => $this->warn($message),
                'error' => $this->error($message),
                default => $this->line($message),
            },
        );

        $this->newLine();
        $this->info('Import finished for user: ' . $this->user->email);
        $this->line('Records: ' . $stats['records']);
        $this->line('Links found: ' . $stats['links_found']);
        $this->line('Links updated: ' . $stats['links_updated'] . ($dryRun ? ' (dry-run)' : ''));
        $this->line('Links not found: ' . $stats['links_not_found']);
        if ($skipExisting) {
            $this->line('Links skipped (already tagged): ' . $stats['links_skipped_existing']);
        }
        if ($createTags) {
            $this->line('Tags created: ' . $stats['tags_created']);
        } else {
            $this->line('Unknown tags skipped: ' . $stats['tags_skipped_unknown']);
        }

        return self::SUCCESS;
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

        $this->info('You will be asked to select a user who owns the links to tag.');
        $this->askForUser();
        return true;
    }

    private function resolvePath(string $path): string
    {
        return str_starts_with($path, '/') ? $path : storage_path($path);
    }
}
