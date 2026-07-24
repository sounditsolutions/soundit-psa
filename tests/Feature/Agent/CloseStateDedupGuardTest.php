<?php

namespace Tests\Feature\Agent;

use App\Enums\TechnicianRunState;
use App\Enums\TicketStatus;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Agent\ProposeCloseTool;
use App\Services\Technician\Notify\OperatorNotifier;
use App\Services\Technician\TechnicianApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The auto-close STATE/DEDUP guard (psa-y4ft). Confidence-agnostic, held-safe,
 * ships dormant. Three parts:
 *   1. Already-closed rejection — propose_close on a Closed ticket returns a
 *      failure and creates no run (Chet learns and moves on).
 *   2. Ticket-level dedup — no 2nd close proposal while one is already pending
 *      (fixes the content-hash gap that let ticket 22482 be proposed twice).
 *   3. Auto-withdraw — when a ticket is Closed by anyone, its held close
 *      proposals are withdrawn (removes the manual "duplicate" cleanup).
 */
class CloseStateDedupGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Tier-1 (psa-2f0bg): selectively fake ONLY the staged-action notification job.
        // These tests' subject is the agent's own synchronous behaviour; the observer's
        // async notify on AwaitingApproval is a separate concern with its own test
        // (StagedActionNotificationTest). Faking just this job leaves all other jobs live.
        \Illuminate\Support\Facades\Bus::fake([\App\Jobs\NotifyStagedActionAwaitingApproval::class]);
        User::factory()->create(); // first user = AI actor fallback
    }

    private function silentNotifier(): void
    {
        $this->mock(OperatorNotifier::class)->shouldReceive('notify')->never();
    }

    private function tool(): ProposeCloseTool
    {
        return app(ProposeCloseTool::class);
    }

    // ── Part 1: already-closed rejection ─────────────────────────────────────

    public function test_propose_close_on_an_already_closed_ticket_is_rejected_without_a_run(): void
    {
        $this->silentNotifier();
        $ticket = Ticket::factory()->create(['status' => TicketStatus::Closed]);

        $result = $this->tool()->execute($ticket, ['reason' => 'Looks resolved.', 'confidence' => 0.95]);

        $this->assertStringContainsString('already closed', strtolower($result));
        $this->assertSame(0, TechnicianRun::where('ticket_id', $ticket->id)->count(),
            'no held run may be created for an already-closed ticket');
        $this->assertDatabaseMissing('technician_action_logs', ['ticket_id' => $ticket->id]);
    }

    public function test_propose_close_on_an_open_ticket_still_creates_a_held_run(): void
    {
        $this->silentNotifier();
        $ticket = Ticket::factory()->create(['status' => TicketStatus::PendingClient]);

        $this->tool()->execute($ticket, ['reason' => 'No client reply in 45 days.', 'confidence' => 0.9]);

        $run = TechnicianRun::where('ticket_id', $ticket->id)->where('action_type', 'propose_close')->first();
        $this->assertNotNull($run, 'an open ticket must still get a held proposal');
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);
    }

    // ── Part 2: ticket-level dedup at proposal time ──────────────────────────

    public function test_a_second_proposal_with_a_different_reason_is_deduped_to_one_run(): void
    {
        $this->silentNotifier();
        $ticket = Ticket::factory()->create(['status' => TicketStatus::PendingClient]);

        $this->tool()->execute($ticket, ['reason' => 'No reply in 30 days.', 'confidence' => 0.8]);
        // Different reason text → different content_hash: this slipped past the
        // content-hash idempotency (the ticket 22482 double-proposal). It must now
        // be deduped at ticket level.
        $second = $this->tool()->execute($ticket, ['reason' => 'Resolved via phone; never administratively closed.', 'confidence' => 0.85]);

        $this->assertSame(1, TechnicianRun::where('ticket_id', $ticket->id)->where('action_type', 'propose_close')->count(),
            'a ticket may have at most one pending close proposal');
        $this->assertStringContainsString('already', strtolower($second));
        $this->assertStringContainsString('awaiting approval', strtolower($second));
    }

    public function test_a_new_proposal_is_allowed_after_the_prior_one_was_denied(): void
    {
        // Ticket-level dedup blocks only a PENDING proposal — a terminal (Denied)
        // outcome must not permanently bar Chet from re-proposing later.
        $this->silentNotifier();
        $ticket = Ticket::factory()->create(['status' => TicketStatus::PendingClient]);

        $this->tool()->execute($ticket, ['reason' => 'First take.', 'confidence' => 0.8]);
        TechnicianRun::where('ticket_id', $ticket->id)->firstOrFail()->deny();

        $this->tool()->execute($ticket, ['reason' => 'Second take, new evidence.', 'confidence' => 0.9]);

        $pending = TechnicianRun::where('ticket_id', $ticket->id)
            ->where('action_type', 'propose_close')
            ->where('state', TechnicianRunState::AwaitingApproval->value)
            ->count();
        $this->assertSame(1, $pending, 'a fresh proposal is allowed once the prior one is terminal');
    }

    // ── Part 3: auto-withdraw held proposals when the ticket is Closed ────────

    public function test_a_held_close_proposal_is_withdrawn_when_the_ticket_is_closed(): void
    {
        Queue::fake();
        $this->silentNotifier();
        $ticket = Ticket::factory()->create(['status' => TicketStatus::PendingClient]);
        $this->tool()->execute($ticket, ['reason' => 'Stale — no reply.', 'confidence' => 0.8]);

        $run = TechnicianRun::where('ticket_id', $ticket->id)->firstOrFail();
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);

        // A human (or anything) closes the ticket while the proposal is still held.
        $ticket->update(['status' => TicketStatus::Closed]);

        $run->refresh();
        $this->assertSame(TechnicianRunState::Withdrawn, $run->state,
            'a held close proposal is auto-withdrawn when its ticket is Closed');
    }

    public function test_approving_a_held_close_ends_done_not_withdrawn(): void
    {
        // The approve path claims the run to Executing BEFORE it closes the ticket,
        // so the close observer's (awaiting_approval-only) withdraw must NOT clobber it.
        Queue::fake();
        $this->silentNotifier();
        $ticket = Ticket::factory()->create(['status' => TicketStatus::PendingClient]);
        $this->tool()->execute($ticket, ['reason' => 'No reply in 60 days.', 'confidence' => 0.9]);
        $run = TechnicianRun::where('ticket_id', $ticket->id)->firstOrFail();

        $operator = User::factory()->create();
        app(TechnicianApprovalService::class)->approveClose($run, $operator->id);

        $run->refresh();
        $this->assertSame(TechnicianRunState::Done, $run->state,
            'the approved close ends Done, not clobbered to Withdrawn by the close observer');
        $ticket->refresh();
        $this->assertSame(TicketStatus::Closed, $ticket->status);
    }
}
