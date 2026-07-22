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
}
