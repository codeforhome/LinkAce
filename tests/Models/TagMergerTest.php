<?php

namespace Tests\Models;

use App\Models\Link;
use App\Models\Tag;
use App\Models\User;
use App\Services\Tagging\TagMerger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TagMergerTest extends TestCase
{
    use RefreshDatabase;

    private TagMerger $merger;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->merger = new TagMerger();
        $this->user = User::factory()->create();
    }

    public function test_merge_moves_links_and_deletes_source(): void
    {
        $target = Tag::factory()->for($this->user)->create(['name' => 'javascript']);
        $source = Tag::factory()->for($this->user)->create(['name' => 'js']);
        $link = Link::factory()->for($this->user)->create();
        $link->tags()->attach($source->id);

        $result = $this->merger->merge($this->user, 'javascript', ['js']);

        $this->assertSame(1, $result['links_affected']);
        $this->assertEqualsCanonicalizing(['javascript'], $link->fresh()->tags->pluck('name')->all());
        $this->assertSoftDeleted('tags', ['id' => $source->id]);
        $this->assertDatabaseHas('tags', ['id' => $target->id, 'deleted_at' => null]);
    }

    public function test_merge_deduplicates_when_link_has_both(): void
    {
        $target = Tag::factory()->for($this->user)->create(['name' => 'javascript']);
        $source = Tag::factory()->for($this->user)->create(['name' => 'js']);
        $link = Link::factory()->for($this->user)->create();
        $link->tags()->attach([$target->id, $source->id]); // already has both

        $this->merger->merge($this->user, 'javascript', ['js']);

        $this->assertCount(1, $link->fresh()->tags); // no duplicate
        $this->assertEqualsCanonicalizing(['javascript'], $link->fresh()->tags->pluck('name')->all());
    }

    public function test_merge_creates_target_if_missing(): void
    {
        $source = Tag::factory()->for($this->user)->create(['name' => 'js']);
        $link = Link::factory()->for($this->user)->create();
        $link->tags()->attach($source->id);

        $result = $this->merger->merge($this->user, 'javascript', ['js']);

        $this->assertTrue($result['created_target']);
        $this->assertDatabaseHas('tags', ['user_id' => $this->user->id, 'name' => 'javascript']);
        $this->assertEqualsCanonicalizing(['javascript'], $link->fresh()->tags->pluck('name')->all());
    }

    public function test_dry_run_changes_nothing(): void
    {
        $source = Tag::factory()->for($this->user)->create(['name' => 'js']);
        $link = Link::factory()->for($this->user)->create();
        $link->tags()->attach($source->id);

        $result = $this->merger->merge($this->user, 'javascript', ['js'], true);

        $this->assertSame(1, $result['merged'][0]['links_moved']);
        $this->assertDatabaseHas('tags', ['id' => $source->id, 'deleted_at' => null]); // not deleted
        $this->assertEqualsCanonicalizing(['js'], $link->fresh()->tags->pluck('name')->all());
        $this->assertDatabaseMissing('tags', ['name' => 'javascript']); // target not created
    }

    public function test_missing_source_is_reported(): void
    {
        Tag::factory()->for($this->user)->create(['name' => 'javascript']);

        $result = $this->merger->merge($this->user, 'javascript', ['nope']);

        $this->assertEqualsCanonicalizing(['nope'], $result['missing']);
        $this->assertEmpty($result['merged']);
    }

    public function test_does_not_merge_tag_into_itself(): void
    {
        $tag = Tag::factory()->for($this->user)->create(['name' => 'javascript']);
        $link = Link::factory()->for($this->user)->create();
        $link->tags()->attach($tag->id);

        $result = $this->merger->merge($this->user, 'javascript', ['javascript', 'JavaScript']);

        $this->assertEmpty($result['merged']);
        $this->assertDatabaseHas('tags', ['id' => $tag->id, 'deleted_at' => null]);
    }
}
