<?php

namespace App\Console\Commands;

use App\Enums\ModelAttribute;
use App\Models\Link;
use App\Models\Tag;
use App\Models\User;
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

            $link = $this->resolveLink($record);
            if (!$link) {
                $stats['links_not_found']++;
                $this->warn('Skipping: link not found for input: ' . $record['raw']);
                continue;
            }

            $stats['links_found']++;

            if ($skipExisting && $link->tags()->exists()) {
                $stats['links_skipped_existing']++;
                continue;
            }

            $tagNames = $this->normalizeTagNames($record['tags']);
            if (empty($tagNames)) {
                $this->warn('Skipping link #' . $link->id . ': no valid tags.');
                continue;
            }

            $tagIds = [];
            foreach ($tagNames as $tagName) {
                $tag = $this->findTagForUser($tagName);
                if (!$tag) {
                    if (!$createTags) {
                        $stats['tags_skipped_unknown']++;
                        continue;
                    }

                    $tag = $this->createTagForUser($tagName);
                    if (!$tag) {
                        $stats['errors']++;
                        continue;
                    }

                    $stats['tags_created']++;
                }

                $tagIds[] = $tag->id;
            }

            $tagIds = array_values(array_unique($tagIds));
            if (empty($tagIds)) {
                $this->warn('Skipping link #' . $link->id . ': no tags could be resolved/created.');
                continue;
            }

            if ($dryRun) {
                $this->line(sprintf(
                    'Would %s tags on link #%d (%s): %s',
                    $mode,
                    $link->id,
                    $link->url,
                    implode(', ', $tagNames),
                ));
                $stats['links_updated']++;
                continue;
            }

            if ($mode === 'replace') {
                $link->tags()->sync($tagIds);
            } else {
                $link->tags()->syncWithoutDetaching($tagIds);
            }

            $stats['links_updated']++;
        }

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

    /**
     * @param array{raw:string,id:int|null,url:string|null,tags:array<int,string>} $record
     */
    private function resolveLink(array $record): ?Link
    {
        if (!empty($record['id'])) {
            return Link::query()
                ->byUser($this->user->id)
                ->whereKey($record['id'])
                ->first();
        }

        if (!empty($record['url'])) {
            return Link::query()
                ->byUser($this->user->id)
                ->where('url', $record['url'])
                ->first();
        }

        return null;
    }

    private function findTagForUser(string $name): ?Tag
    {
        $nameLower = mb_strtolower($name);

        return Tag::query()
            ->byUser($this->user->id)
            ->whereRaw('LOWER(name) = ?', [$nameLower])
            ->first();
    }

    private function createTagForUser(string $name): ?Tag
    {
        $visibility = usersettings('tags_default_visibility', $this->user->id) ?? ModelAttribute::VISIBILITY_PRIVATE;

        try {
            return Tag::create([
                'user_id' => $this->user->id,
                'name' => $name,
                'visibility' => (int) $visibility,
            ]);
        } catch (\Throwable $e) {
            $this->error('Failed to create tag "' . $name . '": ' . $e->getMessage());
            return null;
        }
    }
}
