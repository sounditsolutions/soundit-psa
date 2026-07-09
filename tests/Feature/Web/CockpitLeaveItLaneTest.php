<?php

namespace Tests\Feature\Web;

use App\Enums\TicketStatus;
use App\Models\AssistantConversation;
use App\Models\Client;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Agent\Steering\CorrectionRecorder;
use App\Services\Agent\Steering\LeaveItOutcomeRecorder;
use App\Services\Technician\Cockpit\CockpitQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * psa-3q0c — the cockpit surfaces correction-driven "left as-is" outcomes.
 *
 * A leave-it outcome is an assistant turn on a ticket_correction conversation
 * (LeaveItOutcomeRecorder). The "Re-assessed from your correction" lane shows a
 * thread only when its MOST RECENT turn is such a leave-it, within a 48h window,
 * for a still-open ticket on an active client.
 */
class CockpitLeaveItLaneTest extends TestCase
{
    use RefreshDatabase;

    private function query(): CockpitQuery
    {
        return app(CockpitQuery::class);
    }

    private function openTicket(): Ticket
    {
        $client = Client::factory()->create();

        return Ticket::factory()->for($client)->create([
            'status' => TicketStatus::InProgress,
            'subject' => 'Printer keeps jamming',
        ]);
    }

    /** Correction (user turn) + agent leave-it (assistant turn) on the ticket's correction thread. */
    private function recordLeaveIt(Ticket $ticket, string $correction, string $reason): AssistantConversation
    {
        $operator = User::factory()->create();
        $conversation = app(CorrectionRecorder::class)->record($ticket, $operator, $correction, null);
        app(LeaveItOutcomeRecorder::class)->record($conversation, $reason);

        return $conversation;
    }

    // ── the query ─────────────────────────────────────────────────────────────

    public function test_surfaces_a_recent_leave_it_outcome(): void
    {
        $ticket = $this->openTicket();
        $this->recordLeaveIt($ticket, 'Keep this open please.', 'Client is awaiting a vendor callback.');

        $rows = $this->query()->reassessedLeftAsIs();

        $this->assertCount(1, $rows);
        $this->assertSame($ticket->id, $rows->first()->ticket->id);
        $this->assertStringContainsString('Client is awaiting a vendor callback', $rows->first()->note);
        $this->assertStringStartsWith(LeaveItOutcomeRecorder::NOTE_PREFIX, $rows->first()->note);
    }

    /** A newer operator correction (user turn) supersedes the leave-it → thread drops out of the lane. */
    public function test_excludes_a_thread_whose_latest_turn_is_a_newer_correction(): void
    {
        $ticket = $this->openTicket();
        $conversation = $this->recordLeaveIt($ticket, 'first correction', 'left it once');

        // Operator corrects again — a newer user turn is now the latest message (re-assessment in flight).
        $conversation->messages()->create(['role' => 'user', 'content' => 'actually, reconsider this']);

        $this->assertTrue($this->query()->reassessedLeftAsIs()->isEmpty(),
            'an in-flight newer correction must supersede the stale leave-it note');
    }

    /** Outcomes older than the window self-clear. */
    public function test_excludes_a_stale_leave_it_outside_the_window(): void
    {
        $ticket = $this->openTicket();

        $this->travel(-49)->hours();
        $this->recordLeaveIt($ticket, 'keep open', 'old reason');
        $this->travelBack();

        $this->assertTrue($this->query()->reassessedLeftAsIs()->isEmpty(),
            'a leave-it older than 48h must not surface');
    }

    /** A closed ticket is no longer actionable — its leave-it note drops out. */
    public function test_excludes_a_closed_ticket(): void
    {
        $ticket = $this->openTicket();
        $this->recordLeaveIt($ticket, 'keep open', 'left it');

        $ticket->update(['status' => TicketStatus::Closed]);

        $this->assertTrue($this->query()->reassessedLeftAsIs()->isEmpty());
    }

    /** An inactive client drops out (mirrors every other cockpit lane). */
    public function test_excludes_an_inactive_client(): void
    {
        $ticket = $this->openTicket();
        $this->recordLeaveIt($ticket, 'keep open', 'left it');

        $ticket->client->update(['is_active' => false]);

        $this->assertTrue($this->query()->reassessedLeftAsIs()->isEmpty());
    }

    /** Ordinary correction threads with NO leave-it turn (only user corrections) never surface here. */
    public function test_ignores_correction_threads_with_no_leave_it_turn(): void
    {
        $ticket = $this->openTicket();
        $operator = User::factory()->create();
        app(CorrectionRecorder::class)->record($ticket, $operator, 'a correction with no leave-it outcome', null);

        $this->assertTrue($this->query()->reassessedLeftAsIs()->isEmpty());
    }

    // ── the view ──────────────────────────────────────────────────────────────

    public function test_cockpit_renders_the_leave_it_note(): void
    {
        $ticket = $this->openTicket();
        $this->recordLeaveIt($ticket, 'Keep this open please.', 'Client is awaiting a vendor callback.');

        $response = $this->actingAs(User::factory()->create())->get(route('cockpit.index'));

        $response->assertOk();
        $response->assertSee('Re-assessed from your correction');
        $response->assertSee('Client is awaiting a vendor callback');
        $response->assertSee('Printer keeps jamming');
    }

    public function test_cockpit_omits_the_lane_when_there_are_no_leave_it_outcomes(): void
    {
        $response = $this->actingAs(User::factory()->create())->get(route('cockpit.index'));

        $response->assertOk();
        $response->assertDontSee('Re-assessed from your correction');
    }
}
