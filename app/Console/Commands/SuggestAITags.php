<?php

namespace App\Console\Commands;

use App\Models\Link;
use App\Models\Tag;
use App\Models\User;
use App\Services\AITagging\OnlineTagSuggester;
use App\Services\AITagging\TagApplier;
use App\Services\AITagging\TagSuggestionParser;
use Carbon\Carbon;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class SuggestAITags extends Command
{
    use AsksForUser;

    protected $signature = 'ai:suggest-tags
                        {--limit= : Limit the number of links processed.}
                        {--untagged-only : Only process links without any tags.}
                        {--exclude-broken : Exclude links with broken status.}
                        {--from-id= : Only process links with id >= this value.}
                        {--to-id= : Only process links with id <= this value.}
                        {--created-after= : Only links created on/after this date (e.g. 2025-01-01).}
                        {--created-before= : Only links created on/before this date (e.g. 2025-01-01).}
                        {--skip-ai-tagged : Skip links that were already AI-tagged (incremental re-runs).}
                        {--with-vocabulary : Inject your existing tags so the model reuses them (for re-tagging).}
                        {--existing-tags-only : Restrict suggestions to your existing tags (implies --with-vocabulary, creates no new tags).}
                        {--canonical-only : Restrict suggestions to your canonical tags only (implies --existing-tags-only).}
                        {--max-tags= : Cap tags applied per link (defaults to 3 in --canonical-only mode).}
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

    /** @var array{mode:string,dry_run:bool,create_tags:bool,skip_existing:bool,max_tags:int|null} */
    private array $applyOptions = [];

    private ?string $model = null;

    /** @var array<int,string> */
    private array $vocabulary = [];

    private bool $existingOnly = false;

    private bool $canonicalOnly = false;

    private ?int $maxTags = null;

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

        $createdAfter = $this->parseDateOption('created-after');
        $createdBefore = $this->parseDateOption('created-before');
        if ($createdAfter === false || $createdBefore === false) {
            return self::INVALID;
        }

        $batchSize = max(1, (int) ($this->option('batch') ?: 25));
        $this->model = $this->option('model') ?: null;

        // canonical-only is the strictest form of existing-tags-only.
        $this->canonicalOnly = (bool) $this->option('canonical-only');
        $this->existingOnly = $this->canonicalOnly || (bool) $this->option('existing-tags-only');
        $withVocabulary = $this->existingOnly || (bool) $this->option('with-vocabulary');

        // Resolve the per-link tag cap: explicit --max-tags wins; otherwise canonical mode defaults
        // to the configured browsing-bucket cap.
        if ($this->option('max-tags') !== null) {
            $this->maxTags = max(1, (int) $this->option('max-tags'));
        } elseif ($this->canonicalOnly) {
            $this->maxTags = max(1, (int) config('services.openrouter.canonical_max_tags', 3));
        }

        $this->applyOptions = [
            'mode' => $this->option('replace') ? 'replace' : 'merge',
            'dry_run' => (bool) $this->option('dry-run'),
            // existing-tags-only must never create tags, regardless of --no-create-tags.
            'create_tags' => !$this->existingOnly && !$this->option('no-create-tags'),
            'skip_existing' => (bool) $this->option('skip-existing'),
            'max_tags' => $this->maxTags,
        ];

        if ($withVocabulary) {
            $this->vocabulary = $this->loadVocabulary();
            $label = $this->canonicalOnly ? 'canonical' : 'existing';
            if (empty($this->vocabulary)) {
                if ($this->canonicalOnly) {
                    $this->error('No canonical tags found. Mark some with: php artisan tags:canonical --add=...');
                    return self::FAILURE;
                }
                $this->warn('No existing tags found for this user; proceeding without a vocabulary.');
            } else {
                $this->line('Using ' . count($this->vocabulary) . ' ' . $label . ' tag(s) as vocabulary.');
            }

            // In existing-only/canonical mode, hard-restrict applied tags to the vocabulary —
            // the prompt asks the model to comply, but this guarantees it regardless.
            if ($this->existingOnly) {
                $this->applyOptions['allowed_tags'] = $this->vocabulary;
            }
        }

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

        if (($fromId = $this->option('from-id')) !== null) {
            $query->where('id', '>=', (int) $fromId);
        }

        if (($toId = $this->option('to-id')) !== null) {
            $query->where('id', '<=', (int) $toId);
        }

        if ($createdAfter !== null) {
            $query->where('created_at', '>=', $createdAfter);
        }

        if ($createdBefore !== null) {
            $query->where('created_at', '<=', $createdBefore);
        }

        if ($this->option('skip-ai-tagged')) {
            $query->whereNull('ai_tagged_at');
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

        $raw = $suggester->suggestForLinks($links, $this->model, $this->vocabulary, $this->existingOnly, $this->maxTags);
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

    /**
     * Parse a date option. Returns null if unset, a Carbon instance if valid,
     * or false if the value could not be parsed (caller should abort).
     */
    private function parseDateOption(string $option): Carbon|null|false
    {
        $value = $this->option($option);
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (InvalidFormatException $e) {
            $this->error(sprintf('Invalid --%s value "%s". Use a date like 2025-01-01.', $option, $value));
            return false;
        }
    }

    /**
     * Load the user's existing tag names, most-used first, capped for token control.
     *
     * @return array<int,string>
     */
    private function loadVocabulary(): array
    {
        $cap = (int) config('services.openrouter.vocab_limit', 300);

        $query = Tag::query()
            ->byUser($this->user->id)
            ->withCount('links')
            ->orderByDesc('links_count')
            ->orderBy('name')
            ->limit(max(1, $cap));

        if ($this->canonicalOnly) {
            $query->canonical();
        }

        return $query->pluck('name')->all();
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
