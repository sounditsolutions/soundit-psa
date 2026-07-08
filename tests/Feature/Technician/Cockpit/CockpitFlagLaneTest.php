<?php

namespace Tests\Feature\Technician\Cockpit;

use App\Enums\TechnicianRunState;
use App\Enums\TicketStatus;
use App\Models\Client;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Technician\Cockpit\CockpitQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The "Flagged for your attention" cockpit lane (Increment H).
 *
 * A flag is a NOTICE, not an executable proposal: it lives in its own Flagged
 * state, in its own lane, resolved by acknowledge (→ Done) or dismiss (→ Denied)
 * — NEVER by the approve/execute path. This suite pins the lane's distinctness
 * from the two existing lanes and the no-execution lifecycle.
 */
class CockpitFlagLaneTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function ticket(): Ticket
    {
        $client = Client::factory()->create();

        return Ticket::factory()->for($client)->create(['status' => TicketStatus::InProgress]);
    }

    private function flagRun(Ticket $ticket, TechnicianRunState $state = TechnicianRunState::Flagged, string $reason = 'Needs an owner decision I cannot make.'): TechnicianRun
    {
        return TechnicianRun::create([
            'ticket_id' => $ticket->id,
            'client_id' => $ticket->client_id,
            'action_type' => 'flag_attention',
            'content_hash' => 'flag-'.$ticket->id,
            'state' => $state,
            'proposed_content' => $reason,
            'proposed_meta' => ['category' => 'needs_decision', 'reason' => $reason],
            'tokens_used' => 0,
        ]);
    }

    private function closeRun(Ticket $ticket): TechnicianRun
    {
        return TechnicianRun::create([
            'ticket_id' => $ticket->id,
            'client_id' => $ticket->client_id,
            'action_type' => 'propose_close',
            'content_hash' => 'close-'.$ticket->id,
            'state' => TechnicianRunState::AwaitingApproval,
            'proposed_content' => 'stale; safe to close',
            'proposed_meta' => ['confidence' => 0.5],
            'confidence' => 0.5,
            'tokens_used' => 0,
        ]);
    }

    // ── lane membership ──────────────────────────────────────────────────────

    public function test_flagged_lane_lists_held_flags_only(): void
    {
        $flag = $this->flagRun($this->ticket());
        $this->closeRun($this->ticket());                                   // a proposal, not a flag
        $this->flagRun($this->ticket(), TechnicianRunState::Done);          // an already-acknowledged flag

        $lane = app(CockpitQuery::class)->flaggedForAttention();

        $this->assertCount(1, $lane);
        $this->assertSame($flag->id, $lane->first()->id);
    }

    public function test_approval_lane_excludes_flags_and_flag_lane_excludes_proposals(): void
    {
        $flag = $this->flagRun($this->ticket());
        $close = $this->closeRun($this->ticket());

        $query = app(CockpitQuery::class);
        $draftIds = $query->pendingDrafts()->pluck('id');
        $flagIds = $query->flaggedForAttention()->pluck('id');

        $this->assertTrue($draftIds->contains($close->id), 'the proposal belongs to the approval lane');
        $this->assertFalse($draftIds->contains($flag->id), 'a flag must NOT appear in the approval lane');
        $this->assertTrue($flagIds->contains($flag->id));
        $this->assertFalse($flagIds->contains($close->id));
    }

    public function test_pending_count_includes_both_proposals_and_flags(): void
    {
        $this->closeRun($this->ticket());
        $this->flagRun($this->ticket());

        $this->assertSame(2, app(CockpitQuery::class)->pendingCount());
    }

    // ── resolve lifecycle (no execution) ─────────────────────────────────────

    public function test_acknowledging_a_flag_resolves_it_to_done_without_touching_the_ticket(): void
    {
        $ticket = $this->ticket();
        $original = $ticket->status;
        $flag = $this->flagRun($ticket);

        $this->actingAs($this->user)
            ->post(route('cockpit.acknowledge', $flag))
            ->assertRedirect(route('cockpit.index'));

        $this->assertSame(TechnicianRunState::Done, $flag->fresh()->state);
        $this->assertSame($original, $ticket->fresh()->status, 'acknowledging a flag must not touch the ticket');
    }

    public function test_dismissing_a_flag_resolves_it_to_denied(): void
    {
        $flag = $this->flagRun($this->ticket());

        $this->actingAs($this->user)
            ->post(route('cockpit.dismiss', $flag))
            ->assertRedirect(route('cockpit.index'));

        $this->assertSame(TechnicianRunState::Denied, $flag->fresh()->state);
    }

    public function test_a_flag_can_never_be_approved_or_executed(): void
    {
        $ticket = $this->ticket();
        $original = $ticket->status;
        $flag = $this->flagRun($ticket);

        // The approve path is for executable proposals only; a flag must be refused.
        $this->actingAs($this->user)
            ->post(route('cockpit.approve', $flag))
            ->assertStatus(422);

        $this->assertSame(TechnicianRunState::Flagged, $flag->fresh()->state, 'a flag must remain held — never executed');
        $this->assertSame($original, $ticket->fresh()->status);
    }

    public function test_acknowledge_only_acts_on_a_flagged_run(): void
    {
        // A propose_close (AwaitingApproval) is NOT a flag — acknowledge must not resolve it.
        $close = $this->closeRun($this->ticket());

        $this->actingAs($this->user)->post(route('cockpit.acknowledge', $close));

        $this->assertSame(TechnicianRunState::AwaitingApproval, $close->fresh()->state);
    }

    // ── rendering ────────────────────────────────────────────────────────────

    public function test_cockpit_page_renders_the_flag_lane(): void
    {
        $this->flagRun($this->ticket(), reason: 'Refund decision needed from the owner.');

        $this->actingAs($this->user)
            ->get(route('cockpit.index'))
            ->assertOk()
            ->assertSee('Refund decision needed from the owner.')
            ->assertSee('Flagged for your attention');
    }
}
