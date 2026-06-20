<?php

namespace Tests\Models;

use App\Models\Link;
use App\Models\User;
use App\Repositories\LinkRepository;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class LinkUpdateTest extends TestCase
{
    use DatabaseMigrations;
    use DatabaseTransactions;

    /** @var User */
    private $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    public function test_valid_link_update(): void
    {
        $this->be($this->user);

        $link = Link::factory()->create();

        $changedData = [
            'title' => 'This is a new title!',
        ];

        $updatedLink = LinkRepository::update($link, $changedData);

        $this->assertEquals('This is a new title!', $updatedLink->title);
    }

    public function test_updating_url_of_broken_link_resets_status(): void
    {
        $this->be($this->user);

        $link = Link::factory()->create([
            'url' => 'https://broken-old-url.example.com',
            'status' => Link::STATUS_BROKEN,
            'last_checked_at' => now()->subDay(),
        ]);

        $updatedLink = LinkRepository::update($link, [
            'url' => 'https://working-new-url.example.com',
        ]);

        $this->assertEquals(Link::STATUS_OK, $updatedLink->status);
        $this->assertNull($updatedLink->last_checked_at);
    }

    public function test_updating_url_of_broken_link_to_same_url_does_not_reset_status(): void
    {
        $this->be($this->user);

        $link = Link::factory()->create([
            'url' => 'https://broken-url.example.com',
            'status' => Link::STATUS_BROKEN,
            'last_checked_at' => now()->subDay(),
        ]);

        $updatedLink = LinkRepository::update($link, [
            'url' => 'https://broken-url.example.com',
        ]);

        $this->assertEquals(Link::STATUS_BROKEN, $updatedLink->status);
        $this->assertNotNull($updatedLink->last_checked_at);
    }

    public function test_updating_title_of_broken_link_does_not_reset_status(): void
    {
        $this->be($this->user);

        $link = Link::factory()->create([
            'status' => Link::STATUS_BROKEN,
            'last_checked_at' => now()->subDay(),
        ]);

        $updatedLink = LinkRepository::update($link, [
            'title' => 'New title',
        ]);

        $this->assertEquals(Link::STATUS_BROKEN, $updatedLink->status);
        $this->assertNotNull($updatedLink->last_checked_at);
    }
}
