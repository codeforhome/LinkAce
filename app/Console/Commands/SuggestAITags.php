<?php

namespace App\Console\Commands;

use App\Models\Link;
use App\Models\User;
use App\Services\AITagging\OnlineTagSuggester;
use App\Services\AITagging\TagApplier;
use App\Services\AITagging\TagSuggestionParser;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class SuggestAITags extends Command
{
    use AsksForUser;

    protected $signature = 'ai:suggest-tags
                        {--limit= : Limit the number of links processed.}
                        {--untagged-only : Only process links without any tags.}
                        {--exclude-broken : Exclude links with broken status.}
                        {--batch=25 : Number of links sent per AI request.}
                        {--model= : Override the OpenRouter model (defaults to config).}
                        {--dry-run : Show what would change without applying.}
                        {--replace : Replace existing tags instead of merging.}
                        {--skip-existing : Skip links that already have tags.}
                        {--no-create-tags : Do not create new tags (unknown tags are skipped).}
                        {--user-email= : Process links owned by this user (skips interactive prompt).}';

    protected $description = 'Suggest and apply tags for links using an online AI provider (OpenRouter / DeepSeek).';

    /** @var array<string,int> */
    private array $totals = [];

    /** @var array{mode:string,dry_run:bool,create_tags:bool,skip_existing:bool} */
    private array $applyOptions = [];

    private ?string $model = null;

    public function handle(
        OnlineTagSuggester $suggester,
        TagSuggestionParser $parser,
        TagApplier $applier,
    ): int {
        if (!$suggester->isEnabled()) {
            $this->error('OpenRouter is not enabled. Set OPENROUTER_ENABLED=true and OPENROUTER_API_KEY in your .env.');
            return self::FAILURE;
        }

        if (!$this->resolveUser()) {
            return self::FAILURE;
        }

        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        if ($limit !== null && $limit <= 0) {
            $this->error('Invalid --limit. Must be a positive integer.');
            return self::INVALID;
        }

        $batchSize = max(1, (int) ($this->option('batch') ?: 25));
        $this->model = $this->option('model') ?: null;
        $this->applyOptions = [
            'mode' => $this->option('replace') ? 'replace' : 'merge',
            'dry_run' => (bool) $this->option('dry-run'),
            'create_tags' => !$this->option('no-create-tags'),
            'skip_existing' => (bool) $this->option('skip-existing'),
        ];

        $this->totals = [
            'records' => 0,
            'links_found' => 0,
            'links_updated' => 0,
            'links_skipped_existing' => 0,
            'links_not_found' => 0,
            'tags_created' => 0,
            'tags_skipped_unknown' => 0,
            'errors' => 0,
            'batches' => 0,
            'api_failures' => 0,
        ];

        $query = Link::query()
            ->byUser($this->user->id)
            ->with(['tags:id,name'])
            ->orderBy('id');

        if ($this->option('untagged-only')) {
            $query->whereDoesntHave('tags');
        }

        if ($this->option('exclude-broken')) {
            $query->where('status', '!=', Link::STATUS_BROKEN);
        }

        $buffer = collect();
        $processed = 0;

        $query->chunkById(200, function ($links) use (&$buffer, &$processed, $limit, $batchSize, $suggester, $parser, $applier) {
            foreach ($links as $link) {
                if ($limit !== null && $processed >= $limit) {
                    return false;
                }

                $buffer->push($link);
                $processed++;

                if ($buffer->count() >= $batchSize) {
                    $this->processBatch($buffer, $suggester, $parser, $applier);
                    $buffer = collect();
                }
            }

            return true;
        });

        if ($buffer->isNotEmpty()) {
            $this->processBatch($buffer, $suggester, $parser, $applier);
        }

        $this->printSummary();

        return self::SUCCESS;
    }

    /**
     * @param Collection<int,Link> $links
     */
    private function processBatch(
        Collection $links,
        OnlineTagSuggester $suggester,
        TagSuggestionParser $parser,
        TagApplier $applier,
    ): void {
        $this->totals['batches']++;
        $this->line(sprintf('Processing batch of %d link(s)...', $links->count()));

        $raw = $suggester->suggestForLinks($links, $this->model);
        if ($raw === null) {
            $this->totals['api_failures']++;
            $this->warn('AI request failed for this batch (see logs). Skipping.');
            return;
        }

        $records = $parser->parse($raw);
        if ($records->isEmpty()) {
            $this->warn('No tag suggestions could be parsed from the AI response for this batch.');
            return;
        }

        $stats = $applier->apply(
            $this->user,
            $records,
            $this->applyOptions,
            fn(string $level, string $message) => match ($level) {
                'warn' => $this->warn($message),
                'error' => $this->error($message),
                default => $this->line($message),
            },
        );

        foreach ($stats as $key => $value) {
            $this->totals[$key] = ($this->totals[$key] ?? 0) + $value;
        }
    }

    private function printSummary(): void
    {
        $dryRun = $this->applyOptions['dry_run'];

        $this->newLine();
        $this->info('AI tag suggestions finished for user: ' . $this->user->email);
        $this->line('Batches sent: ' . $this->totals['batches']);
        if ($this->totals['api_failures'] > 0) {
            $this->line('Batches failed (API): ' . $this->totals['api_failures']);
        }
        $this->line('Suggestions parsed: ' . $this->totals['records']);
        $this->line('Links found: ' . $this->totals['links_found']);
        $this->line('Links updated: ' . $this->totals['links_updated'] . ($dryRun ? ' (dry-run)' : ''));
        $this->line('Links not found: ' . $this->totals['links_not_found']);
        if ($this->applyOptions['skip_existing']) {
            $this->line('Links skipped (already tagged): ' . $this->totals['links_skipped_existing']);
        }
        if ($this->applyOptions['create_tags']) {
            $this->line('Tags created' . ($dryRun ? ' (would)' : '') . ': ' . $this->totals['tags_created']);
        } else {
            $this->line('Unknown tags skipped: ' . $this->totals['tags_skipped_unknown']);
        }
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
}
