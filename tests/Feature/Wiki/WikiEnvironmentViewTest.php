<?php

namespace Tests\Feature\Wiki;

use App\Models\Client;
use App\Models\Setting;
use App\Models\User;
use App\Models\WikiFact;
use App\Models\WikiPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * psa-s5bf: the per-client wiki index is a consolidated single-scroll environment
 * view — a tech reads the whole environment on one page with an in-page anchor nav,
 * instead of clicking through ten separate pages.
 */
class WikiEnvironmentViewTest extends TestCase
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

    public function test_environment_pages_render_inline_on_one_page(): void
    {
        $client = Client::factory()->create();
        // Pre-seed two skeleton slugs with distinctive bodies; ensureForClient() fills the rest.
        WikiPage::factory()->forClient($client)->create([
            'slug' => 'network', 'title' => 'Network', 'kind' => 'environment',
            'body_md' => "## Topology\n\nNETWORK_SENTINEL flat /24.\n",
        ]);
        WikiPage::factory()->forClient($client)->create([
            'slug' => 'security', 'title' => 'Security stack', 'kind' => 'environment',
            'body_md' => "## Tooling\n\nSECURITY_SENTINEL Huntress EDR.\n",
        ]);

        $response = $this->actingAs($this->user())->get(route('clients.wiki.index', $client));

        // Content from multiple environment pages is visible on a SINGLE response — the whole point.
        $response->assertOk()
            ->assertSee('NETWORK_SENTINEL')
            ->assertSee('SECURITY_SENTINEL')
            ->assertSee('Microsoft 365')   // a skeleton-seeded section title
            ->assertSee('Infrastructure'); // another seeded section title
    }

    public function test_sections_have_in_page_anchor_nav_and_open_links(): void
    {
        $client = Client::factory()->create();

        $response = $this->actingAs($this->user())->get(route('clients.wiki.index', $client));

        $response->assertOk()
            // Sticky in-page nav jumps to each section by anchor.
            ->assertSee('href="#wiki-network"', false)
            ->assertSee('href="#wiki-m365"', false)
            ->assertSee('id="wiki-infrastructure"', false)
            // Each section keeps a deep link to the full page for edit / history / facts.
            ->assertSee(route('clients.wiki.show', [$client, 'network']), false);
    }

    public function test_runbook_pages_are_sidebar_links_not_inline_sections(): void
    {
        $client = Client::factory()->create();
        WikiPage::factory()->forClient($client)->create([
            'slug' => 'runbooks/onboarding', 'title' => 'Onboarding deviation', 'kind' => 'runbook',
            'body_md' => "## Hardware\n\nMac only.\n",
        ]);

        $response = $this->actingAs($this->user())->get(route('clients.wiki.index', $client));

        $response->assertOk()
            // Listed in the "More pages" sidebar as a link…
            ->assertSee('Onboarding deviation')
            ->assertSee(route('clients.wiki.show', [$client, 'runbooks/onboarding']), false)
            // …but NOT inlined as a scroll section (no anchor id, no inline body).
            ->assertDontSee('id="wiki-runbooks-onboarding"', false)
            ->assertDontSee('Mac only.');
    }

    public function test_ambient_provenance_summary_shows_per_section(): void
    {
        $client = Client::factory()->create();
        $page = WikiPage::factory()->forClient($client)->create([
            'slug' => 'infrastructure', 'title' => 'Infrastructure',
            'body_md' => "## Assets\n\n- DC01\n",
        ]);
        WikiFact::factory()->create([
            'client_id' => $client->id, 'page_id' => $page->id, 'scope' => $page->scope,
            'section_anchor' => 'assets', 'status' => 'unverified',
            'subject_key' => 'asset:dc01:os', 'statement' => 'DC01 runs Windows Server 2022',
        ]);

        $response = $this->actingAs($this->user())->get(route('clients.wiki.index', $client));

        $response->assertOk()->assertSee('1 unverified');
    }

    public function test_clean_wiki_has_no_needs_review_nag(): void
    {
        $client = Client::factory()->create();

        $response = $this->actingAs($this->user())->get(route('clients.wiki.index', $client));

        // Zero-state is silent (spec §8.1.4) — a freshly-seeded skeleton has no facts.
        $response->assertOk()->assertDontSee('Needs review');
    }
}
