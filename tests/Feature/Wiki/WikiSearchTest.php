<?php

namespace Tests\Feature\Wiki;

use App\Models\Client;
use App\Models\Setting;
use App\Models\User;
use App\Models\WikiFact;
use App\Models\WikiPage;
use App\Services\Wiki\WikiSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WikiSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::setValue('wiki_enabled', '1');
    }

    public function test_search_finds_pages_and_facts_with_like_fallback(): void
    {
        $client = Client::factory()->create();
        $page = WikiPage::factory()->forClient($client)->create([
            'slug' => 'network', 'title' => 'Network', 'body_md' => 'FortiGate 60F at the edge.',
        ]);
        WikiFact::factory()->create([
            'client_id' => $client->id, 'page_id' => $page->id,
            'subject_key' => 'asset:fw01:model', 'statement' => 'FW01 is a FortiGate 60F',
        ]);
        WikiPage::factory()->create(['slug' => 'unrelated', 'title' => 'Unrelated', 'body_md' => 'nothing here']);

        $results = app(WikiSearchService::class)->search('FortiGate', $client->id);

        $this->assertCount(1, $results['pages']);
        $this->assertTrue($results['pages']->first()->is($page));
        $this->assertCount(1, $results['facts']);
    }

    public function test_client_scope_includes_global_pages(): void
    {
        $client = Client::factory()->create();
        WikiPage::factory()->create(['slug' => 'vendors/fortinet', 'title' => 'Fortinet', 'body_md' => 'FortiGate quirks']);

        $results = app(WikiSearchService::class)->search('FortiGate', $client->id);

        $this->assertCount(1, $results['pages']);
    }

    public function test_search_excludes_archived_pages_and_retired_facts(): void
    {
        $client = Client::factory()->create();
        $archivedPage = WikiPage::factory()->forClient($client)->create([
            'slug' => 'old', 'title' => 'Old FortiGate notes', 'body_md' => 'FortiGate', 'is_archived' => true,
        ]);
        $livePage = WikiPage::factory()->forClient($client)->create(['slug' => 'live', 'title' => 'Live', 'body_md' => 'x']);
        WikiFact::factory()->create([
            'client_id' => $client->id, 'page_id' => $livePage->id,
            'subject_key' => 'asset:fw:model', 'statement' => 'FortiGate retired fact', 'status' => 'retired',
        ]);
        // A non-retired fact on an archived page should also be excluded.
        WikiFact::factory()->create([
            'client_id' => $client->id, 'page_id' => $archivedPage->id,
            'subject_key' => 'asset:fw:ip', 'statement' => 'FortiGate on archived page', 'status' => 'confirmed',
        ]);

        $results = app(WikiSearchService::class)->search('FortiGate', $client->id);

        $this->assertCount(0, $results['pages']);
        $this->assertCount(0, $results['facts']);
    }

    public function test_search_route_renders_results(): void
    {
        $user = User::factory()->create();
        WikiPage::factory()->create(['slug' => 'vendors/fortinet', 'title' => 'Fortinet', 'body_md' => 'FortiGate quirks']);

        $this->actingAs($user)->get('/wiki-search?q=FortiGate')
            ->assertOk()
            ->assertSee('Fortinet');
    }
}
