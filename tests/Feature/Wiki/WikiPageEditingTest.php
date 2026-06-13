<?php

namespace Tests\Feature\Wiki;

use App\Models\Client;
use App\Models\User;
use App\Models\WikiPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WikiPageEditingTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_creates_global_page_with_revision(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/wiki-pages', [
            'title' => 'Fortinet', 'slug' => 'vendors/fortinet', 'kind' => 'vendor',
            'body_md' => "## Quirks\n\nFortiOS 7.4 DHCP bug.",
        ])->assertRedirect('/wiki/vendors/fortinet');

        $page = WikiPage::where('slug', 'vendors/fortinet')->first();
        $this->assertSame('human', $page->created_by_type->value);
        $this->assertCount(1, $page->revisions);
    }

    public function test_store_creates_client_page(): void
    {
        $user = User::factory()->create();
        $client = Client::factory()->create();

        $this->actingAs($user)->post('/wiki-pages', [
            'client_id' => $client->id, 'title' => 'Printers', 'slug' => 'printers',
            'kind' => 'environment', 'body_md' => '## Fleet',
        ])->assertRedirect("/clients/{$client->id}/wiki/printers");

        $this->assertNotNull(WikiPage::forClient($client->id)->where('slug', 'printers')->first());
    }

    public function test_store_duplicate_slug_shows_error(): void
    {
        $user = User::factory()->create();
        WikiPage::factory()->create(['slug' => 'dup']);

        $this->actingAs($user)->from('/wiki-pages/create')->post('/wiki-pages', [
            'title' => 'Dup', 'slug' => 'dup', 'kind' => 'note', 'body_md' => '',
        ])->assertRedirect('/wiki-pages/create')->assertSessionHas('error');

        $this->assertSame(1, WikiPage::where('slug', 'dup')->count());
    }

    public function test_update_writes_revision_and_detects_concurrent_edit(): void
    {
        $user = User::factory()->create();
        $page = WikiPage::factory()->create(['body_md' => 'v1']);
        $stamp = $page->updated_at->toIso8601String();

        $this->actingAs($user)->patch("/wiki-pages/{$page->id}", [
            'body_md' => 'v2', 'change_summary' => 'tweak', 'expected_updated_at' => $stamp,
        ])->assertRedirect();
        $this->assertSame('v2', $page->fresh()->body_md);

        $this->actingAs($user)->patch("/wiki-pages/{$page->id}", [
            'body_md' => 'v3', 'change_summary' => 'stale', 'expected_updated_at' => $stamp,
        ])->assertSessionHas('error');
        $this->assertSame('v2', $page->fresh()->body_md);
    }
}
