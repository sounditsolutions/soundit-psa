<?php

namespace App\Services\Agent\Steering;

use App\Models\TechnicianRun;
use App\Services\Signals\SignalHub;

/**
 * Emits the operator's cockpit verdict on a held AI Technician proposal as an
 * outbound Signal, so the out-of-process MCP agent ("Chet" — which makes ~all
 * propose_close proposals today, the native lane being quiesced) receives the
 * approve/decline/correct feedback it otherwise never sees. This closes the
 * cockpit STEER/LEARN loop to the MCP surface (bd psa-0xvv) — the learning
 * sibling of the raise-hand wire (bd psa-sh7y).
 *
 * The three verdicts map to run states set by the approval layer:
 *   approved  → TechnicianRun::advanceTo(Done)     (TechnicianApprovalService)
 *   declined  → TechnicianRun::deny()  → Denied
 *   corrected → TechnicianRun::markSuperseded()    (ReassessTrigger)
 *
 * Reference-only by design: the MCP inbox payload carries event + ticket + category
 * only (see McpSink), never the summary. The correction text rides the SignalEvent
 * summary — redaction-scanned and 500-capped by SignalHub — for content sinks
 * (email/webhook) and the Alerts Hub audit trail, and is deliberately NOT pushed to
 * the MCP agent (a tested privacy boundary: McpSinkTest / PollSignalsToolTest).
 * Chet learns the outcome + the ticket to pull, not the operator's raw words.
 *
 * Fire-and-forget: SignalHub::emit swallows its own failures, so a routing or
 * redaction problem can never break the operator's cockpit action. Resolved via the
 * container (app(SignalHub::class)) to mirror the sibling emitter, EscalationNotifier.
 */
class ProposalOutcomeSignal
{
    public function approved(TechnicianRun $run): void
    {
        $this->emit('agent.proposal_approved', $run, 'approved');
    }

    public function declined(TechnicianRun $run): void
    {
        $this->emit('agent.proposal_declined', $run, 'declined');
    }

    public function corrected(TechnicianRun $run, string $correction): void
    {
        $this->emit('agent.proposal_corrected', $run, 'corrected', $correction);
    }

    private function emit(string $typeKey, TechnicianRun $run, string $verb, ?string $correction = null): void
    {
        $ticket = $run->ticket;
        if ($ticket === null) {
            // The ticket was (soft-)deleted — there is nothing for the agent to
            // reference, so there is nothing to feed back.
            return;
        }

        $summary = "Operator {$verb} the AI {$run->action_type} proposal on ticket #{$ticket->id}";
        if ($correction !== null && trim($correction) !== '') {
            $summary .= ': '.trim($correction);
        }
        $summary .= '.';

        app(SignalHub::class)->emit($typeKey, $ticket, $summary, [
            // action_type discriminates the proposal kind (propose_close, send_reply,
            // …) so an operator can route a subset to the agent and the agent can tell
            // what it is being judged on without pulling the ticket. Reference-safe:
            // it is a fixed system enum, never client-typed content.
            'category' => $run->action_type,
            'priority' => $ticket->priority_order,
            'client_id' => $run->client_id ?? $ticket->client_id,
        ]);
    }
}
