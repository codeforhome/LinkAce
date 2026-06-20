<?php

namespace Tests\Models;

use App\Models\Link;
use App\Models\Tag;
use App\Models\User;
use App\Services\AITagging\TagApplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class TagApplierTest extends TestCase
{
    use RefreshDatabase;

    private TagApplier $applier;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->applier = new TagApplier();
        $this->user = User::factory()->create();
    }

    /**
     * @param array<int,array{id?:int,url?:string,tags:array<int,string>}> $rows
     */
    private function records(array $rows): Collection
    {
        return collect($rows)->map(fn($r) => [
            'raw' => json_encode($r),
            'id' => $r['id'] ?? null,
            'url' => $r['url'] ?? null,
            'tags' => $r['tags'],
        ]);
    }

    public function test_merge_creates_and_attaches_tags(): void
    {
        $link = Link::factory()->create(['user_id' => $this->user->id]);

        $stats = $this->applier->apply(
            $this->user,
            $this->records([['id' => $link->id, 'tags' => ['php', 'laravel']]]),
            ['mode' => 'merge', 'create_tags' => true],
        );

        $this->assertSame(1, $stats['links_updated']);
        $this->assertSame(2, $stats['tags_created']);
        $this->assertEqualsCanonicalizing(['php', 'laravel'], $link->fresh()->tags->pluck('name')->all());
    }

    public function test_replace_syncs_tags(): void
    {
        $link = Link::factory()->create(['user_id' => $this->user->id]);
        $old = Tag::factory()->create(['user_id' => $this->user->id, 'name' => 'old']);
        $link->tags()->attach($old->id);

        $this->applier->apply(
            $this->user,
            $this->records([['id' => $link->id, 'tags' => ['new']]]),
            ['mode' => 'replace', 'create_tags' => true],
        );

        $this->assertEqualsCanonicalizing(['new'], $link->fresh()->tags->pluck('name')->all());
    }

    public function test_dry_run_writes_nothing(): void
    {
        $link = Link::factory()->create(['user_id' => $this->user->id]);

        $stats = $this->applier->apply(
            $this->user,
            $this->records([['id' => $link->id, 'tags' => ['php']]]),
            ['dry_run' => true, 'create_tags' => true],
        );

        $this->assertSame(1, $stats['links_updated']); // counted as "would update"
        $this->assertCount(0, $link->fresh()->tags);
        $this->assertNull($link->fresh()->ai_tagged_at);
        $this->assertSame(0, Tag::count());
    }

    public function test_max_tags_truncates_applied_tags(): void
    {
        $link = Link::factory()->create(['user_id' => $this->user->id]);

        $this->applier->apply(
            $this->user,
            $this->records([['id' => $link->id, 'tags' => ['a', 'b', 'c', 'd']]]),
            ['mode' => 'merge', 'create_tags' => true, 'max_tags' => 2],
        );

        $fresh = $link->fresh();
        $this->assertCount(2, $fresh->tags);
        $this->assertEqualsCanonicalizing(['a', 'b'], $fresh->tags->pluck('name')->all());
    }

    public function test_real_apply_stamps_ai_tagged_at_and_stores_full_raw_tags(): void
    {
        $link = Link::factory()->create(['user_id' => $this->user->id]);

        $this->applier->apply(
            $this->user,
            $this->records([['id' => $link->id, 'tags' => ['a', 'b', 'c', 'd']]]),
            ['mode' => 'merge', 'create_tags' => true, 'max_tags' => 2],
        );

        $fresh = $link->fresh();
        $this->assertNotNull($fresh->ai_tagged_at);
        // raw_tags keeps the FULL suggestion even though only 2 were applied.
        $this->assertEqualsCanonicalizing(['a', 'b', 'c', 'd'], $fresh->raw_tags);
    }

    public function test_allowed_tags_restricts_to_vocabulary(): void
    {
        $link = Link::factory()->create(['user_id' => $this->user->id]);
        // Both exist; 'php' is NOT in the allowed set so it must be dropped even though it exists.
        Tag::factory()->create(['user_id' => $this->user->id, 'name' => 'php']);
        Tag::factory()->create(['user_id' => $this->user->id, 'name' => 'laravel']);

        $this->applier->apply(
            $this->user,
            $this->records([['id' => $link->id, 'tags' => ['php', 'laravel']]]),
            ['mode' => 'merge', 'create_tags' => false, 'allowed_tags' => ['laravel', 'investing']],
        );

        $fresh = $link->fresh();
        $this->assertEqualsCanonicalizing(['laravel'], $fresh->tags->pluck('name')->all());
        // raw_tags still records the full suggestion, including the filtered-out tag.
        $this->assertEqualsCanonicalizing(['php', 'laravel'], $fresh->raw_tags);
    }

    public function test_create_tags_false_skips_unknown_tags(): void
    {
        $link = Link::factory()->create(['user_id' => $this->user->id]);
        $known = Tag::factory()->create(['user_id' => $this->user->id, 'name' => 'known']);

        $stats = $this->applier->apply(
            $this->user,
            $this->records([['id' => $link->id, 'tags' => ['known', 'unknown']]]),
            ['mode' => 'merge', 'create_tags' => false],
        );

        $this->assertSame(1, $stats['tags_skipped_unknown']);
        $this->assertEqualsCanonicalizing(['known'], $link->fresh()->tags->pluck('name')->all());
    }

    public function test_skip_existing_leaves_tagged_links_untouched(): void
    {
        $link = Link::factory()->create(['user_id' => $this->user->id]);
        $existing = Tag::factory()->create(['user_id' => $this->user->id, 'name' => 'existing']);
        $link->tags()->attach($existing->id);

        $stats = $this->applier->apply(
            $this->user,
            $this->records([['id' => $link->id, 'tags' => ['new']]]),
            ['mode' => 'merge', 'create_tags' => true, 'skip_existing' => true],
        );

        $this->assertSame(1, $stats['links_skipped_existing']);
        $this->assertEqualsCanonicalizing(['existing'], $link->fresh()->tags->pluck('name')->all());
    }
}
