<?php

namespace Tests\Feature\Technician\Cockpit;

use App\Enums\NoteType;
use App\Enums\TechnicianRunState;
use App\Enums\TicketStatus;
use App\Models\Client;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Services\Technician\Cockpit\CockpitQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * psa-y4ft.1: the "Closed directly by the agent" cockpit lane. An autonomous
 * DIRECT close (set_ticket_status → Closed) records a Done direct_close run;
 * this lane surfaces the recent ones (48h, ticket still Closed) with a
 * one-click Reopen that mirrors the held-close undo guards.
 */
class CockpitDirectCloseLaneTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    /** @return array{0: TechnicianRun, 1: Ticket, 2: TicketNote} */
    private function agentDirectClose(array $runOverrides = [], array $ticketOverrides = []): array
    {
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->create(array_merge([
            'client_id' => $client->id,
            'status' => TicketStatus::Closed,
            'subject' => 'Stale VPN ticket',
            'closed_at' => now(),
        ], $ticketOverrides));

        $note = TicketNote::create([
            'ticket_id' => $ticket->id,
            'author_id' => $this->user->id,
            'body' => 'Status changed from Pending Client to Closed.',
            'note_type' => NoteType::StatusChange,
            'is_private' => true,
            'status_from' => TicketStatus::PendingClient,
            'status_to' => TicketStatus::Closed,
            'noted_at' => now(),
        ]);

        $run = TechnicianRun::create(array_merge([
            'ticket_id' => $ticket->id,
            'client_id' => $client->id,
            'action_type' => 'direct_close',
            'content_hash' => hash('sha256', 'direct_close:'.$ticket->id.':'.$note->id),
            'state' => TechnicianRunState::Done,
            'proposed_content' => 'No client reply in 3 weeks; closing.',
            'proposed_meta' => ['status_note_id' => $note->id, 'drafted_by' => 'mcp-staff:chet'],
        ], $runOverrides));

        return [$run, $ticket, $note];
    }

    public function test_lane_shows_a_recent_direct_close_with_a_reopen_control(): void
    {
        [$run, $ticket] = $this->agentDirectClose();

        $response = $this->actingAs($this->user)->get(route('cockpit.index'));

        $response->assertOk();
        $response->assertSee('Closed directly by the agent');
        $response->assertSee('Stale VPN ticket');
        $response->assertSee('No client reply in 3 weeks; closing.');
        $response->assertSee('Reopen');
        $response->assertSee(route('cockpit.reopen', $run), false);
        $this->assertTrue($response->viewData('directCloses')->contains('id', $run->id));
    }

    public function test_lane_is_self_clearing_and_scoped_to_direct_close_runs(): void
    {
        [$kept] = $this->agentDirectClose();

        // Reopened by ANY path (ticket no longer Closed) → drops out.
        [$reopened, $reopenedTicket] = $this->agentDirectClose();
        $reopenedTicket->update(['status' => TicketStatus::InProgress, 'closed_at' => null]);

        // Already reversed (Denied) → drops out even while the ticket is Closed.
        $this->agentDirectClose(['state' => TechnicianRunState::Denied]);

        // Older than the 48h window → drops out.
        [$stale] = $this->agentDirectClose();
        $stale->created_at = now()->subHours(CockpitQuery::DIRECT_CLOSE_WINDOW_HOURS + 1);
        $stale->save();

        // A Done propose_close (operator-approved close) is NOT this lane's business.
        [$held] = $this->agentDirectClose(['action_type' => 'propose_close']);

        $ids = app(CockpitQuery::class)->recentDirectCloses()->pluck('id');

        $this->assertSame([$kept->id], $ids->all());
        $this->assertNotContains($reopened->id, $ids);
        $this->assertNotContains($stale->id, $ids);
        $this->assertNotContains($held->id, $ids);
    }

    public function test_reopen_reopens_the_ticket_and_marks_the_run_denied(): void
    {
        [$run, $ticket] = $this->agentDirectClose();

        $this->actingAs($this->user)
            ->postJson(route('cockpit.reopen', $run))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('status', 'reopened');

        $fresh = $ticket->fresh();
        $this->assertSame(TicketStatus::InProgress, $fresh->status);
        $this->assertNull($fresh->closed_at);
        $this->assertSame(TechnicianRunState::Denied, $run->fresh()->state);
        $this->assertDatabaseHas('ticket_notes', [
            'ticket_id' => $ticket->id,
            'note_type' => NoteType::StatusChange->value,
            'status_to' => TicketStatus::InProgress->value,
            'author_id' => $this->user->id,
            'body' => 'Reopened by cockpit undo.',
        ]);
    }

    public function test_reopen_refuses_when_a_later_status_change_exists(): void
    {
        // The agent's close is no longer the LATEST status change (a human reopened
        // and re-closed since) — the current Closed state is not the agent's close,
        // so the one-click reopen must not clobber it.
        [$run, $ticket, $note] = $this->agentDirectClose();
        TicketNote::create([
            'ticket_id' => $ticket->id,
            'author_id' => $this->user->id,
            'body' => 'Closed again after review.',
            'note_type' => NoteType::StatusChange,
            'is_private' => true,
            'status_from' => TicketStatus::InProgress,
            'status_to' => TicketStatus::Closed,
            'noted_at' => $note->noted_at->copy()->addMinute(),
        ]);

        $this->actingAs($this->user)
            ->postJson(route('cockpit.reopen', $run))
            ->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('status', 'already_handled');

        $this->assertSame(TicketStatus::Closed, $ticket->fresh()->status);
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
    }

    public function test_reopen_refuses_when_the_ticket_is_no_longer_closed(): void
    {
        [$run, $ticket] = $this->agentDirectClose();
        $ticket->update(['status' => TicketStatus::InProgress, 'closed_at' => null]);

        $this->actingAs($this->user)
            ->postJson(route('cockpit.reopen', $run))
            ->assertOk()
            ->assertJsonPath('ok', false);

        $this->assertSame(TicketStatus::InProgress, $ticket->fresh()->status);
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
    }

    public function test_reopen_is_single_use(): void
    {
        [$run, $ticket] = $this->agentDirectClose();

        $this->actingAs($this->user)->postJson(route('cockpit.reopen', $run))->assertJsonPath('ok', true);
        $this->actingAs($this->user)
            ->postJson(route('cockpit.reopen', $run))
            ->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('status', 'already_handled');

        // Still exactly one reopen status note — the double-tap did nothing.
        $this->assertSame(1, TicketNote::query()
            ->where('ticket_id', $ticket->id)
            ->where('note_type', NoteType::StatusChange->value)
            ->where('status_to', TicketStatus::InProgress->value)
            ->count());
    }

    public function test_reopen_refuses_a_non_direct_close_run(): void
    {
        // Operator-approved held closes have their own (toast) undo with its own
        // 5-minute window — this durable endpoint must not extend it.
        [$run, $ticket] = $this->agentDirectClose(['action_type' => 'propose_close']);

        $this->actingAs($this->user)
            ->postJson(route('cockpit.reopen', $run))
            ->assertOk()
            ->assertJsonPath('ok', false);

        $this->assertSame(TicketStatus::Closed, $ticket->fresh()->status);
    }

    public function test_reopen_refuses_outside_the_lane_window(): void
    {
        [$run, $ticket] = $this->agentDirectClose();
        $run->created_at = now()->subHours(CockpitQuery::DIRECT_CLOSE_WINDOW_HOURS + 1);
        $run->save();

        $this->actingAs($this->user)
            ->postJson(route('cockpit.reopen', $run))
            ->assertOk()
            ->assertJsonPath('ok', false);

        $this->assertSame(TicketStatus::Closed, $ticket->fresh()->status);
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
    }

    public function test_reopen_requires_auth(): void
    {
        [$run] = $this->agentDirectClose();

        $this->post(route('cockpit.reopen', $run))->assertRedirect(route('login'));
    }
}
