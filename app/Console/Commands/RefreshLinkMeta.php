<?php

namespace App\Console\Commands;

use App\Models\Link;
use App\Models\User;
use App\Services\Metadata\LinkMetaRefresher;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Sleep;

class RefreshLinkMeta extends Command
{
    protected $signature = 'links:refresh-meta
                        {--user-email= : Only refresh links owned by this user.}
                        {--domain= : Comma-separated host filter, e.g. x.com,twitter.com,facebook.com.}
                        {--from-id= : Only links with id >= this value.}
                        {--to-id= : Only links with id <= this value.}
                        {--limit= : Maximum number of links to process.}
                        {--force : Refresh all matched links, not just weak/placeholder ones.}
                        {--dry-run : Show what would change without saving.}
                        {--no-wait : Do not throttle between requests.}';

    protected $description = 'Re-fetch title/description/thumbnail for existing links (e.g. old Twitter/X, Facebook).';

    /** @var array<int,string> */
    private array $domains = [];

    private array $stats = [
        'scanned' => 0,
        'refreshed' => 0,
        'skipped_current_ok' => 0,
        'skipped_fetch_failed' => 0,
        'skipped_fresh_weak' => 0,
        'skipped_no_change' => 0,
    ];

    public function handle(LinkMetaRefresher $refresher): int
    {
        $force = (bool) $this->option('force');
        $dryRun = (bool) $this->option('dry-run');

        $this->domains = array_values(array_filter(array_map(
            fn($d) => mb_strtolower(trim($d)),
            explode(',', (string) $this->option('domain')),
        )));

        $users = $this->resolveUsers();
        if ($users === null) {
            return self::FAILURE;
        }

        $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;

        foreach ($users as $user) {
            $this->processUser($user, $refresher, $force, $dryRun, $limit);
            if ($limit !== null && $this->stats['scanned'] >= $limit) {
                break;
            }
        }

        $this->printSummary($dryRun);

        return self::SUCCESS;
    }

    /**
     * @return iterable<User>|null
     */
    private function resolveUsers(): ?iterable
    {
        $email = $this->option('user-email');
        if ($email !== null) {
            $user = User::where('email', $email)->first();
            if (!$user) {
                $this->error('No user found for --user-email.');
                return null;
            }
            return [$user];
        }

        return User::query()->notBlocked()->get();
    }

    private function processUser(User $user, LinkMetaRefresher $refresher, bool $force, bool $dryRun, ?int $limit): void
    {
        $query = Link::query()
            ->where('user_id', $user->id)
            ->where('url', 'like', 'http%')
            ->orderBy('id');

        if (($fromId = $this->option('from-id')) !== null) {
            $query->where('id', '>=', (int) $fromId);
        }
        if (($toId = $this->option('to-id')) !== null) {
            $query->where('id', '<=', (int) $toId);
        }
        $this->applyDomainPrefilter($query);

        foreach ($query->cursor() as $link) {
            if ($limit !== null && $this->stats['scanned'] >= $limit) {
                return;
            }

            // Exact host check (the SQL LIKE is only a loose prefilter).
            if (!$this->hostMatches($link->url)) {
                continue;
            }

            $this->stats['scanned']++;

            $result = $refresher->refresh($link, $force, $dryRun);

            if ($result['changed']) {
                $this->stats['refreshed']++;
                $this->line(sprintf(
                    '#%d %s: "%s" -> "%s"',
                    $link->id,
                    $dryRun ? '[dry-run]' : 'refreshed',
                    mb_substr((string) $result['old']['title'], 0, 40),
                    mb_substr((string) $result['new']['title'], 0, 40),
                ));
            } else {
                $key = 'skipped_' . $result['reason'];
                $this->stats[$key] = ($this->stats[$key] ?? 0) + 1;
            }

            // Throttle outgoing requests, but only when we actually hit the network (a refresh
            // attempt) and not during dry runs.
            if (!$dryRun && !$this->option('no-wait') && $result['reason'] !== 'current_ok') {
                Sleep::sleep(1);
            }
        }
    }

    private function applyDomainPrefilter(Builder $query): void
    {
        if (empty($this->domains)) {
            return;
        }

        $query->where(function (Builder $q) {
            foreach ($this->domains as $domain) {
                $q->orWhere('url', 'like', '%' . $domain . '%');
            }
        });
    }

    private function hostMatches(string $url): bool
    {
        if (empty($this->domains)) {
            return true;
        }

        $host = mb_strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return false;
        }

        foreach ($this->domains as $domain) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return true;
            }
        }

        return false;
    }

    private function printSummary(bool $dryRun): void
    {
        $this->newLine();
        $this->info('Metadata refresh ' . ($dryRun ? '(dry-run) ' : '') . 'finished.');
        $this->line('Scanned: ' . $this->stats['scanned']);
        $this->line('Refreshed: ' . $this->stats['refreshed'] . ($dryRun ? ' (would)' : ''));
        $this->line('Skipped (already ok): ' . $this->stats['skipped_current_ok']);
        $this->line('Skipped (fetch failed): ' . $this->stats['skipped_fetch_failed']);
        $this->line('Skipped (still weak): ' . $this->stats['skipped_fresh_weak']);
        $this->line('Skipped (no change): ' . $this->stats['skipped_no_change']);
    }
}
