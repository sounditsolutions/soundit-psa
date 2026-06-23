<?php

namespace App\Services\Technician;

use App\Enums\NoteType;
use App\Enums\TechnicianRunState;
use App\Enums\WhoType;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Services\EmailService;
use App\Support\TechnicianConfig;
use Illuminate\Support\Facades\Log;

/**
 * The acknowledgment sending layer (spec §6/§9, "auto-acknowledge"). Composes a
 * templated, disclosed, non-substantive ack and sends it AS AN AUTO ACTION
 * THROUGH THE GATE, then — once the gate has executed (committed note + audit
 * atomically) — actually emails the client and links the email. The send is
 * deliberately OUTSIDE the gate transaction (no external call inside a DB tx).
 * Fail-closed: no contact email / a send failure holds (note kept), never crashes.
 */
class AutoAcknowledge
{
    public function __construct(
        private readonly TechnicianActionGate $gate,
        private readonly TechnicianDisclosure $disclosure,
        private readonly EmailService $email,
    ) {}

    public function run(TechnicianRun $run, Ticket $ticket): void
    {
        // Spec §9 (v2): suppress the auto-ack for sensitive categories
        // (billing / security-incident / outage) — those get a human, not a bot ack.
        if (TechnicianConfig::ackSuppressedForCategory($ticket->category)) {
            Log::info('[Technician] Ack suppressed for category', [
                'ticket_id' => $ticket->id,
                'category' => $ticket->category,
            ]);
            $run->advanceTo(TechnicianRunState::Done);

            return;
        }

        $actorId = TechnicianConfig::aiActorUserId();
        $actorName = TechnicianConfig::aiActorName();

        $body = $this->disclosure->withDisclosure($this->template($ticket), $actorName);
        $this->disclosure->assertPresent($body); // pre-send structural check (fail-closed)

        $createdNote = null;

        $result = $this->gate->dispatch(
            actionType: 'send_ack',
            ticketId: $ticket->id,
            clientId: $ticket->client_id,
            contentHash: $run->content_hash,
            summary: 'Auto-acknowledged the client.',
            runId: $run->id,
            executor: function () use ($ticket, $actorId, $actorName, $body, $run, &$createdNote): void {
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

                // Advance INSIDE the gate transaction (v2) so note + audit + run-state
                // commit atomically: a failure rolls back all three, leaving the run in
                // Gathering with no committed note (clean retry, no duplicate ack).
                $run->advanceTo(TechnicianRunState::Done);
            },
        );

        // The client email is sent AFTER the gate transaction commits (never an
        // external call inside a DB tx). If the job dies before this, the run is
        // already Done, so a retry won't duplicate the note — the client simply
        // isn't emailed, the same tolerated outcome as any send failure.
        if ($result->status === 'executed' && $createdNote !== null) {
            $this->sendEmail($ticket, $createdNote);
        }
    }

    /** Send the ack to the client (outside the gate transaction). Fail-closed. */
    private function sendEmail(Ticket $ticket, TicketNote $note): void
    {
        $to = $ticket->contact?->email;

        if (! $to) {
            Log::info('[Technician] Ack note written but no contact email to send to', ['ticket_id' => $ticket->id]);

            return;
        }

        try {
            $email = $this->email->sendTicketReplyNote($ticket, $note, $to, []);

            if ($email) {
                $note->update(['email_id' => $email->id]);
            }
        } catch (\Throwable $e) {
            Log::warning('[Technician] Ack email failed to send', [
                'ticket_id' => $ticket->id,
                'note_id' => $note->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function template(Ticket $ticket): string
    {
        return "Thanks for getting in touch — we've received your request and a member of our team "
            .'will review it and follow up '.TechnicianConfig::ackEtaText().'. '
            ."We wanted to let you know it's in our queue.";
    }
}
