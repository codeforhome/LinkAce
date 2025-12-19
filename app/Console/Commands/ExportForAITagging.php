<?php

namespace App\Console\Commands;

use App\Models\Link;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\File;

class ExportForAITagging extends Command
{
    use AsksForUser;

    protected $signature = 'links:export-for-ai
                        {--format=json : Output format (json, csv, markdown).}
                        {--limit= : Limit the number of exported links.}
                        {--untagged-only : Export only links without any tags.}
                        {--exclude-broken : Exclude links with broken status.}
                        {--output= : Write output to a file (relative paths are stored in storage/).}
                        {--user-email= : Export links owned by this user (skips interactive prompt).}';

    protected $description = 'Export links in an AI-friendly format for offline tag suggestions.';

    public function handle(): int
    {
        $format = strtolower((string) $this->option('format'));
        if (!in_array($format, ['json', 'csv', 'markdown'], true)) {
            $this->error('Invalid --format. Allowed: json, csv, markdown.');
            return self::INVALID;
        }

        if (!$this->resolveUser()) {
            return self::FAILURE;
        }

        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        if ($limit !== null && $limit <= 0) {
            $this->error('Invalid --limit. Must be a positive integer.');
            return self::INVALID;
        }

        $handle = $this->openOutputHandle($this->option('output'));
        if ($handle === null) {
            return self::FAILURE;
        }

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

        return match ($format) {
            'json' => $this->exportJson($query, $handle, $limit),
            'csv' => $this->exportCsv($query, $handle, $limit),
            'markdown' => $this->exportMarkdown($query, $handle, $limit),
        };
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

        $this->info('You will be asked to select a user who owns the links to export.');
        $this->askForUser();
        return true;
    }

    /**
     * @param string|null $output
     * @return resource|null
     */
    private function openOutputHandle(?string $output): mixed
    {
        if ($output === null) {
            return fopen('php://output', 'wb');
        }

        $path = $this->resolvePath($output);
        File::ensureDirectoryExists(dirname($path));
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            $this->error('Could not open output file for writing: ' . $path);
            return null;
        }

        $this->info('Writing export to "' . $path . '"');
        return $handle;
    }

    private function resolvePath(string $path): string
    {
        return str_starts_with($path, '/') ? $path : storage_path($path);
    }

    /**
     * @param Builder $query
     * @param resource $handle
     */
    private function exportJson(Builder $query, $handle, ?int $limit): int
    {
        fwrite($handle, '[');
        $isFirst = true;
        $exported = 0;

        $query->chunkById(200, function ($links) use ($handle, &$isFirst, &$exported, $limit) {
            foreach ($links as $link) {
                if ($limit !== null && $exported >= $limit) {
                    return false;
                }

                $payload = [
                    'id' => $link->id,
                    'url' => $link->url,
                    'title' => $link->title,
                    'description' => $link->description,
                    'current_tags' => $link->tags->pluck('name')->values()->all(),
                    'domain' => $link->domainOfURL(),
                    'status' => $this->mapStatus($link->status),
                    'created_at' => optional($link->created_at)->toIso8601String(),
                    'last_checked_at' => optional($link->last_checked_at)->toIso8601String(),
                ];

                $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if ($json === false) {
                    $this->warn('Skipping link #' . $link->id . ': could not JSON encode payload.');
                    continue;
                }

                if (!$isFirst) {
                    fwrite($handle, ',');
                }
                fwrite($handle, $json);
                $isFirst = false;
                $exported++;
            }

            return true;
        });

        fwrite($handle, "]\n");
        return self::SUCCESS;
    }

    /**
     * @param Builder $query
     * @param resource $handle
     */
    private function exportCsv(Builder $query, $handle, ?int $limit): int
    {
        fputcsv($handle, [
            'id',
            'url',
            'title',
            'description',
            'current_tags',
            'domain',
            'status',
            'created_at',
            'last_checked_at',
        ]);

        $exported = 0;

        $query->chunkById(500, function ($links) use ($handle, &$exported, $limit) {
            foreach ($links as $link) {
                if ($limit !== null && $exported >= $limit) {
                    return false;
                }

                fputcsv($handle, [
                    $link->id,
                    $link->url,
                    $link->title,
                    $link->description,
                    $link->tags->pluck('name')->implode('|'),
                    $link->domainOfURL(),
                    $this->mapStatus($link->status),
                    optional($link->created_at)->toIso8601String(),
                    optional($link->last_checked_at)->toIso8601String(),
                ]);

                $exported++;
            }

            return true;
        });

        return self::SUCCESS;
    }

    /**
     * @param Builder $query
     * @param resource $handle
     */
    private function exportMarkdown(Builder $query, $handle, ?int $limit): int
    {
        fwrite($handle, "# LinkAce Export for Offline AI Tagging\n\n");
        fwrite($handle, "- User: {$this->user->email}\n");
        fwrite($handle, "- Generated: " . now()->toIso8601String() . "\n\n");

        $exported = 0;

        $query->chunkById(200, function ($links) use ($handle, &$exported, $limit) {
            foreach ($links as $link) {
                if ($limit !== null && $exported >= $limit) {
                    return false;
                }

                $tags = $link->tags->pluck('name')->values()->all();
                $tagsText = empty($tags) ? '(none)' : implode(', ', $tags);

                fwrite($handle, "## {$link->id} {$link->title}\n\n");
                fwrite($handle, "- URL: {$link->url}\n");
                fwrite($handle, "- Domain: {$link->domainOfURL()}\n");
                fwrite($handle, "- Status: {$this->mapStatus($link->status)}\n");
                fwrite($handle, "- Current tags: {$tagsText}\n");
                if ($link->description) {
                    fwrite($handle, "- Description: {$link->description}\n");
                }
                fwrite($handle, "\n");

                $exported++;
            }

            return true;
        });

        return self::SUCCESS;
    }

    private function mapStatus(?int $status): string
    {
        return match ($status) {
            Link::STATUS_OK => 'ok',
            Link::STATUS_MOVED => 'moved',
            Link::STATUS_BROKEN => 'broken',
            default => 'unknown',
        };
    }
}
