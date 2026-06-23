<?php

namespace App\Services\Technician;

use App\Enums\NoteType;
use App\Enums\TechnicianRunState;
use App\Enums\WhoType;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Support\TechnicianConfig;

/**
 * Phase-0 vertical slice (spec §6, §9 "auto-acknowledge", §12 Phase 0). On run
 * creation, the Technician composes a templated, non-substantive, DISCLOSED
 * acknowledgment and sends it AS AN AUTO ACTION THROUGH THE GATE — proving the
 * whole substrate (gate → AI-authored client note → append-only audit → run
 * advance). The richer AI-help "choice" UI/copy + suppression rules are Phase 1.
 */
class AutoAcknowledge
{
    public function __construct(
        private readonly TechnicianActionGate $gate,
        private readonly TechnicianDisclosure $disclosure,
    ) {}

    public function run(TechnicianRun $run, Ticket $ticket): void
    {
        // The sending layer composes the disclosure (NOT the model).
        $body = $this->disclosure->withDisclosure($this->template($ticket));

        // Pre-send structural-disclosure check (fail-closed if absent).
        $this->disclosure->assertPresent($body);

        $actorId = TechnicianConfig::aiActorUserId();
        $authorName = ($actorId ? User::find($actorId)?->name : null) ?? 'AI Assistant';

        $result = $this->gate->dispatch(
            actionType: 'send_ack',
            ticketId: $ticket->id,
            clientId: $ticket->client_id,
            contentHash: $run->content_hash,
            summary: 'Auto-acknowledged the client.',
            runId: $run->id,
            executor: function () use ($ticket, $actorId, $authorName, $body): void {
                TicketNote::create([
                    'ticket_id' => $ticket->id,
                    'author_id' => $actorId,
                    'author_name' => $authorName,
                    'who_type' => WhoType::Agent,
                    'ai_authored' => true,
                    'body' => $body,
                    'note_type' => NoteType::Reply,
                    'is_private' => false,
                    'noted_at' => now(),
                ]);
            },
        );

        if ($result->status === 'executed') {
            $run->advanceTo(TechnicianRunState::Done);
        }
    }

    private function template(Ticket $ticket): string
    {
        return "Thanks for getting in touch — we've received your request and a member of our team "
            .'will review it and follow up '.TechnicianConfig::ackEtaText().'. '
            ."We wanted to let you know it's in our queue.";
    }
}
