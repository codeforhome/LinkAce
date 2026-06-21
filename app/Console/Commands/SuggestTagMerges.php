<?php

namespace App\Console\Commands;

use App\Models\Tag;
use App\Models\User;
use App\Services\AITagging\OpenRouterClient;
use App\Services\Tagging\TagMerger;
use Illuminate\Console\Command;

class SuggestTagMerges extends Command
{
    use AsksForUser;

    protected $signature = 'tags:suggest-merges
                        {--apply : Apply the suggested merges (default: only print them).}
                        {--model= : Override the OpenRouter model.}
                        {--user-email= : Operate on tags owned by this user (skips interactive prompt).}';

    protected $description = 'Ask the AI to propose synonym/near-duplicate tag merges to reduce sprawl.';

    public function handle(OpenRouterClient $client, TagMerger $merger): int
    {
        if (!$client->isConfigured()) {
            $this->error('OpenRouter is not enabled. Set OPENROUTER_ENABLED=true and OPENROUTER_API_KEY.');
            return self::FAILURE;
        }

        if (!$this->resolveUser()) {
            return self::FAILURE;
        }

        $tags = Tag::query()
            ->byUser($this->user->id)
            ->withCount('links')
            ->orderByDesc('links_count')
            ->get(['id', 'name'])
            ->map(fn(Tag $t) => ['name' => $t->name, 'count' => $t->links_count]);

        if ($tags->count() < 2) {
            $this->info('Not enough tags to suggest merges.');
            return self::SUCCESS;
        }

        $groups = $this->requestSuggestions($client, $tags->all());
        if ($groups === null) {
            $this->error('The AI did not return usable suggestions (see logs).');
            return self::FAILURE;
        }

        if (empty($groups)) {
            $this->info('No merges suggested — your tags look distinct.');
            return self::SUCCESS;
        }

        $apply = (bool) $this->option('apply');

        foreach ($groups as $group) {
            $canonical = trim((string) ($group['canonical'] ?? ''));
            $sources = array_values(array_filter(array_map('trim', (array) ($group['merge'] ?? []))));
            // Don't count the canonical itself as a source.
            $sources = array_values(array_filter($sources, fn($s) => mb_strtolower($s) !== mb_strtolower($canonical)));

            if ($canonical === '' || empty($sources)) {
                continue;
            }

            if ($apply) {
                $result = $merger->merge($this->user, $canonical, $sources, false);
                $this->info(sprintf('Merged %d into "%s" (%d links).', count($result['merged']), $canonical, $result['links_affected']));
            } else {
                $this->line(sprintf('%s  <=  %s', $canonical, implode(', ', $sources)));
                $this->line(sprintf(
                    '    php artisan tags:merge --user-email=%s --into=%s --from=%s --dry-run',
                    $this->user->email,
                    escapeshellarg($canonical),
                    escapeshellarg(implode(',', $sources)),
                ));
            }
        }

        if (!$apply) {
            $this->newLine();
            $this->info('Review the groups above, then run the printed commands (drop --dry-run to apply), or re-run with --apply.');
        }

        return self::SUCCESS;
    }

    /**
     * @param array<int,array{name:string,count:int}> $tags
     * @return array<int,array{canonical:string,merge:array<int,string>}>|null
     */
    private function requestSuggestions(OpenRouterClient $client, array $tags): ?array
    {
        $system = <<<TEXT
You help consolidate a messy bookmark tag list. You receive a JSON array of {name, count}.
Identify groups of synonyms / near-duplicates / trivial variants (e.g. "js" & "javascript",
"prompt engineering" & "prompt-engineering", singular/plural). For each group choose ONE
canonical name (prefer the clearest, most-used existing tag).

Output rules (critical):
- Return ONLY a JSON array, no prose or code fences.
- Each element: {"canonical": "<tag>", "merge": ["<tag>", "<tag>", ...]}
- Only include groups that actually have duplicates (2+ members). Do not merge distinct concepts.
TEXT;

        $payload = json_encode($tags, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]';

        $content = $client->chat([
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $payload],
        ], $this->option('model') ?: null);

        if ($content === null) {
            return null;
        }

        $content = trim($content);
        if (preg_match('/```(?:json)?\s*(.+?)\s*```/s', $content, $m) === 1) {
            $content = trim($m[1]);
        }

        $decoded = json_decode($content, true);

        return is_array($decoded) ? $decoded : null;
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
