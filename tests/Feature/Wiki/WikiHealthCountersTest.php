<?php

namespace Tests\Feature\Wiki;

use App\Enums\WikiFactSource;
use App\Enums\WikiFactStatus;
use App\Enums\WikiFactVolatility;
use App\Models\Client;
use App\Models\Setting;
use App\Models\User;
use App\Models\WikiFact;
use App\Models\WikiPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WikiHealthCountersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::setValue('wiki_enabled', '1');
        // Default staleness window
        Setting::setValue('wiki_staleness_days_volatile', '90');
    }

    private function user(): User
    {
        return User::factory()->create();
    }

    private function staleFact(WikiPage $page, array $overrides = []): WikiFact
    {
        return WikiFact::factory()->create(array_merge([
            'page_id' => $page->id,
            'client_id' => $page->client_id,
            'scope' => $page->scope,
            'subject_key' => 'asset:dc01:fw',
            'statement' => 'firmware 7.2.1',
            'status' => WikiFactStatus::Confirmed,
            'source_type' => WikiFactSource::Ticket,
            'volatility' => WikiFactVolatility::Volatile,
            'last_affirmed_at' => now()->subDays(120), // past the 90-day window
        ], $overrides));
    }

    // ── healthCounts: stale is included ─────────────────────────────────────

    public function test_health_counts_include_stale_on_client_index(): void
    {
        $client = Client::factory()->create();
        $page = WikiPage::factory()->forClient($client)->create(['slug' => 'infrastructure']);

        $this->staleFact($page);                                  // one stale
        WikiFact::factory()->create([                             // one fresh — should NOT count
            'page_id' => $page->id,
            'client_id' => $client->id,
            'scope' => $page->scope,
            'volatility' => WikiFactVolatility::Volatile,
            'source_type' => WikiFactSource::Ticket,
            'status' => WikiFactStatus::Confirmed,
            'last_affirmed_at' => now()->subDays(5),
        ]);

        $response = $this->actingAs($this->user())->get(route('clients.wiki.index', $client));

        $response->assertOk()->assertSee('1 stale');
    }

    public function test_health_counts_stale_count_is_scoped_to_client(): void
    {
        // Stale fact on client A should not appear on client B's index
        $clientA = Client::factory()->create();
        $clientB = Client::factory()->create();
        $pageA = WikiPage::factory()->forClient($clientA)->create(['slug' => 'infrastructure']);

        $this->staleFact($pageA); // stale on A

        $response = $this->actingAs($this->user())->get(route('clients.wiki.index', $clientB));

        $response->assertOk()->assertDontSee('stale');
    }

    // ── zero-state silent: no "Needs review" text when nothing to review ────

    public function test_zero_state_is_silent_on_clean_wiki(): void
    {
        $client = Client::factory()->create();
        // No facts at all — completely clean wiki

        $response = $this->actingAs($this->user())->get(route('clients.wiki.index', $client));

        $response->assertOk()->assertDontSee('Needs review');
    }

    public function test_zero_state_is_silent_when_all_facts_are_fresh_and_confirmed(): void
    {
        $client = Client::factory()->create();
        $page = WikiPage::factory()->forClient($client)->create(['slug' => 'infrastructure']);
        // A recently-affirmed volatile fact — not stale
        WikiFact::factory()->create([
            'page_id' => $page->id,
            'client_id' => $client->id,
            'scope' => $page->scope,
            'volatility' => WikiFactVolatility::Volatile,
            'source_type' => WikiFactSource::Ticket,
            'status' => WikiFactStatus::Confirmed,
            'last_affirmed_at' => now()->subDays(5),
        ]);

        $response = $this->actingAs($this->user())->get(route('clients.wiki.index', $client));

        $response->assertOk()->assertDontSee('Needs review');
    }

    // ── "Needs review" block surfaces when ONLY stale (no unverified/disputed) ─

    public function test_needs_review_block_shown_when_only_stale(): void
    {
        $client = Client::factory()->create();
        $page = WikiPage::factory()->forClient($client)->create(['slug' => 'infrastructure']);

        $this->staleFact($page); // stale only — no unverified or disputed facts

        $response = $this->actingAs($this->user())->get(route('clients.wiki.index', $client));

        $response->assertOk()->assertSee('Needs review')->assertSee('stale');
    }

    // ── stale in section summaries on show page ──────────────────────────────

    public function test_stale_fact_appears_in_section_summary_on_show(): void
    {
        $client = Client::factory()->create();
        $page = WikiPage::factory()->forClient($client)->create(['slug' => 'infrastructure']);

        $this->staleFact($page, ['section_anchor' => 'assets']);

        $response = $this->actingAs($this->user())->get(route('clients.wiki.show', [$client, 'infrastructure']));

        $response->assertOk()->assertSee('1 stale');
    }

    public function test_stale_section_summary_is_silent_when_no_stale(): void
    {
        $client = Client::factory()->create();
        $page = WikiPage::factory()->forClient($client)->create(['slug' => 'infrastructure']);
        // Fresh volatile fact — not stale
        WikiFact::factory()->create([
            'page_id' => $page->id,
            'client_id' => $client->id,
            'scope' => $page->scope,
            'section_anchor' => 'assets',
            'volatility' => WikiFactVolatility::Volatile,
            'source_type' => WikiFactSource::Ticket,
            'status' => WikiFactStatus::Confirmed,
            'last_affirmed_at' => now()->subDays(5),
        ]);

        $response = $this->actingAs($this->user())->get(route('clients.wiki.show', [$client, 'infrastructure']));

        $response->assertOk()->assertDontSee('stale');
    }
}
