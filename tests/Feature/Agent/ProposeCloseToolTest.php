<?php

namespace Tests\Feature\Agent;

use App\Enums\NoteType;
use App\Enums\TechnicianRunState;
use App\Enums\TechnicianTier;
use App\Enums\TicketStatus;
use App\Jobs\SendPortalNotification;
use App\Models\Setting;
use App\Models\TechnicianActionLog;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Agent\ProposeCloseTool;
use App\Services\Technician\Notify\OperatorNotifier;
use App\Services\Technician\TechnicianActionGate;
use App\Services\Technician\TechnicianActionResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * ProposeCloseTool — the AI Technician's single gated ACT tool.
 *
 * Covers:
 *  1. Held by default (auto off): high confidence, no threshold → awaiting_approval; ticket untouched; notifier silent.
 *  2. Auto closes silently + notifies (CO-18/CO-21): threshold set, eligible open ticket, high confidence → Closed + notifier called + NO client SendPortalNotification.
 *  3. Idempotency (CO-4): same reason twice → exactly one TechnicianRun and one awaiting_approval audit row.
 *  4. Stale-ticket guard (CO-23): ticket closed between classify and execute → executor early-returns (advanceTo Done), no exception, no double status change.
 */
class ProposeCloseToolTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // First user = AI actor (TechnicianConfig::aiActorUserId() falls back to first user).
        User::factory()->create();
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * A ticket in an open, auto-safe status (PendingClient) with no recent client note.
     * PendingClient: isOpen()=true AND in CloseAutoEligibility::AUTO_SAFE_STATUSES.
     */
    private function eligibleOpenTicket(): Ticket
    {
        return Ticket::factory()->create(['status' => TicketStatus::PendingClient]);
    }

    private function setAutoThreshold(float $threshold): void
    {
        Setting::setValue('propose_close_auto_threshold', (string) $threshold);
    }

    private function tool(): ProposeCloseTool
    {
        return app(ProposeCloseTool::class);
    }

    // ── 1. Held by default ────────────────────────────────────────────────────

    public function test_held_by_default_when_no_auto_threshold_set(): void
    {
        // Auto threshold unset → null → never auto (safe default for propose_close).
        $ticket = $this->eligibleOpenTicket();

        $notifier = $this->mock(OperatorNotifier::class);
        $notifier->shouldReceive('notify')->never();

        $this->tool()->execute($ticket, [
            'reason' => 'No client response in 45 days.',
            'confidence' => 0.99,
        ]);

        // TechnicianRun must exist in AwaitingApproval state.
        $run = TechnicianRun::where('ticket_id', $ticket->id)
            ->where('action_type', 'propose_close')
            ->first();
        $this->assertNotNull($run);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);
        $this->assertEqualsWithDelta(0.99, $run->confidence, 0.001);
        $this->assertSame('No client response in 45 days.', $run->proposed_content);

        // Gate must record awaiting_approval, NOT executed.
        $this->assertDatabaseHas('technician_action_logs', [
            'ticket_id' => $ticket->id,
            'action_type' => 'propose_close',
            'result_status' => 'awaiting_approval',
        ]);
        $this->assertDatabaseMissing('technician_action_logs', [
            'ticket_id' => $ticket->id,
            'result_status' => 'executed',
        ]);

        // Ticket must NOT be closed.
        $ticket->refresh();
        $this->assertNotSame(TicketStatus::Closed, $ticket->status);
    }

    // ── 2. Auto closes silently + notifies (CO-18/CO-21) ─────────────────────

    public function test_auto_closes_to_closed_silently_and_notifies_operator(): void
    {
        Queue::fake(); // captures any queued jobs so we can assert SendPortalNotification absent

        $this->setAutoThreshold(0.95);
        $ticket = $this->eligibleOpenTicket(); // PendingClient, no recent EndUser note

        $notifier = $this->mock(OperatorNotifier::class);
        $notifier->shouldReceive('notify')
            ->once()
            ->withArgs(fn (string $subject, string $body): bool => str_contains($subject, (string) $ticket->id) &&
                str_contains($body, (string) $ticket->id) &&
                str_contains($body, '97.0%')
            );

        $this->tool()->execute($ticket, [
            'reason' => 'No client reply in 30+ days; original issue confirmed resolved.',
            'confidence' => 0.97,
        ]);

        // Ticket must be Closed (not Resolved — Resolved fires a client portal email).
        $ticket->refresh();
        $this->assertSame(TicketStatus::Closed, $ticket->status);
        $this->assertNotNull($ticket->closed_at);

        // Gate must record executed.
        $this->assertDatabaseHas('technician_action_logs', [
            'ticket_id' => $ticket->id,
            'action_type' => 'propose_close',
            'result_status' => 'executed',
        ]);

        // TechnicianRun must be Done.
        $run = TechnicianRun::where('ticket_id', $ticket->id)
            ->where('action_type', 'propose_close')
            ->first();
        $this->assertNotNull($run);
        $this->assertSame(TechnicianRunState::Done, $run->state);

        // CRITICAL (CO-18): closing to Closed must NOT dispatch a client portal notification.
        // (Resolved would have dispatched status_resolved; Closed is deliberately silent.)
        Queue::assertNotPushed(SendPortalNotification::class);
    }

    public function test_auto_eligible_resolved_ticket_actually_closes(): void
    {
        // Resolved IS auto-eligible (in CloseAutoEligibility::AUTO_SAFE_STATUSES) and
        // Resolved → Closed is an allowed transition. The stale guard (=== Closed) must
        // NOT short-circuit a Resolved ticket — it must actually close to Closed.
        Queue::fake();

        $this->setAutoThreshold(0.95);
        $ticket = Ticket::factory()->create(['status' => TicketStatus::Resolved]); // eligible, no recent EndUser note

        $notifier = $this->mock(OperatorNotifier::class);
        $notifier->shouldReceive('notify')->once();

        $this->tool()->execute($ticket, [
            'reason' => 'Resolved 21 days ago; grace period elapsed with no client pushback.',
            'confidence' => 0.98,
        ]);

        // Must actually transition Resolved → Closed (not early-return on the stale guard).
        $ticket->refresh();
        $this->assertSame(TicketStatus::Closed, $ticket->status);
        $this->assertNotNull($ticket->closed_at);

        $this->assertDatabaseHas('technician_action_logs', [
            'ticket_id' => $ticket->id,
            'action_type' => 'propose_close',
            'result_status' => 'executed',
        ]);

        $run = TechnicianRun::where('ticket_id', $ticket->id)
            ->where('action_type', 'propose_close')
            ->first();
        $this->assertSame(TechnicianRunState::Done, $run->state);

        // Closed is silent: no client portal notification.
        Queue::assertNotPushed(SendPortalNotification::class);
    }

    // ── 3. Idempotency (CO-4) ─────────────────────────────────────────────────

    public function test_two_executes_with_same_reason_produce_exactly_one_run_and_one_audit_row(): void
    {
        // No threshold — both calls land in held mode.
        $ticket = $this->eligibleOpenTicket();
        $reason = 'Stale: no client response in 60 days.';

        $notifier = $this->mock(OperatorNotifier::class);
        $notifier->shouldReceive('notify')->never();

        $tool = $this->tool();
        $first = $tool->execute($ticket, ['reason' => $reason, 'confidence' => 0.85]);
        $second = $tool->execute($ticket, ['reason' => $reason, 'confidence' => 0.85]);

        // Second call should signal "already proposed" (idempotent early-return).
        $this->assertStringContainsString('awaiting approval', $second);

        // Exactly one TechnicianRun for this content.
        $this->assertSame(
            1,
            TechnicianRun::where('ticket_id', $ticket->id)
                ->where('action_type', 'propose_close')
                ->count(),
        );

        // Exactly one awaiting_approval audit row — the second call must NOT re-dispatch.
        $this->assertSame(
            1,
            TechnicianActionLog::where('ticket_id', $ticket->id)
                ->where('action_type', 'propose_close')
                ->where('result_status', 'awaiting_approval')
                ->count(),
        );
    }

    // ── 4. Stale-ticket guard (CO-23) ─────────────────────────────────────────

    public function test_stale_ticket_guard_advances_run_to_done_without_double_close(): void
    {
        // Arrange: threshold set, eligible open ticket.
        $this->setAutoThreshold(0.95);
        $ticket = $this->eligibleOpenTicket();

        // The notifier will be called because the mock returns result='executed'.
        $notifier = $this->mock(OperatorNotifier::class);
        $notifier->shouldReceive('notify')->once();

        // Mock the gate to simulate the race condition: the ticket is closed by a human
        // BETWEEN the gate's classify call (which saw PendingClient → Auto) and the
        // executor call. The mock closes the ticket via raw SQL, then calls the executor.
        // The executor must detect the closure and early-return (advanceTo Done) without
        // calling changeStatus again.
        $this->mock(TechnicianActionGate::class, function (MockInterface $m) use ($ticket): void {
            $m->shouldReceive('dispatch')
                ->once()
                ->andReturnUsing(function (
                    string $actionType,
                    int $ticketId,
                    ?int $clientId,
                    string $contentHash,
                    string $summary,
                    ?int $runId,
                    callable $executor,
                    ?string $approvalToken = null,
                    ?int $approverUserId = null,
                    ?float $confidence = null,
                ) use ($ticket): TechnicianActionResult {
                    // Simulate: a human closes the ticket in the race window.
                    DB::table('tickets')->where('id', $ticket->id)->update([
                        'status' => TicketStatus::Closed->value,
                        'closed_at' => now()->toDateTimeString(),
                        'resolved_at' => now()->toDateTimeString(),
                        'updated_at' => now()->toDateTimeString(),
                    ]);

                    // Call the executor as the gate would in Auto mode.
                    $executor();

                    $log = TechnicianActionLog::create([
                        'actor_id' => User::first()->id,
                        'actor_label' => 'ai-technician',
                        'action_type' => $actionType,
                        'tier' => TechnicianTier::Auto->value,
                        'result_status' => 'executed',
                        'ticket_id' => $ticketId,
                        'client_id' => $clientId,
                        'run_id' => $runId,
                        'content_hash' => $contentHash,
                        'summary' => $summary,
                        'correlation_id' => (string) Str::uuid(),
                    ]);

                    return new TechnicianActionResult('executed', TechnicianTier::Auto, $log);
                });
        });

        // Act — must not throw.
        $this->tool()->execute($ticket, [
            'reason' => 'Ghost ticket — no client activity for 90 days.',
            'confidence' => 0.97,
        ]);

        // Run must be Done (executor fired but early-returned via the stale guard).
        $run = TechnicianRun::where('ticket_id', $ticket->id)
            ->where('action_type', 'propose_close')
            ->first();
        $this->assertNotNull($run);
        $this->assertSame(TechnicianRunState::Done, $run->state);

        // Ticket must still be Closed — NOT changed again by the executor.
        $ticket->refresh();
        $this->assertSame(TicketStatus::Closed, $ticket->status);

        // No status-change note was created by the executor (it early-returned before changeStatus).
        $this->assertDatabaseMissing('ticket_notes', [
            'ticket_id' => $ticket->id,
            'note_type' => NoteType::StatusChange->value,
        ]);
    }
}
