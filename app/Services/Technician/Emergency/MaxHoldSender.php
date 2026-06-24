<?php

namespace App\Services\Technician\Emergency;

use App\Enums\NoteType;
use App\Enums\WhoType;
use App\Models\TechnicianEmergency;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Services\EmailService;
use App\Services\Technician\TechnicianActionGate;
use App\Services\Technician\TechnicianDisclosure;
use App\Support\TechnicianConfig;
use Illuminate\Support\Facades\Log;

/**
 * The ONE autonomous client-facing send in Phase 2 (spec §6 "honest max-hold").
 *
 * When the deterministic escalation has exhausted the chain and nobody is
 * reachable, the sweep calls this once to tell the client — honestly, with the
 * structural AI disclosure — that we've flagged it urgent and are working to get
 * a technician to them. It mirrors AutoAcknowledge EXACTLY: build the disclosed
 * body, route it through the gate as the action `send_max_hold` (which only fires
 * AUTO when the operator has mapped it so; otherwise the gate HOLDS it as
 * awaiting_approval and nothing is sent — the safe default during the trip), then
 * — once the gate has executed (note + audit committed atomically) — actually
 * email the client OUTSIDE the gate transaction and link the email.
 *
 * CO-9 (the safety-critical bit): because the sweep can tick concurrently, the
 * once-guard is an ATOMIC CAS CLAIM on max_hold_sent_at taken BEFORE anything
 * else — two ticks cannot both read NULL then both send. The claim is REVERTED if
 * the gate does not execute (held / awaiting_approval) or anything throws, so a
 * later LEGIT auto-send (once the operator maps it) is never permanently
 * suppressed. A note written with no deliverable contact email LEAVES the claim
 * set on purpose — it must not re-fire forever chasing a send that cannot happen.
 */
class MaxHoldSender
{
    public function __construct(
        private readonly TechnicianActionGate $gate,
        private readonly TechnicianDisclosure $disclosure,
        private readonly EmailService $email,
    ) {}

    public function send(TechnicianEmergency $e, Ticket $ticket): void
    {
        // (1) CO-9 — atomic once-guard. CAS-claim the send BEFORE doing anything:
        //     only the tick that flips NULL → now() proceeds; a concurrent tick
        //     (or a re-run) matches 0 rows and returns. This closes the read-then-
        //     write race two sweep ticks would otherwise lose.
        $claimed = TechnicianEmergency::where('id', $e->id)
            ->whereNull('max_hold_sent_at')
            ->update(['max_hold_sent_at' => now()]);

        if (! $claimed) {
            return;
        }

        // Keep the in-memory model consistent with the row we just claimed.
        $e->max_hold_sent_at = now();

        try {
            // (2) Build the disclosed body and structurally assert the disclosure is
            //     present BEFORE the send (fail-closed — never an undisclosed send).
            $actorId = TechnicianConfig::aiActorUserId();
            $actorName = TechnicianConfig::aiActorName();
            $body = $this->disclosure->withDisclosure(TechnicianConfig::maxHoldMessage(), $actorName);
            $this->disclosure->assertPresent($body);

            // (3) Stable content hash for the gate's audit/idempotency.
            $contentHash = hash('sha256', 'send_max_hold:'.$ticket->id.':'.$body);

            $createdNote = null;

            // (4) Route through the gate. The note is created INSIDE the gate
            //     transaction so note + audit commit together (clean retry). The
            //     client email is sent by US AFTER 'executed', never inside the tx.
            $result = $this->gate->dispatch(
                actionType: 'send_max_hold',
                ticketId: $ticket->id,
                clientId: $ticket->client_id,
                contentHash: $contentHash,
                summary: 'Sent the honest max-hold holding message to the client.',
                runId: null,
                executor: function () use ($ticket, $actorId, $actorName, $body, &$createdNote): void {
                    $createdNote = TicketNote::create([
                        'ticket_id' => $ticket->id,
                        'author_id' => $actorId,
                        'author_name' => $actorName,
                        'who_type' => WhoType::Agent,
                        'ai_authored' => true,
                        'body' => $body,
                        'note_type' => NoteType::Reply,
                        'is_private' => false,
                        'noted_at' => now(),
                    ]);
                },
            );

            // (5) Not executed (held / awaiting_approval / blocked) ⇒ REVERT the
            //     claim so a future legit auto-send is not permanently suppressed.
            if ($result->status !== 'executed' || $createdNote === null) {
                $e->update(['max_hold_sent_at' => null]);
                $e->max_hold_sent_at = null;

                return;
            }

            // Executed: the note is committed and the claim STANDS. Email best-effort.
            $this->sendEmail($ticket, $createdNote);
        } catch (\Throwable $ex) {
            // Any failure on the build/gate path reverts the claim (a thrown
            // executor already rolled back the note inside the gate transaction).
            $e->update(['max_hold_sent_at' => null]);
            $e->max_hold_sent_at = null;

            Log::warning('[Technician] Max-hold send failed; claim reverted', [
                'emergency_id' => $e->id,
                'ticket_id' => $ticket->id,
                'error' => $ex->getMessage(),
            ]);
        }
    }

    /**
     * Email the client OUTSIDE the gate transaction. Fail-closed: no contact email
     * or a send failure leaves the committed note in place and the claim SET (the
     * note was written; we do not re-fire to chase an undeliverable send).
     */
    private function sendEmail(Ticket $ticket, TicketNote $note): void
    {
        $to = $ticket->contact?->email;

        if (! $to) {
            Log::info('[Technician] Max-hold note written but no contact email to send to', ['ticket_id' => $ticket->id]);

            return;
        }

        try {
            $email = $this->email->sendTicketReplyNote($ticket, $note, $to, []);

            if ($email) {
                $note->update(['email_id' => $email->id]);
            }
        } catch (\Throwable $ex) {
            Log::warning('[Technician] Max-hold email failed to send', [
                'ticket_id' => $ticket->id,
                'note_id' => $note->id,
                'error' => $ex->getMessage(),
            ]);
        }
    }
}
