<?php

namespace Tests\Commands;

use App\Models\Link;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RefreshLinkMetaCommandTest extends TestCase
{
    use RefreshDatabase;

    private function goodHtml(string $title = 'Fresh Title', string $desc = 'Fresh description'): string
    {
        return '<!DOCTYPE html><head>' .
            '<title>' . $title . '</title>' .
            '<meta name="description" content="' . $desc . '">' .
            '</head></html>';
    }

    public function test_refreshes_weak_link_by_default(): void
    {
        Http::fake(['example.com/*' => Http::response($this->goodHtml())]);
        $user = User::factory()->create();
        // Weak: title equals the host, no description.
        $link = Link::factory()->for($user)->create([
            'url' => 'https://example.com/page',
            'title' => 'example.com',
            'description' => null,
        ]);

        $this->artisan('links:refresh-meta', ['--user-email' => $user->email, '--no-wait' => true]);

        $this->assertDatabaseHas('links', ['id' => $link->id, 'title' => 'Fresh Title', 'description' => 'Fresh description']);
    }

    public function test_leaves_good_link_untouched_without_force(): void
    {
        Http::fake(['example.com/*' => Http::response($this->goodHtml())]);
        $user = User::factory()->create();
        $link = Link::factory()->for($user)->create([
            'url' => 'https://example.com/page',
            'title' => 'My Curated Title',
            'description' => 'My own description',
        ]);

        $this->artisan('links:refresh-meta', ['--user-email' => $user->email, '--no-wait' => true]);

        // Unchanged — good metadata must not be clobbered.
        $this->assertDatabaseHas('links', ['id' => $link->id, 'title' => 'My Curated Title']);
    }

    public function test_force_overwrites_good_link(): void
    {
        Http::fake(['example.com/*' => Http::response($this->goodHtml())]);
        $user = User::factory()->create();
        $link = Link::factory()->for($user)->create([
            'url' => 'https://example.com/page',
            'title' => 'My Curated Title',
            'description' => 'My own description',
        ]);

        $this->artisan('links:refresh-meta', ['--user-email' => $user->email, '--force' => true, '--no-wait' => true]);

        $this->assertDatabaseHas('links', ['id' => $link->id, 'title' => 'Fresh Title']);
    }

    public function test_dry_run_changes_nothing(): void
    {
        Http::fake(['example.com/*' => Http::response($this->goodHtml())]);
        $user = User::factory()->create();
        $link = Link::factory()->for($user)->create([
            'url' => 'https://example.com/page',
            'title' => 'example.com',
            'description' => null,
        ]);

        $this->artisan('links:refresh-meta', ['--user-email' => $user->email, '--dry-run' => true, '--no-wait' => true]);

        $this->assertDatabaseHas('links', ['id' => $link->id, 'title' => 'example.com']);
    }

    public function test_domain_filter_matches_host_exactly_not_substring(): void
    {
        Http::fake([
            'x.com/*' => Http::response($this->goodHtml('Tweet Title')),
            'netflix.com/*' => Http::response($this->goodHtml('Netflix Title')),
        ]);
        $user = User::factory()->create();
        $tweet = Link::factory()->for($user)->create(['url' => 'https://x.com/jack/status/20', 'title' => 'x.com', 'description' => null]);
        // netflix.com contains the substring "x.com" but must NOT be treated as host x.com.
        $netflix = Link::factory()->for($user)->create(['url' => 'https://netflix.com/title/123', 'title' => 'netflix.com', 'description' => null]);

        $this->artisan('links:refresh-meta', ['--user-email' => $user->email, '--domain' => 'x.com', '--no-wait' => true]);

        $this->assertDatabaseHas('links', ['id' => $tweet->id, 'title' => 'Tweet Title']);
        $this->assertDatabaseHas('links', ['id' => $netflix->id, 'title' => 'netflix.com']); // untouched
    }

    public function test_does_not_overwrite_with_fresh_junk(): void
    {
        // The page itself is a bot-wall — the fresh result is weak, so the original is kept.
        Http::fake(['example.com/*' => Http::response('<!DOCTYPE html><head><title>Just a moment...</title></head></html>')]);
        $user = User::factory()->create();
        $link = Link::factory()->for($user)->create([
            'url' => 'https://example.com/page',
            'title' => 'example.com',
            'description' => null,
        ]);

        $this->artisan('links:refresh-meta', ['--user-email' => $user->email, '--no-wait' => true]);

        $this->assertDatabaseHas('links', ['id' => $link->id, 'title' => 'example.com']); // unchanged
    }
}
