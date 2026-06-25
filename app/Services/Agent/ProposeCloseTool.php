<?php

namespace App\Services\Agent;

use App\Enums\TechnicianRunState;
use App\Enums\TicketStatus;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Services\Technician\Notify\OperatorNotifier;
use App\Services\Technician\TechnicianActionGate;
use App\Services\TicketService;
use App\Support\TechnicianConfig;

/**
 * The Backlog Agent's single gated ACT tool.
 *
 * Calling propose_close() records a held TechnicianRun and dispatches through
 * the existing TechnicianActionGate with the agent's confidence scalar:
 *
 *  Held band (default / auto threshold unset):
 *    The gate records awaiting_approval. An operator approves later (Task 8).
 *
 *  Auto band (opt-in — operator sets propose_close_auto_threshold):
 *    The gate runs the executor, which closes the ticket to Closed (silent —
 *    NOT Resolved, which would dispatch a client portal email). The operator is
 *    then notified AFTER dispatch returns, never inside the executor.
 *
 * Design constraints:
 *  - Gate is the SOLE execute/audit chokepoint (spec §4.3).
 *  - Idempotency guard (CO-4): an existing AwaitingApproval run for the same
 *    content is NOT re-dispatched (prevents duplicate audit rows).
 *  - Stale-ticket guard (CO-23): the executor re-fetches the ticket before
 *    closing — if a human closed it between classify and execute, the run is
 *    advanced to Done without touching the ticket.
 *  - Notify is post-'executed', never inside the executor (which is inside a
 *    DB transaction — external calls must not go inside that boundary).
 */
class ProposeCloseTool
{
    public function __construct(
        private readonly TechnicianActionGate $gate,
        private readonly OperatorNotifier $notifier,
    ) {}

    /** The Anthropic tool definition for `propose_close`. */
    public static function definition(): array
    {
        return [
            'name' => 'propose_close',
            'description' => 'Propose closing a stale ticket. The proposal is held for operator approval unless the operator has configured an auto-close confidence threshold and the confidence clears it. Always provide concrete, ticket-specific evidence in the reason — cite dates, what the original issue was, and why closing is safe without further action.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'reason' => [
                        'type' => 'string',
                        'description' => 'Why this ticket should be closed, with specific evidence (e.g. "No client response in 30 days; the original issue — a printer jam — was confirmed resolved in the last technician note on 2026-05-15").',
                    ],
                    'confidence' => [
                        'type' => 'number',
                        'description' => 'Confidence from 0 to 1 that closing is the right action. Use ≥0.95 only when the evidence is unambiguous.',
                    ],
                ],
                'required' => ['reason', 'confidence'],
            ],
        ];
    }

    /**
     * Record a held TechnicianRun proposal and dispatch through the gate.
     *
     * Returns a short string the model sees in its tool_result.
     */
    public function execute(Ticket $ticket, array $input): string
    {
        $reason = trim((string) ($input['reason'] ?? ''));
        $confidence = (float) ($input['confidence'] ?? 0.0);
        $hash = hash('sha256', 'propose_close:'.$ticket->id.':'.$reason);

        $run = TechnicianRun::firstOrCreate(
            [
                'ticket_id' => $ticket->id,
                'action_type' => 'propose_close',
                'content_hash' => $hash,
            ],
            [
                'client_id' => $ticket->client_id,
                'state' => TechnicianRunState::AwaitingApproval,
                'proposed_content' => $reason,
                'proposed_meta' => ['confidence' => $confidence],
                'confidence' => $confidence,
                'tokens_used' => 0,
            ],
        );

        // Idempotency guard (CO-4): an existing AwaitingApproval run for the same
        // content hash means we already proposed this — do NOT re-dispatch (which
        // would write a duplicate awaiting_approval audit row). Mirror recordHeld's
        // revive/return logic: revive stale (Done/Denied/Superseded) runs so the
        // cockpit never loses visibility; return early only for the AwaitingApproval case.
        if (! $run->wasRecentlyCreated) {
            if ($run->state === TechnicianRunState::AwaitingApproval) {
                return "Already proposed closing ticket #{$ticket->id}; awaiting approval.";
            }
            // Revive a stale run so the cockpit can re-surface it.
            $run->update([
                'state' => TechnicianRunState::AwaitingApproval->value,
                'proposed_content' => $reason,
                'proposed_meta' => ['confidence' => $confidence],
                'confidence' => $confidence,
                'tokens_used' => 0,
            ]);
        }

        $summary = "Backlog agent proposes closing ticket #{$ticket->id}: {$reason}";

        $result = $this->gate->dispatch(
            actionType: 'propose_close',
            ticketId: $ticket->id,
            clientId: $ticket->client_id,
            contentHash: $hash,
            summary: $summary,
            runId: $run->id,
            executor: function () use ($ticket, $run): void {
                // CO-23: re-check before closing — a human may have closed this ticket
                // between the gate's classify call and this executor call. Guard ONLY
                // against the already-at-target-state race (=== Closed): advance the run
                // to Done so it does not linger, but do NOT attempt a double-close.
                // A Resolved ticket IS auto-eligible (CloseAutoEligibility) and
                // Resolved → Closed is an allowed transition — it must actually close,
                // not early-return — so the guard is the exact terminal state, not isOpen().
                if ($ticket->fresh()->status === TicketStatus::Closed) {
                    $run->advanceTo(TechnicianRunState::Done);

                    return;
                }

                // Close to Closed (silent): Resolved dispatches a client portal email
                // (status_resolved); Closed does not. The deliberate close path is Closed.
                app(TicketService::class)->changeStatus(
                    $ticket,
                    TicketStatus::Closed,
                    TechnicianConfig::aiActorUserId(),
                    'Closed by backlog agent (high confidence, no recent client activity).',
                );

                $run->advanceTo(TechnicianRunState::Done);
            },
            confidence: $confidence,
        );

        // CO-21: notify the operator AFTER dispatch returns 'executed'.
        // NEVER notify inside the executor — the executor runs inside a DB transaction;
        // external sends (Teams/email) must not cross that boundary.
        if ($result->status === 'executed') {
            $pct = number_format($confidence * 100, 1);
            $this->notifier->notify(
                "Backlog agent closed ticket #{$ticket->id}",
                "Ticket #{$ticket->id} was automatically closed by the backlog agent.\n\nReason: {$reason}\n\nConfidence: {$pct}%",
            );

            return "Closed #{$ticket->id} (high confidence). Operator notified.";
        }

        return "Recorded a close proposal for #{$ticket->id}; held for approval.";
    }
}
