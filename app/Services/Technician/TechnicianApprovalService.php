<?php

namespace App\Services\Technician;

use App\Enums\NoteType;
use App\Enums\TechnicianRunState;
use App\Enums\WhoType;
use App\Models\TechnicianRun;
use App\Models\TicketNote;
use App\Services\EmailService;
use App\Support\TechnicianConfig;
use Illuminate\Support\Facades\Log;

/** The outcome of an approve action. status ∈ {sent, already_handled, gate_declined}. */
final class TechnicianApprovalResult
{
    public function __construct(
        public readonly string $status,
        public readonly ?int $noteId = null,
    ) {}
}

/**
 * Turns a held draft into a real, human-approved, single-use client send (Plan 1B).
 * The run-state CAS latch (claimForExecution) makes it exactly-once even on a
 * double-tap / replayed grant; the gate enforces the signed identity-bound grant;
 * disclosure is appended by this sending layer; the recipient is re-derived from
 * the ticket contact (never the model-suggested address). The email is sent AFTER
 * the gate transaction, never inside it.
 */
class TechnicianApprovalService
{
    public function __construct(
        private readonly TechnicianActionGate $gate,
        private readonly TechnicianDisclosure $disclosure,
        private readonly EmailService $email,
    ) {}

    public function approveAndSend(TechnicianRun $run, string $body, int $approverId): TechnicianApprovalResult
    {
        $body = trim($body);

        // Single-use latch: only the winner of the CAS proceeds.
        if ($body === '' || ! $run->claimForExecution()) {
            return new TechnicianApprovalResult('already_handled');
        }

        $ticket = $run->ticket;
        $actorId = TechnicianConfig::aiActorUserId();
        $actorName = TechnicianConfig::aiActorName();

        // The grant binds the EXACT (possibly edited) body the operator approved.
        $hash = hash('sha256', 'send_reply:'.$run->ticket_id.':'.$body);
        $token = TechnicianApprovalGrant::issue('send_reply', $run->ticket_id, $hash, $approverId);

        $disclosed = $this->disclosure->withDisclosure($body, $actorName);
        $this->disclosure->assertPresent($disclosed); // fail-closed pre-send check

        $createdNote = null;

        $result = $this->gate->dispatch(
            actionType: 'send_reply',
            ticketId: $run->ticket_id,
            clientId: $run->client_id,
            contentHash: $hash,
            summary: 'Operator-approved client reply.',
            runId: $run->id,
            executor: function () use ($ticket, $actorId, $actorName, $disclosed, $run, &$createdNote): void {
                $createdNote = TicketNote::create([
                    'ticket_id' => $ticket->id,
                    'author_id' => $actorId,
                    'author_name' => $actorName,
                    'who_type' => WhoType::Agent,
                    'ai_authored' => true,
                    'body' => $disclosed,
                    'note_type' => NoteType::Reply,
                    'is_private' => false,
                    'noted_at' => now(),
                ]);
                $run->advanceTo(TechnicianRunState::Done); // note + audit + state commit atomically
            },
            approvalToken: $token,
            approverUserId: $approverId,
        );

        if ($result->status !== 'executed' || $createdNote === null) {
            // Gate declined (kill-switch flipped in-flight, etc.) — un-latch so the operator can retry.
            $run->advanceTo(TechnicianRunState::AwaitingApproval);

            return new TechnicianApprovalResult('gate_declined');
        }

        // Recipient is the ticket's own contact — NEVER the model-suggested address. Sent after the gate tx.
        $this->sendEmail($ticket, $createdNote);

        return new TechnicianApprovalResult('sent', $createdNote->id);
    }

    public function deny(TechnicianRun $run): void
    {
        $run->deny();
    }

    private function sendEmail(\App\Models\Ticket $ticket, TicketNote $note): void
    {
        $to = $ticket->contact?->email;
        if (! $to) {
            Log::info('[Technician] Approved reply note written but no contact email', ['ticket_id' => $ticket->id]);

            return;
        }

        try {
            $email = $this->email->sendTicketReplyNote($ticket, $note, $to, []);
            if ($email) {
                $note->update(['email_id' => $email->id]);
            }
        } catch (\Throwable $e) {
            Log::warning('[Technician] Approved reply email failed', ['ticket_id' => $ticket->id, 'error' => $e->getMessage()]);
        }
    }
}
