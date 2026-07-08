<?php

namespace Tests\Feature\Wiki;

use App\Models\Setting;
use App\Models\User;
use App\Models\WikiPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WikiArchiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_archive_button_archives_page_with_revision(): void
    {
        Setting::setValue('wiki_enabled', '1');
        $user = User::factory()->create();
        $page = WikiPage::factory()->create(['slug' => 'old-notes', 'title' => 'Old']);

        $this->actingAs($user)->post("/wiki-pages/{$page->id}/archive")
            ->assertRedirect(route('wiki.index'));

        $this->assertTrue($page->fresh()->is_archived);
        $this->assertSame('Archived', $page->fresh()->revisions->first()->change_summary);
        $this->actingAs($user)->get('/wiki/old-notes')->assertNotFound(); // active() scope
    }
}
