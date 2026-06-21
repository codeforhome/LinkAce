<?php

namespace Tests\Commands;

use App\Models\Link;
use App\Models\Tag;
use App\Models\User;
use App\Services\AITagging\OpenRouterClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TagReductionCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_prune_deletes_unused_tags_only_by_default(): void
    {
        $user = User::factory()->create();
        $unused = Tag::factory()->for($user)->create(['name' => 'orphan']);
        $used = Tag::factory()->for($user)->create(['name' => 'keep']);
        Link::factory()->for($user)->create()->tags()->attach($used->id);

        $this->artisan('tags:prune', ['--user-email' => $user->email]);

        $this->assertSoftDeleted('tags', ['id' => $unused->id]);
        $this->assertDatabaseHas('tags', ['id' => $used->id, 'deleted_at' => null]);
    }

    public function test_prune_protects_canonical_tags(): void
    {
        $user = User::factory()->create();
        $canonicalUnused = Tag::factory()->for($user)->create(['name' => 'bucket', 'is_canonical' => true]);

        $this->artisan('tags:prune', ['--user-email' => $user->email]);

        $this->assertDatabaseHas('tags', ['id' => $canonicalUnused->id, 'deleted_at' => null]);
    }

    public function test_prune_dry_run_changes_nothing(): void
    {
        $user = User::factory()->create();
        $unused = Tag::factory()->for($user)->create(['name' => 'orphan']);

        $this->artisan('tags:prune', ['--user-email' => $user->email, '--dry-run' => true]);

        $this->assertDatabaseHas('tags', ['id' => $unused->id, 'deleted_at' => null]);
    }

    public function test_merge_command_merges_sources(): void
    {
        $user = User::factory()->create();
        $target = Tag::factory()->for($user)->create(['name' => 'javascript']);
        $source = Tag::factory()->for($user)->create(['name' => 'js']);
        $link = Link::factory()->for($user)->create();
        $link->tags()->attach($source->id);

        $this->artisan('tags:merge', [
            '--user-email' => $user->email,
            '--into' => 'javascript',
            '--from' => 'js',
        ]);

        $this->assertSoftDeleted('tags', ['id' => $source->id]);
        $this->assertEqualsCanonicalizing(['javascript'], $link->fresh()->tags->pluck('name')->all());
    }

    public function test_suggest_merges_applies_with_faked_ai(): void
    {
        $user = User::factory()->create();
        $target = Tag::factory()->for($user)->create(['name' => 'javascript']);
        $source = Tag::factory()->for($user)->create(['name' => 'js']);
        $link = Link::factory()->for($user)->create();
        $link->tags()->attach($source->id);

        // Bind a fake OpenRouter client returning a canned merge map.
        $fake = \Mockery::mock(OpenRouterClient::class);
        $fake->shouldReceive('isConfigured')->andReturn(true);
        $fake->shouldReceive('chat')->andReturn('[{"canonical":"javascript","merge":["js"]}]');
        $this->app->instance(OpenRouterClient::class, $fake);

        $this->artisan('tags:suggest-merges', ['--user-email' => $user->email, '--apply' => true]);

        $this->assertSoftDeleted('tags', ['id' => $source->id]);
        $this->assertEqualsCanonicalizing(['javascript'], $link->fresh()->tags->pluck('name')->all());
    }
}
