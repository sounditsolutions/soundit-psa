<?php

namespace Tests\Feature\Wiki;

use App\Enums\TicketStatus;
use App\Enums\WikiAuthorType;
use App\Enums\WikiFactSource;
use App\Enums\WikiFactStatus;
use App\Enums\WikiFactVolatility;
use App\Enums\WikiPageKind;
use App\Enums\WikiRunStatus;
use App\Jobs\MineTicketKnowledge;
use App\Models\Client;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\WikiFact;
use App\Models\WikiLink;
use App\Models\WikiPage;
use App\Models\WikiRun;
use App\Services\Wiki\WikiMaintainService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class WikiMaintainServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::setValue('wiki_enabled', '1');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function clientWithPage(): array
    {
        $client = Client::factory()->create();
        $page = WikiPage::factory()->forClient($client)->create([
            'slug' => 'infrastructure', 'kind' => WikiPageKind::Environment,
        ]);

        return [$client, $page];
    }

    private function fact(
        WikiPage $page,
        string $subjectKey,
        string $statement,
        WikiFactSource $source = WikiFactSource::Ticket,
        WikiFactStatus $status = WikiFactStatus::Unverified,
    ): WikiFact {
        return WikiFact::factory()->create([
            'page_id' => $page->id,
            'client_id' => $page->client_id,
            'scope' => $page->scope,
            'subject_key' => $subjectKey,
            'statement' => $statement,
            'source_type' => $source,
            'status' => $status,
            'volatility' => WikiFactVolatility::Durable,
        ]);
    }

    // ── Contradiction sweep ───────────────────────────────────────────────────

    public function test_contradiction_sweep_pairs_cross_source_facts(): void
    {
        [$client, $page] = $this->clientWithPage();

        $sync = $this->fact($page, 'asset:dc01:ram', 'DC01 has 32 GB RAM', WikiFactSource::Sync, WikiFactStatus::Confirmed);
        $tix = $this->fact($page, 'asset:dc01:ram', 'DC01 has 16 GB RAM', WikiFactSource::Ticket, WikiFactStatus::Unverified);

        app(WikiMaintainService::class)->run('manual');

        $this->assertSame(WikiFactStatus::Disputed, $sync->fresh()->status);
        $this->assertSame(WikiFactStatus::Disputed, $tix->fresh()->status);
    }

    public function test_contradiction_sweep_sets_symmetric_disputed_with_links(): void
    {
        [$client, $page] = $this->clientWithPage();

        $a = $this->fact($page, 'asset:switch:fw', 'firmware 1.0', WikiFactSource::Sync, WikiFactStatus::Confirmed);
        $b = $this->fact($page, 'asset:switch:fw', 'firmware 2.0', WikiFactSource::Ticket, WikiFactStatus::Unverified);

        app(WikiMaintainService::class)->run('manual');

        $aFresh = $a->fresh();
        $bFresh = $b->fresh();
        $this->assertSame($aFresh->id, $bFresh->disputed_with_fact_id);
        $this->assertSame($bFresh->id, $aFresh->disputed_with_fact_id);
    }

    public function test_contradiction_sweep_ignores_already_disputed_pairs(): void
    {
        [$client, $page] = $this->clientWithPage();

        $a = $this->fact($page, 'asset:dc01:os', 'Windows 2019', WikiFactSource::Sync, WikiFactStatus::Disputed);
        $b = $this->fact($page, 'asset:dc01:os', 'Windows 2022', WikiFactSource::Ticket, WikiFactStatus::Disputed);
        $a->update(['disputed_with_fact_id' => $b->id]);
        $b->update(['disputed_with_fact_id' => $a->id]);

        $result = app(WikiMaintainService::class)->run('manual');

        // Already disputed — sweep files 0 new disputes
        $this->assertSame(0, $result['contradictions']['filed']);
    }

    public function test_contradiction_sweep_skips_identical_statements(): void
    {
        [$client, $page] = $this->clientWithPage();

        // Two facts with the SAME trimmed statement — reaffirmation, not contradiction
        $this->fact($page, 'asset:dc01:cpu', 'Xeon E5', WikiFactSource::Sync, WikiFactStatus::Confirmed);
        $this->fact($page, 'asset:dc01:cpu', 'Xeon E5', WikiFactSource::Ticket, WikiFactStatus::Unverified);

        $result = app(WikiMaintainService::class)->run('manual');

        $this->assertSame(0, $result['contradictions']['filed']);
    }

    public function test_contradiction_sweep_skips_pinned_facts(): void
    {
        [$client, $page] = $this->clientWithPage();

        $pinned = $this->fact($page, 'asset:fw:model', 'FortiGate 60F', WikiFactSource::Sync, WikiFactStatus::Confirmed);
        $pinned->update(['pinned' => true]);
        $this->fact($page, 'asset:fw:model', 'Cisco ASA', WikiFactSource::Ticket, WikiFactStatus::Unverified);

        $result = app(WikiMaintainService::class)->run('manual');

        // Pinned — not processed by sweep
        $this->assertSame(0, $result['contradictions']['filed']);
    }

    // ── Link lint ─────────────────────────────────────────────────────────────

    public function test_link_lint_counts_dead_and_orphan(): void
    {
        [$client, $page] = $this->clientWithPage();

        // Dead link: to_page_id is NULL
        WikiLink::create(['from_page_id' => $page->id, 'to_page_id' => null, 'target_slug' => 'nonexistent', 'anchor_text' => 'dead']);

        // Orphan page: active, non-overview, non-skeleton (created_by_type = ai), zero backlinks
        $orphan = WikiPage::factory()->forClient($client)->create([
            'slug' => 'orphan-page',
            'kind' => WikiPageKind::Environment,
            'created_by_type' => WikiAuthorType::Ai,
            'is_archived' => false,
        ]);

        $result = app(WikiMaintainService::class)->run('manual');

        $this->assertGreaterThanOrEqual(1, $result['lint']['dead_links']);
        $this->assertGreaterThanOrEqual(1, $result['lint']['orphan_pages']);
    }

    public function test_link_lint_excludes_skeleton_pages_from_orphan_count(): void
    {
        [$client, $page] = $this->clientWithPage();

        // Skeleton page: created_by_type = System — should NOT be counted as orphan
        WikiPage::factory()->forClient($client)->create([
            'slug' => 'skeleton-security',
            'kind' => WikiPageKind::Environment,
            'created_by_type' => WikiAuthorType::System,
            'is_archived' => false,
        ]);

        $result = app(WikiMaintainService::class)->run('manual');

        $this->assertSame(0, $result['lint']['orphan_pages']);
    }

    public function test_link_lint_dead_is_zero_when_all_links_resolve(): void
    {
        [$client, $page] = $this->clientWithPage();

        // A valid link with to_page_id set
        $targetPage = WikiPage::factory()->forClient($client)->create(['slug' => 'target-page']);
        WikiLink::create([
            'from_page_id' => $page->id,
            'to_page_id' => $targetPage->id,
            'target_slug' => 'target-page',
            'anchor_text' => 'Target',
        ]);

        $result = app(WikiMaintainService::class)->run('manual');

        $this->assertSame(0, $result['lint']['dead_links']);
    }

    // ── Maintain WikiRun record ───────────────────────────────────────────────

    public function test_run_records_a_maintain_wiki_run(): void
    {
        app(WikiMaintainService::class)->run('cron');

        $run = WikiRun::where('run_type', 'maintain')->latest('id')->first();
        $this->assertNotNull($run);
        $this->assertSame(WikiRunStatus::Completed, $run->status);
        $this->assertArrayHasKey('stale', $run->stage_results);
        $this->assertArrayHasKey('contradictions', $run->stage_results);
        $this->assertArrayHasKey('lint', $run->stage_results);
        $this->assertArrayHasKey('open_tickets', $run->stage_results);
        $this->assertArrayHasKey('regen', $run->stage_results);
    }

    public function test_run_is_idempotent_same_day(): void
    {
        app(WikiMaintainService::class)->run('cron');
        app(WikiMaintainService::class)->run('manual'); // second call same day

        $count = WikiRun::where('run_type', 'maintain')->count();
        $this->assertSame(1, $count); // updateOrCreate on daily hash → one row
    }

    // ── Stale-open-ticket sweep ───────────────────────────────────────────────

    public function test_stale_open_ticket_sweep_flags_not_mines(): void
    {
        Bus::fake();

        Setting::setValue('wiki_stale_open_ticket_days', '30');

        $client = Client::factory()->create();
        $ticket = Ticket::factory()->create([
            'client_id' => $client->id,
            'status' => TicketStatus::InProgress,
            'resolution' => null,
            'updated_at' => now()->subDays(45),
        ]);

        // Give it a note so it qualifies (has substantive content)
        TicketNote::create([
            'ticket_id' => $ticket->id,
            'author_name' => 'Staff',
            'body' => 'Still investigating.',
            'note_type' => 'note',
        ]);

        $result = app(WikiMaintainService::class)->run('cron');

        $this->assertGreaterThanOrEqual(1, $result['open_tickets']['flagged']);
        Bus::assertNotDispatched(MineTicketKnowledge::class); // Phase-5 default: flag only
    }

    public function test_stale_open_ticket_sweep_excludes_already_mined_tickets(): void
    {
        Bus::fake();

        Setting::setValue('wiki_stale_open_ticket_days', '30');

        $client = Client::factory()->create();
        $ticket = Ticket::factory()->create([
            'client_id' => $client->id,
            'status' => TicketStatus::InProgress,
            'resolution' => null,
            'updated_at' => now()->subDays(45),
        ]);

        TicketNote::create([
            'ticket_id' => $ticket->id,
            'author_name' => 'Staff',
            'body' => 'Note here.',
            'note_type' => 'note',
        ]);

        // Simulate a completed mine run for this ticket
        WikiRun::create([
            'run_type' => 'mine_ticket',
            'subject_type' => 'ticket',
            'subject_id' => $ticket->id,
            'status' => WikiRunStatus::Completed->value,
            'triggered_by' => 'auto',
        ]);

        $result = app(WikiMaintainService::class)->run('cron');

        // Already mined — should not be flagged
        $this->assertSame(0, $result['open_tickets']['flagged']);
    }

    public function test_stale_open_ticket_sweep_excludes_closed_tickets(): void
    {
        Bus::fake();

        Setting::setValue('wiki_stale_open_ticket_days', '30');

        $client = Client::factory()->create();
        // Closed ticket — should not be flagged as stale-open
        Ticket::factory()->create([
            'client_id' => $client->id,
            'status' => TicketStatus::Closed,
            'updated_at' => now()->subDays(45),
        ]);

        $result = app(WikiMaintainService::class)->run('cron');

        $this->assertSame(0, $result['open_tickets']['flagged']);
    }

    // ── Staleness sweep ───────────────────────────────────────────────────────

    public function test_staleness_sweep_counts_stale_facts(): void
    {
        [$client, $page] = $this->clientWithPage();

        Setting::setValue('wiki_staleness_days_volatile', '90');

        WikiFact::factory()->create([
            'page_id' => $page->id,
            'client_id' => $client->id,
            'scope' => $page->scope,
            'volatility' => WikiFactVolatility::Volatile,
            'source_type' => WikiFactSource::Ticket,
            'status' => WikiFactStatus::Confirmed,
            'last_affirmed_at' => now()->subDays(120),
        ]);

        $result = app(WikiMaintainService::class)->run('cron');

        $this->assertSame(1, $result['stale']['total']);
    }

    // ── markDisputed (public wrapper) ─────────────────────────────────────────

    public function test_mark_disputed_is_symmetric(): void
    {
        [$client, $page] = $this->clientWithPage();

        $a = $this->fact($page, 'asset:router:fw', 'firmware A', WikiFactSource::Sync, WikiFactStatus::Confirmed);
        $b = $this->fact($page, 'asset:router:fw', 'firmware B', WikiFactSource::Ticket, WikiFactStatus::Unverified);

        app(\App\Services\Wiki\WikiFactService::class)->markDisputed($b, $a);

        $aFresh = $a->fresh();
        $bFresh = $b->fresh();
        $this->assertSame(WikiFactStatus::Disputed, $aFresh->status);
        $this->assertSame(WikiFactStatus::Disputed, $bFresh->status);
        $this->assertSame($aFresh->id, $bFresh->disputed_with_fact_id);
        $this->assertSame($bFresh->id, $aFresh->disputed_with_fact_id);
    }
}
