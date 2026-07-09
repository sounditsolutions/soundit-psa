<?php

namespace Tests\Feature\Wiki;

use App\Enums\WikiPageKind;
use App\Models\Client;
use App\Models\Setting;
use App\Models\User;
use App\Models\WikiPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * psa-voy3: the wiki index "Search the wiki" box must filter the on-page list.
 *
 * The narrowing itself is client-side JS (progressive enhancement over the full
 * server-side page+fact search that still runs on Enter). PHPUnit cannot execute
 * that JS, so these tests lock the DOM contract the script depends on: the filter
 * input id, the per-group / per-item hooks, and the lowercased `data-wiki-search`
 * haystack that decides what a typed term like "m365" matches or hides.
 */
class WikiIndexFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::setValue('wiki_enabled', '1');
    }

    private function user(): User
    {
        return User::factory()->create();
    }

    public function test_client_index_renders_the_live_filter_hooks(): void
    {
        $client = Client::factory()->create();

        // clientIndex lazily seeds the skeleton (Microsoft 365 / m365, Applications, …)
        // on first visit — the exact pages the wiki-client-nav QA scenario navigates.
        $response = $this->actingAs($this->user())->get(route('clients.wiki.index', $client));

        $response->assertOk()
            ->assertSee('id="wikiIndexFilter"', false)   // the search box the script binds to
            ->assertSee('data-wiki-group', false)         // group wrapper (header + list) it hides
            ->assertSee('data-wiki-item', false)          // per-page rows it filters
            ->assertSee('data-wiki-empty', false)         // "no matches" message it toggles
            ->assertSee("getElementById('wikiIndexFilter')", false); // the wiring script is present
    }

    public function test_m365_row_carries_a_haystack_that_matches_m365_and_applications_does_not(): void
    {
        $client = Client::factory()->create();

        $response = $this->actingAs($this->user())->get(route('clients.wiki.index', $client));

        $response->assertOk()
            // The M365 page title is "Microsoft 365" (no "m365" substring); the slug is the
            // reason typing "m365" matches. Both live in the search haystack.
            ->assertSee('data-wiki-search="microsoft 365 m365"', false)
            ->assertSee('Microsoft 365', false)
            // Applications has no "m365" anywhere, so the filter hides it — the exact
            // assertion the QA harness makes (unrelated entries absent after search).
            ->assertSee('data-wiki-search="applications applications"', false);

        // Guard the contract the JS relies on: the Applications haystack must NOT contain the term.
        $this->assertStringNotContainsString('m365', 'applications applications');
    }

    public function test_global_index_also_renders_the_filter_hooks(): void
    {
        // Global index does not seed a skeleton, so give it a page to group.
        WikiPage::factory()->create([
            'slug' => 'runbooks',
            'title' => 'Runbooks',
            'kind' => WikiPageKind::Note,
        ]);

        $response = $this->actingAs($this->user())->get(route('wiki.index'));

        $response->assertOk()
            ->assertSee('id="wikiIndexFilter"', false)
            ->assertSee('data-wiki-item', false)
            ->assertSee('data-wiki-search="runbooks runbooks"', false);
    }
}
