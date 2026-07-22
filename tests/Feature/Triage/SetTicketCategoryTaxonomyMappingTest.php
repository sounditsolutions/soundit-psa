<?php

namespace Tests\Feature\Triage;

use App\Enums\TicketCategoryChangeSource;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketCategoryChangeLog;
use App\Models\User;
use App\Services\Triage\TriageToolExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * so-0ftg Part 4 at the seam it ships through: set_ticket_category (the
 * existing AI-triage classification write) now also places the ticket on the
 * SOP taxonomy via the coarse map. The invariants under test:
 *
 *  - a mapped pair sets tickets.category_id and the change log records a
 *    Triage-sourced row (Phase-1's refinement data);
 *  - an unmapped pair is an honest gap — and clears a stale triage-owned
 *    node after reclassification;
 *  - a node a person chose is NEVER overwritten or cleared by triage — even
 *    when the person set (or cleared) it AFTER the executor cached its Ticket
 *    model: ownership is decided from fresh DB state, not the snapshot
 *    (psa-p4zdp);
 *  - ownership is read from tickets.category_source — stamped in the SAME
 *    UPDATE as category_id — never from the change log, whose Staff row
 *    lands only after the human's update and so can still name the previous
 *    writer when a concurrent triage transaction looks (psa-trjwf re-review);
 *  - unknown ownership (category_id set, null stamp) counts as human-owned.
 */
class SetTicketCategoryTaxonomyMappingTest extends TestCase
{
    use RefreshDatabase;

    private TicketCategory $security;

    private TicketCategory $phishing;

    private TicketCategory $malware;

    protected function setUp(): void
    {
        parent::setUp();

        $this->security = TicketCategory::create(['name' => 'Security & EDR']);
        $this->phishing = TicketCategory::create(['name' => 'Phishing/BEC', 'parent_id' => $this->security->id]);
        $this->malware = TicketCategory::create(['name' => 'Malware/ransomware', 'parent_id' => $this->security->id]);

        config(['tickets.taxonomy_map' => [
            'Security' => [
                'Phishing' => ['Security & EDR', 'Phishing/BEC'],
                'Malware' => ['Security & EDR', 'Malware/ransomware'],
            ],
        ]]);
    }

    private function setCategory(Ticket $ticket, string $category, ?string $subcategory = null): array
    {
        $input = ['category' => $category];
        if ($subcategory !== null) {
            $input['subcategory'] = $subcategory;
        }

        return (new TriageToolExecutor($ticket))->execute('set_ticket_category', $input);
    }

    public function test_mapped_pair_assigns_the_taxonomy_node_and_logs_a_triage_row(): void
    {
        $ticket = Ticket::factory()->create();

        $result = $this->setCategory($ticket, 'Security', 'Phishing');

        $this->assertTrue($result['success']);
        $this->assertSame('mapped', $result['taxonomy']['status']);
        $this->assertSame($this->phishing->id, $result['taxonomy']['category_id']);
        $this->assertSame('Security & EDR / Phishing/BEC', $result['taxonomy']['path']);

        $ticket->refresh();
        $this->assertSame($this->phishing->id, $ticket->category_id);
        // Ownership stamped in the same UPDATE — this is what a later run's
        // precedence decision reads under the row lock.
        $this->assertSame(TicketCategoryChangeSource::Triage, $ticket->category_source);
        $this->assertSame('Security', $ticket->category);
        $this->assertSame('Phishing', $ticket->subcategory);

        $log = TicketCategoryChangeLog::sole();
        $this->assertSame($ticket->id, $log->ticket_id);
        $this->assertSame(TicketCategoryChangeSource::Triage, $log->source);
        $this->assertNull($log->previous_category_id);
        $this->assertSame($this->phishing->id, $log->new_category_id);
        $this->assertNull($log->previous_path);
        $this->assertSame('Security & EDR / Phishing/BEC', $log->new_path);
        $this->assertSame('Security', $log->legacy_category);
        $this->assertSame('Phishing', $log->legacy_subcategory);
        $this->assertNull($log->changed_by);
    }

    public function test_unmapped_pair_is_a_gap_and_writes_no_log_row(): void
    {
        $ticket = Ticket::factory()->create();

        $result = $this->setCategory($ticket, 'Security', 'Access Review');

        $this->assertTrue($result['success']);
        $this->assertSame('gap', $result['taxonomy']['status']);
        $this->assertNull($result['taxonomy']['category_id']);

        $ticket->refresh();
        $this->assertNull($ticket->category_id);
        // The legacy free-text classification still lands (existing behavior).
        $this->assertSame('Security', $ticket->category);
        $this->assertSame('Access Review', $ticket->subcategory);

        $this->assertSame(0, TicketCategoryChangeLog::count());
    }

    public function test_reclassification_remaps_a_triage_owned_node(): void
    {
        $ticket = Ticket::factory()->create();

        $this->setCategory($ticket, 'Security', 'Phishing');
        $result = $this->setCategory($ticket->refresh(), 'Security', 'Malware');

        $this->assertSame('mapped', $result['taxonomy']['status']);
        $this->assertSame($this->malware->id, $ticket->refresh()->category_id);

        $logs = TicketCategoryChangeLog::orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertSame($this->phishing->id, $logs[1]->previous_category_id);
        $this->assertSame($this->malware->id, $logs[1]->new_category_id);
        $this->assertSame(TicketCategoryChangeSource::Triage, $logs[1]->source);
    }

    public function test_reclassification_to_an_unmapped_pair_clears_a_triage_owned_node(): void
    {
        $ticket = Ticket::factory()->create();

        $this->setCategory($ticket, 'Security', 'Phishing');
        $result = $this->setCategory($ticket->refresh(), 'Security', 'Access Review');

        $this->assertSame('gap', $result['taxonomy']['status']);
        $this->assertNull($ticket->refresh()->category_id);

        $logs = TicketCategoryChangeLog::orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertSame($this->phishing->id, $logs[1]->previous_category_id);
        $this->assertNull($logs[1]->new_category_id);
    }

    public function test_a_human_assigned_node_is_never_overwritten(): void
    {
        $ticket = Ticket::factory()->create();

        // A staff member categorizes the ticket by hand (observer logs Staff).
        $staff = User::factory()->create();
        $this->actingAs($staff);
        $ticket->update(['category_id' => $this->malware->id]);
        auth()->logout();

        $result = $this->setCategory($ticket->refresh(), 'Security', 'Phishing');

        $this->assertSame('kept_existing', $result['taxonomy']['status']);
        $this->assertSame($this->malware->id, $result['taxonomy']['category_id']);
        $this->assertSame($this->malware->id, $ticket->refresh()->category_id);
        $this->assertSame(TicketCategoryChangeSource::Staff, $ticket->category_source);

        // Only the human's row exists — triage added nothing.
        $log = TicketCategoryChangeLog::sole();
        $this->assertSame(TicketCategoryChangeSource::Staff, $log->source);
        $this->assertSame($staff->id, $log->changed_by);
    }

    public function test_a_system_owned_node_is_never_overwritten_by_triage(): void
    {
        // A System write — e.g. an agent (Chet) via the update_ticket MCP tool
        // (psa-bk13g): the MCP surface has no authenticated web-user, so the
        // observer stamps System. System is treated as human-owned exactly like
        // Staff (TriageToolExecutor:549), so a Chet-set node is authoritative.
        $ticket = Ticket::factory()->create();
        $ticket->update(['category_id' => $this->malware->id]); // no actingAs -> System
        $this->assertSame(TicketCategoryChangeSource::System, $ticket->refresh()->category_source);

        $result = $this->setCategory($ticket->refresh(), 'Security', 'Phishing');

        $this->assertSame('kept_existing', $result['taxonomy']['status']);
        $this->assertSame($this->malware->id, $ticket->refresh()->category_id);
        $this->assertSame(TicketCategoryChangeSource::System, $ticket->refresh()->category_source);
    }

    public function test_race_human_update_committed_before_its_staff_log_row_is_preserved(): void
    {
        $ticket = Ticket::factory()->create();

        // Triage owned the node first: the log's latest row says Triage.
        $this->setCategory($ticket, 'Security', 'Phishing');

        // A concurrent triage loop constructs its executor now (stale model).
        $executor = new TriageToolExecutor($ticket->refresh());

        // Mid-loop, a human recategorizes. Their UPDATE — category_id and
        // category_source in ONE statement — commits, but TicketObserver::
        // updated() has not inserted the Staff log row yet (it always trails
        // the row update; psa-trjwf re-review). saveQuietly() manufactures
        // exactly that half-landed state: row committed, log still stale.
        Ticket::findOrFail($ticket->id)->forceFill([
            'category_id' => $this->malware->id,
            'category_source' => TicketCategoryChangeSource::Staff,
        ])->saveQuietly();

        // The triage transaction now locks the row. The change log still
        // names Triage as the last writer — deciding from it would clobber
        // the human. The locked row's own stamp must win.
        $result = $executor->execute('set_ticket_category', ['category' => 'Security', 'subcategory' => 'Phishing']);

        $this->assertSame('kept_existing', $result['taxonomy']['status']);
        $this->assertSame($this->malware->id, $result['taxonomy']['category_id']);
        $this->assertSame($this->malware->id, $ticket->refresh()->category_id);
        $this->assertSame(TicketCategoryChangeSource::Staff, $ticket->category_source);

        // Triage wrote nothing: still only its original assignment row.
        $this->assertSame(1, TicketCategoryChangeLog::count());
        $this->assertSame(TicketCategoryChangeSource::Triage, TicketCategoryChangeLog::sole()->source);
    }

    public function test_race_human_clear_committed_before_its_staff_log_row_stays_cleared(): void
    {
        $ticket = Ticket::factory()->create();

        // Triage owned the node first: the log's latest row says Triage.
        $this->setCategory($ticket, 'Security', 'Phishing');

        $executor = new TriageToolExecutor($ticket->refresh());

        // Same half-landed window as above, for a CLEAR: the human's null
        // (with its Staff stamp) is committed; their Staff log row is not.
        Ticket::findOrFail($ticket->id)->forceFill([
            'category_id' => null,
            'category_source' => TicketCategoryChangeSource::Staff,
        ])->saveQuietly();

        // A stale-log decision would read "triage wrote last" and re-assign
        // the node the human just removed. The row says a person owns the
        // clear — it must stay cleared.
        $result = $executor->execute('set_ticket_category', ['category' => 'Security', 'subcategory' => 'Malware']);

        $this->assertSame('kept_existing', $result['taxonomy']['status']);
        $this->assertNull($result['taxonomy']['category_id']);
        $this->assertNull($ticket->refresh()->category_id);
        $this->assertSame(TicketCategoryChangeSource::Staff, $ticket->category_source);
        $this->assertSame(1, TicketCategoryChangeLog::count());
    }

    public function test_race_human_category_set_after_executor_construction_is_preserved(): void
    {
        $ticket = Ticket::factory()->create();

        // The triage loop's executor is constructed FIRST — its cached Ticket
        // model reads category_id = null (the psa-p4zdp TOCTOU window).
        $executor = new TriageToolExecutor($ticket);

        // Mid-loop, a staff member categorizes the ticket through a FRESH model.
        $staff = User::factory()->create();
        $this->actingAs($staff);
        Ticket::findOrFail($ticket->id)->update(['category_id' => $this->malware->id]);
        auth()->logout();

        // The stale executor now classifies to a DIFFERENT node. Ownership must
        // be decided from the database, not the cached null snapshot, so the
        // human's node survives. Deliberately NO refresh of the executor's
        // ticket — the stale view IS the scenario under test.
        $result = $executor->execute('set_ticket_category', ['category' => 'Security', 'subcategory' => 'Phishing']);

        $this->assertSame('kept_existing', $result['taxonomy']['status']);
        $this->assertSame($this->malware->id, $result['taxonomy']['category_id']);
        $this->assertSame('Security & EDR / Malware/ransomware', $result['taxonomy']['path']);
        $this->assertSame($this->malware->id, $ticket->refresh()->category_id);

        // The log still holds ONLY the human's row, with true DB snapshots.
        $log = TicketCategoryChangeLog::sole();
        $this->assertSame(TicketCategoryChangeSource::Staff, $log->source);
        $this->assertSame($staff->id, $log->changed_by);
        $this->assertNull($log->previous_category_id);
        $this->assertSame($this->malware->id, $log->new_category_id);
        $this->assertSame('Security & EDR / Malware/ransomware', $log->new_path);
    }

    public function test_a_human_cleared_node_is_not_reassigned_by_triage(): void
    {
        $ticket = Ticket::factory()->create();

        // Triage owned the node first...
        $this->setCategory($ticket, 'Security', 'Phishing');

        // ...then a person deliberately cleared it (row stamped Staff in the
        // same UPDATE; the observer also logs a Staff row).
        $staff = User::factory()->create();
        $this->actingAs($staff);
        $ticket->refresh()->update(['category_id' => null]);
        auth()->logout();

        // A later triage classification must not undo the human's clear: a
        // fresh null whose ownership stamp is Staff is human-owned.
        $result = $this->setCategory($ticket->refresh(), 'Security', 'Malware');

        $this->assertSame('kept_existing', $result['taxonomy']['status']);
        $this->assertNull($result['taxonomy']['category_id']);
        $this->assertNull($result['taxonomy']['path']);
        $this->assertNull($ticket->refresh()->category_id);

        // Triage assign + human clear — triage added nothing after.
        $logs = TicketCategoryChangeLog::orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertSame(TicketCategoryChangeSource::Staff, $logs[1]->source);
        $this->assertNull($logs[1]->new_category_id);
    }

    public function test_unknown_ownership_counts_as_human_owned(): void
    {
        $ticket = Ticket::factory()->create();

        // category_id present but never stamped (pre-feature data, direct DB
        // write bypassing events). Conservative: treat as human-owned.
        Ticket::withoutEvents(fn () => $ticket->update(['category_id' => $this->malware->id]));
        $this->assertNull($ticket->refresh()->category_source);

        $result = $this->setCategory($ticket, 'Security', 'Phishing');

        $this->assertSame('kept_existing', $result['taxonomy']['status']);
        $this->assertSame($this->malware->id, $ticket->refresh()->category_id);
        $this->assertSame(0, TicketCategoryChangeLog::count());
    }

    public function test_setting_the_same_mapped_node_again_writes_no_duplicate_row(): void
    {
        $ticket = Ticket::factory()->create();

        $this->setCategory($ticket, 'Security', 'Phishing');
        $result = $this->setCategory($ticket->refresh(), 'Security', 'Phishing');

        $this->assertSame('mapped', $result['taxonomy']['status']);
        $this->assertSame(1, TicketCategoryChangeLog::count());
    }

    public function test_invalid_category_still_errors_before_any_mapping(): void
    {
        $ticket = Ticket::factory()->create();

        $result = $this->setCategory($ticket, 'Nonsense');

        $this->assertArrayHasKey('error', $result);
        $this->assertNull($ticket->refresh()->category_id);
        $this->assertSame(0, TicketCategoryChangeLog::count());
    }
}
