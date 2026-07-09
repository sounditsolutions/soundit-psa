<?php

namespace Tests\Feature\Tickets;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * psa-5d8f: a resolved ticket must record what was done — the resolution feeds
 * future tickets and the client wiki. Staff-facing resolve paths therefore
 * require a non-empty resolution before the ticket moves to Resolved.
 * Programmatic paths through TicketService::changeStatus() stay flexible (they
 * rely on the AI auto-draft fallback), so the guard lives at the controllers,
 * not the service.
 */
class ResolveRequiresResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Ticket save fires the observer (triage/notification dispatches). Fake the
        // bus so no queued work escapes the test.
        Bus::fake();
    }

    // ── Status endpoint (Resolve modal + status-only panel) ──────────────────

    public function test_resolving_via_status_endpoint_is_rejected_without_a_resolution(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create([
            'status' => TicketStatus::InProgress,
            'resolution' => null,
        ]);

        $resp = $this->actingAs($user)->patch(route('tickets.update-status', $ticket), [
            'status' => TicketStatus::Resolved->value,
            'resolution' => '',
        ]);

        $resp->assertRedirect(route('tickets.show', $ticket));
        $resp->assertSessionHas('error');

        $ticket->refresh();
        $this->assertSame(TicketStatus::InProgress, $ticket->status, 'Ticket must not be resolved without a resolution');
        $this->assertNull($ticket->resolved_at);
        $this->assertNull($ticket->resolution);
    }

    public function test_resolving_via_status_endpoint_rejects_whitespace_only_resolution(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create([
            'status' => TicketStatus::InProgress,
            'resolution' => null,
        ]);

        $resp = $this->actingAs($user)->patch(route('tickets.update-status', $ticket), [
            'status' => TicketStatus::Resolved->value,
            'resolution' => "   \n\t ",
        ]);

        $resp->assertSessionHas('error');

        $ticket->refresh();
        $this->assertSame(TicketStatus::InProgress, $ticket->status);
        $this->assertNull($ticket->resolution);
    }

    public function test_resolving_via_status_endpoint_succeeds_with_a_resolution(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create([
            'status' => TicketStatus::InProgress,
            'resolution' => null,
        ]);

        $resp = $this->actingAs($user)->patch(route('tickets.update-status', $ticket), [
            'status' => TicketStatus::Resolved->value,
            'resolution' => '  Replaced the failing NIC and confirmed connectivity.  ',
        ]);

        $resp->assertRedirect(route('tickets.show', $ticket));
        $resp->assertSessionHas('success');

        $ticket->refresh();
        $this->assertSame(TicketStatus::Resolved, $ticket->status);
        $this->assertNotNull($ticket->resolved_at);
        // Stored value is trimmed.
        $this->assertSame('Replaced the failing NIC and confirmed connectivity.', $ticket->resolution);
    }

    public function test_non_resolved_transition_does_not_require_a_resolution(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create([
            'status' => TicketStatus::InProgress,
            'resolution' => null,
        ]);

        $resp = $this->actingAs($user)->patch(route('tickets.update-status', $ticket), [
            'status' => TicketStatus::PendingClient->value,
            // no resolution
        ]);

        $resp->assertRedirect(route('tickets.show', $ticket));
        $resp->assertSessionHas('success');

        $ticket->refresh();
        $this->assertSame(TicketStatus::PendingClient, $ticket->status);
    }

    // ── Note/reply endpoint (status change alongside a note) ─────────────────

    public function test_resolving_via_note_without_a_resolution_keeps_the_note_but_does_not_resolve(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create([
            'status' => TicketStatus::InProgress,
            'resolution' => null,
        ]);

        $resp = $this->actingAs($user)->post(route('tickets.notes.store', $ticket), [
            'body' => 'Poked at it for a while.',
            'note_type' => 'note',
            'is_private' => '1',
            'new_status' => TicketStatus::Resolved->value,
            'resolution' => '',
        ]);

        $resp->assertRedirect(route('tickets.show', $ticket));
        $resp->assertSessionHas('warning');

        // The note is preserved...
        $this->assertDatabaseHas('ticket_notes', [
            'ticket_id' => $ticket->id,
            'body' => 'Poked at it for a while.',
        ]);

        // ...but the ticket is not silently resolved without a resolution.
        $ticket->refresh();
        $this->assertSame(TicketStatus::InProgress, $ticket->status);
        $this->assertNull($ticket->resolved_at);
        $this->assertNull($ticket->resolution);
    }

    public function test_resolving_via_note_with_a_resolution_resolves_the_ticket(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create([
            'status' => TicketStatus::InProgress,
            'resolution' => null,
        ]);

        $resp = $this->actingAs($user)->post(route('tickets.notes.store', $ticket), [
            'body' => 'Fixed and verified.',
            'note_type' => 'note',
            'is_private' => '1',
            'new_status' => TicketStatus::Resolved->value,
            'resolution' => 'Cleared the print spooler and restarted the service.',
        ]);

        $resp->assertRedirect(route('tickets.show', $ticket));

        $ticket->refresh();
        $this->assertSame(TicketStatus::Resolved, $ticket->status);
        $this->assertSame('Cleared the print spooler and restarted the service.', $ticket->resolution);
    }

    // ── Modal render ─────────────────────────────────────────────────────────

    public function test_resolve_modal_marks_the_resolution_as_required(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create(['status' => TicketStatus::InProgress]);

        $resp = $this->actingAs($user)->get(route('tickets.show', $ticket));

        $resp->assertOk();
        // Scope the assertions to the Resolve modal via the resolveResolution id so
        // the sibling Close modal (which keeps its own "(recommended)" copy) is not
        // matched. The label now reads "(required)" and the textarea is required.
        $resp->assertSee('for="resolveResolution" class="form-label mb-0">Resolution summary <span class="text-danger">(required)</span>', false);
        $resp->assertSee('id="resolveResolution" class="form-control" rows="3" required', false);
        $resp->assertDontSee('for="resolveResolution" class="form-label mb-0">Resolution summary <span class="text-muted">(recommended)</span>', false);
    }
}
