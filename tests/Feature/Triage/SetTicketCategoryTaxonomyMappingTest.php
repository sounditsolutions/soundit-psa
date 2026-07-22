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
 *  - a node a person chose is NEVER overwritten or cleared by triage;
 *  - unknown history (category_id set, no log rows) counts as human-owned.
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

        // Only the human's row exists — triage added nothing.
        $log = TicketCategoryChangeLog::sole();
        $this->assertSame(TicketCategoryChangeSource::Staff, $log->source);
        $this->assertSame($staff->id, $log->changed_by);
    }

    public function test_unknown_history_counts_as_human_owned(): void
    {
        $ticket = Ticket::factory()->create();

        // category_id present but the change log never saw it (pre-feature
        // data, direct DB write). Conservative: treat as human-owned.
        Ticket::withoutEvents(fn () => $ticket->update(['category_id' => $this->malware->id]));

        $result = $this->setCategory($ticket->refresh(), 'Security', 'Phishing');

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
