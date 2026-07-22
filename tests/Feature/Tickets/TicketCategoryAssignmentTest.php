<?php

namespace Tests\Feature\Tickets;

use App\Enums\TicketCategoryChangeSource;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Per-ticket taxonomy category UI (so-0ftg slice, psa-alzsw).
 *
 * The ITIL taxonomy node (tickets.category_id) — the one carrying the SOP —
 * must be VISIBLE on ticket detail and SETTABLE by a human, distinct from the
 * legacy free-text category/subcategory. The write path reuses tickets.update:
 * category_id is fillable, TicketObserver stamps category_source=staff for an
 * authenticated web user and logs the change, so a human-assigned node is
 * human-owned and triage will not clobber it (CLAUDE.md so-0ftg Part 4).
 */
class TicketCategoryAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Ticket create/update fire triage/technician job dispatches — not under test.
        Bus::fake();
    }

    /** A 3-tier branch: Hardware / Laptop / Boot failure. Returns the leaf + ancestors. */
    private function tree(): array
    {
        $hardware = TicketCategory::create(['name' => 'Hardware']);
        $laptop = TicketCategory::create(['name' => 'Laptop', 'parent_id' => $hardware->id]);
        $leaf = TicketCategory::create(['name' => 'Boot failure', 'parent_id' => $laptop->id]);

        return compact('hardware', 'laptop', 'leaf');
    }

    // ── DISPLAY ──

    public function test_ticket_detail_shows_the_assigned_taxonomy_node_path(): void
    {
        ['leaf' => $node] = $this->tree();
        $ticket = Ticket::factory()->create(['category_id' => $node->id]);

        $this->actingAs(User::factory()->create())
            ->get(route('tickets.show', $ticket))
            ->assertOk()
            ->assertSee('Hardware / Laptop / Boot failure'); // TicketCategory::pathString()
    }

    public function test_ticket_detail_marks_an_uncategorized_ticket(): void
    {
        $ticket = Ticket::factory()->create(['category_id' => null]);

        $this->actingAs(User::factory()->create())
            ->get(route('tickets.show', $ticket))
            ->assertOk()
            ->assertSee('Uncategorized');
    }

    public function test_the_picker_offers_active_nodes_and_hides_inactive_ones(): void
    {
        $this->tree();
        TicketCategory::create(['name' => 'Retired Node', 'is_active' => false]);
        $ticket = Ticket::factory()->create();

        $resp = $this->actingAs(User::factory()->create())
            ->get(route('tickets.show', $ticket))
            ->assertOk();

        $resp->assertSee('name="category_id"', false);        // the picker exists
        $resp->assertSee('Hardware / Laptop / Boot failure');  // active leaf offered as a full path
        $resp->assertDontSee('Retired Node');                  // inactive node not offered
    }

    public function test_detail_flags_a_node_that_has_an_sop_with_a_manage_link(): void
    {
        ['leaf' => $node] = $this->tree();
        $node->update(['sop_text' => 'Step 1: reseat the RAM.']);
        $ticket = Ticket::factory()->create(['category_id' => $node->id]);

        $resp = $this->actingAs(User::factory()->create())
            ->get(route('tickets.show', $ticket))
            ->assertOk();
        $resp->assertSee('SOP available');
        $resp->assertSee(route('ticket-categories.show', $node), false); // manage deep-link
    }

    public function test_detail_flags_a_node_missing_its_sop(): void
    {
        ['leaf' => $node] = $this->tree(); // no sop_text
        $ticket = Ticket::factory()->create(['category_id' => $node->id]);

        $this->actingAs(User::factory()->create())
            ->get(route('tickets.show', $ticket))
            ->assertOk()
            ->assertSee('No SOP yet');
    }

    // ── ASSIGN ──

    public function test_a_human_can_set_the_category_and_it_records_staff_source(): void
    {
        ['leaf' => $node] = $this->tree();
        $ticket = Ticket::factory()->create(['category_id' => null]);

        $this->actingAs(User::factory()->create())
            ->patch(route('tickets.update', $ticket), [
                'subject' => $ticket->subject,
                'category_id' => $node->id,
            ])
            ->assertRedirect();

        $ticket->refresh();
        $this->assertSame($node->id, $ticket->category_id);
        // Observer-stamped ownership: a human write is Staff, so triage cannot clobber it.
        $this->assertSame(TicketCategoryChangeSource::Staff, $ticket->category_source);
    }

    public function test_a_human_can_clear_the_category(): void
    {
        ['leaf' => $node] = $this->tree();
        $ticket = Ticket::factory()->create(['category_id' => $node->id]);

        $this->actingAs(User::factory()->create())
            ->patch(route('tickets.update', $ticket), [
                'subject' => $ticket->subject,
                'category_id' => '',
            ])
            ->assertRedirect();

        $this->assertNull($ticket->refresh()->category_id);
    }

    public function test_a_nonexistent_category_id_is_rejected(): void
    {
        $ticket = Ticket::factory()->create(['category_id' => null]);

        $this->actingAs(User::factory()->create())
            ->patch(route('tickets.update', $ticket), [
                'subject' => $ticket->subject,
                'category_id' => 999999,
            ])
            ->assertSessionHasErrors('category_id');

        $this->assertNull($ticket->refresh()->category_id);
    }

    public function test_an_inactive_category_id_is_rejected(): void
    {
        $retired = TicketCategory::create(['name' => 'Retired', 'is_active' => false]);
        $ticket = Ticket::factory()->create(['category_id' => null]);

        $this->actingAs(User::factory()->create())
            ->patch(route('tickets.update', $ticket), [
                'subject' => $ticket->subject,
                'category_id' => $retired->id,
            ])
            ->assertSessionHasErrors('category_id');

        $this->assertNull($ticket->refresh()->category_id);
    }

    // ── RETIRED-NODE PRESERVATION (psa-alzsw R1 must-fix) ──
    //
    // The picker lists only ACTIVE nodes and the form always posts category_id.
    // Without a grandfather, a ticket already sitting on a soft-retired node
    // would post blank on any unrelated save and silently null its node. The
    // fix: the picker re-surfaces the ticket's OWN current node (marked
    // "(retired)", pre-selected) and validation lets that one exact id through,
    // so an unrelated save re-posts the current id (a no-op) instead of blank.

    public function test_the_picker_includes_the_tickets_own_retired_node_preselected(): void
    {
        $retired = TicketCategory::create(['name' => 'Legacy Symptom', 'is_active' => false]);
        $ticket = Ticket::factory()->create(['category_id' => $retired->id]);

        $resp = $this->actingAs(User::factory()->create())
            ->get(route('tickets.show', $ticket))
            ->assertOk();

        // Re-surfaced, flagged retired, and pre-selected so the form re-posts it.
        $resp->assertSee('Legacy Symptom (retired)');
        $resp->assertSee('value="'.$retired->id.'" selected', false);
    }

    public function test_a_subject_only_save_preserves_a_current_retired_node(): void
    {
        // The node is soft-retired AFTER the ticket was placed on it.
        $retired = TicketCategory::create(['name' => 'Legacy Symptom', 'is_active' => false]);
        $ticket = Ticket::factory()->create(['category_id' => $retired->id]);

        // The form re-posts the current (retired) id alongside the edited field.
        $this->actingAs(User::factory()->create())
            ->patch(route('tickets.update', $ticket), [
                'subject' => 'Changed subject only',
                'category_id' => $retired->id,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $ticket->refresh();
        $this->assertSame($retired->id, $ticket->category_id); // NOT nulled
        $this->assertSame('Changed subject only', $ticket->subject);
    }

    public function test_an_explicit_clear_nulls_the_node_and_records_staff(): void
    {
        ['leaf' => $node] = $this->tree();
        $ticket = Ticket::factory()->create(['category_id' => $node->id]);

        // Selecting the blank option ON PURPOSE must still clear + attribute Staff.
        $this->actingAs(User::factory()->create())
            ->patch(route('tickets.update', $ticket), [
                'subject' => $ticket->subject,
                'category_id' => '',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $ticket->refresh();
        $this->assertNull($ticket->category_id);
        $this->assertSame(TicketCategoryChangeSource::Staff, $ticket->category_source);
        $this->assertDatabaseHas('ticket_category_change_logs', [
            'ticket_id' => $ticket->id,
            'new_category_id' => null,
            'source' => 'staff',
        ]);
    }

    public function test_a_different_inactive_node_is_rejected_even_when_the_current_is_retired(): void
    {
        // Grandfathering is narrow: ONLY the ticket's own current id is let
        // through, never inactive nodes at large.
        $currentRetired = TicketCategory::create(['name' => 'Current Retired', 'is_active' => false]);
        $otherRetired = TicketCategory::create(['name' => 'Other Retired', 'is_active' => false]);
        $ticket = Ticket::factory()->create(['category_id' => $currentRetired->id]);

        $this->actingAs(User::factory()->create())
            ->patch(route('tickets.update', $ticket), [
                'subject' => $ticket->subject,
                'category_id' => $otherRetired->id,
            ])
            ->assertSessionHasErrors('category_id');

        $this->assertSame($currentRetired->id, $ticket->refresh()->category_id); // untouched
    }

    // ── LABEL ASSOCIATION (psa-alzsw R1 must-fix, a11y) ──

    public function test_the_edit_cluster_selects_have_programmatic_labels(): void
    {
        $this->tree();
        $ticket = Ticket::factory()->create();

        $resp = $this->actingAs(User::factory()->create())
            ->get(route('tickets.show', $ticket))
            ->assertOk();

        // Each of the three edit-cluster selects has a bound <label for="...">.
        $resp->assertSee('for="editCategoryNode"', false);
        $resp->assertSee('for="editCategory"', false);
        $resp->assertSee('for="editSubcategory"', false);
    }
}
