<?php

namespace App\Services\Technician;

use App\Enums\NoteType;
use App\Enums\TechnicianRunState;
use App\Enums\TicketStatus;
use App\Enums\WhoType;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Services\EmailService;
use App\Services\Mcp\StaffTacticalActionToolExecutor;
use App\Services\TicketService;
use App\Support\TechnicianConfig;
use Illuminate\Support\Facades\Log;

/** The outcome of an approve action. status ∈ {sent, closed, published, merged, already_handled, gate_declined}. */
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

        try {
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
        } catch (\Throwable $e) {
            // Unexpected throw between claim and email send — revert so the operator can retry.
            $run->releaseClaim();
            throw $e;
        }

        if ($result->status !== 'executed' || $createdNote === null) {
            // Gate declined (kill-switch flipped in-flight, etc.) — un-latch so the operator can retry.
            $run->releaseClaim();

            return new TechnicianApprovalResult('gate_declined');
        }

        // Recipient is the ticket's own contact — NEVER the model-suggested address. Sent after the gate tx.
        $this->sendEmail($ticket, $createdNote);

        return new TechnicianApprovalResult('sent', $createdNote->id);
    }

    /**
     * Approve a held propose_close run: closes the ticket to Closed (silent — no client
     * notification) through the gate (atomic + audited). Mirrors approveAndSend exactly:
     * single-use CAS latch, hash recompute, signed grant, try/catch-releaseClaim (CO-3),
     * releaseClaim on non-executed. No body, no email, no client send.
     */
    public function approveClose(TechnicianRun $run, int $approverId): TechnicianApprovalResult
    {
        // Single-use latch: only the winner of the CAS proceeds.
        if (! $run->claimForExecution()) {
            return new TechnicianApprovalResult('already_handled');
        }

        try {
            $hash = hash('sha256', 'propose_close:'.$run->ticket_id.':'.$run->proposed_content);
            $token = TechnicianApprovalGrant::issue('propose_close', $run->ticket_id, $hash, $approverId);

            $result = $this->gate->dispatch(
                actionType: 'propose_close',
                ticketId: $run->ticket_id,
                clientId: $run->client_id,
                contentHash: $hash,
                summary: 'Operator-approved close.',
                runId: $run->id,
                executor: function () use ($run): void {
                    $ticket = $run->ticket; // belongsTo — null if the ticket was (soft-)deleted
                    // CO-23 + CO-Fix6: capture the fresh model once so both the guard and
                    // changeStatus operate on the same (current) row. If the ticket is gone —
                    // the relation returned null (soft-deleted: the default scope hides it),
                    // hard-deleted (fresh() returns null), or soft-deleted mid-flight (fresh()
                    // strips scopes, so it comes back TRASHED, not null) — treat as already-gone.
                    $fresh = $ticket?->fresh();
                    if ($fresh === null || $fresh->trashed()) {
                        $run->advanceTo(TechnicianRunState::Done);

                        return;
                    }

                    if ($fresh->status === TicketStatus::Closed) {
                        $run->advanceTo(TechnicianRunState::Done);

                        return;
                    }
                    // Using $fresh ensures the status-change note's "from" state is accurate.
                    app(TicketService::class)->changeStatus(
                        $fresh,
                        TicketStatus::Closed,
                        TechnicianConfig::aiActorUserId(),
                        'Closed by AI Technician (operator-approved).',
                    );
                    $run->advanceTo(TechnicianRunState::Done);
                },
                approvalToken: $token,
                approverUserId: $approverId,
                confidence: null,
            );
        } catch (\Throwable $e) {
            // Unexpected throw between claim and execution — revert so the operator can retry.
            $run->releaseClaim();
            throw $e;
        }

        if ($result->status !== 'executed') {
            // Gate declined (kill-switch, client exclusion, etc.) — un-latch so the operator can retry.
            $run->releaseClaim();

            return new TechnicianApprovalResult('gate_declined');
        }

        return new TechnicianApprovalResult('closed'); // no client notification (CO-18)
    }

    public function approveStagedEmail(TechnicianRun $run, string $body, int $approverId): TechnicianApprovalResult
    {
        return $this->approveStagedBodyAction(
            run: $run,
            body: $body,
            approverId: $approverId,
            actionType: 'stage_email',
            noteType: NoteType::Reply,
            summary: 'Operator-approved staged client email.',
            sendsEmail: true,
            successStatus: 'sent',
        );
    }

    public function approveStagedPublicNote(TechnicianRun $run, string $body, int $approverId): TechnicianApprovalResult
    {
        return $this->approveStagedBodyAction(
            run: $run,
            body: $body,
            approverId: $approverId,
            actionType: 'stage_public_note',
            noteType: NoteType::Note,
            summary: 'Operator-approved staged public note.',
            sendsEmail: false,
            successStatus: 'published',
        );
    }

    public function approveMerge(TechnicianRun $run, int $approverId): TechnicianApprovalResult
    {
        if ($run->action_type !== 'propose_merge' || ! $run->claimForExecution()) {
            return new TechnicianApprovalResult('already_handled');
        }

        $pair = $this->validMergePair($run);
        if ($pair === null) {
            $run->releaseClaim();

            return new TechnicianApprovalResult('gate_declined');
        }

        [$primary, $secondary] = $pair;

        try {
            $hash = hash('sha256', 'propose_merge:'.$primary->id.':'.$secondary->id.':'.$run->proposed_content);
            $token = TechnicianApprovalGrant::issue('propose_merge', $run->ticket_id, $hash, $approverId);

            $result = $this->gate->dispatch(
                actionType: 'propose_merge',
                ticketId: $run->ticket_id,
                clientId: $run->client_id,
                contentHash: $hash,
                summary: 'Operator-approved ticket merge.',
                runId: $run->id,
                executor: function () use ($run, $primary, $secondary, $approverId): void {
                    app(TicketService::class)->mergeTickets($primary, $secondary, $approverId);

                    TechnicianRun::query()
                        ->where('ticket_id', $secondary->id)
                        ->whereKeyNot($run->id)
                        ->where('state', TechnicianRunState::AwaitingApproval->value)
                        ->get()
                        ->each(fn (TechnicianRun $pending) => $pending->markSuperseded());

                    $run->advanceTo(TechnicianRunState::Done);
                },
                approvalToken: $token,
                approverUserId: $approverId,
                confidence: null,
            );
        } catch (\Throwable $e) {
            $run->releaseClaim();
            throw $e;
        }

        if ($result->status !== 'executed') {
            $run->releaseClaim();

            return new TechnicianApprovalResult('gate_declined');
        }

        return new TechnicianApprovalResult('merged');
    }

    public function approveStagedTacticalAction(TechnicianRun $run, int $approverId): TechnicianApprovalResult
    {
        return app(StaffTacticalActionToolExecutor::class)->approveStagedRun($run, $approverId);
    }

    public function deny(TechnicianRun $run): void
    {
        $run->deny();
    }

    private function approveStagedBodyAction(
        TechnicianRun $run,
        string $body,
        int $approverId,
        string $actionType,
        NoteType $noteType,
        string $summary,
        bool $sendsEmail,
        string $successStatus,
    ): TechnicianApprovalResult {
        $body = trim($body);

        if ($body === '' || $run->action_type !== $actionType || ! $run->claimForExecution()) {
            return new TechnicianApprovalResult('already_handled');
        }

        $ticket = $run->ticket;
        if (! $ticket) {
            $run->releaseClaim();

            return new TechnicianApprovalResult('gate_declined');
        }

        $actorId = TechnicianConfig::aiActorUserId();
        $actorName = TechnicianConfig::aiActorName();

        try {
            $hash = hash('sha256', $actionType.':'.$run->ticket_id.':'.$body);
            $token = TechnicianApprovalGrant::issue($actionType, $run->ticket_id, $hash, $approverId);

            $disclosed = $this->disclosure->withDisclosure($body, $actorName);
            $this->disclosure->assertPresent($disclosed);

            $createdNote = null;

            $result = $this->gate->dispatch(
                actionType: $actionType,
                ticketId: $run->ticket_id,
                clientId: $run->client_id,
                contentHash: $hash,
                summary: $summary,
                runId: $run->id,
                executor: function () use ($ticket, $actorId, $actorName, $disclosed, $noteType, $run, &$createdNote): void {
                    $createdNote = TicketNote::create([
                        'ticket_id' => $ticket->id,
                        'author_id' => $actorId,
                        'author_name' => $actorName,
                        'who_type' => WhoType::Agent,
                        'ai_authored' => true,
                        'body' => $disclosed,
                        'note_type' => $noteType,
                        'is_private' => false,
                        'noted_at' => now(),
                    ]);
                    $run->advanceTo(TechnicianRunState::Done);
                },
                approvalToken: $token,
                approverUserId: $approverId,
                confidence: null,
            );
        } catch (\Throwable $e) {
            $run->releaseClaim();
            throw $e;
        }

        if ($result->status !== 'executed' || $createdNote === null) {
            $run->releaseClaim();

            return new TechnicianApprovalResult('gate_declined');
        }

        if ($sendsEmail) {
            $this->sendEmail($ticket, $createdNote);
        }

        return new TechnicianApprovalResult($successStatus, $createdNote->id);
    }

    /**
     * @return array{0: Ticket, 1: Ticket}|null
     */
    private function validMergePair(TechnicianRun $run): ?array
    {
        $meta = $run->proposed_meta ?? [];
        $primaryId = (int) ($meta['primary_ticket_id'] ?? $run->ticket_id);
        $secondaryId = (int) ($meta['secondary_ticket_id'] ?? 0);

        if ($primaryId <= 0 || $secondaryId <= 0 || $primaryId !== (int) $run->ticket_id || $primaryId === $secondaryId) {
            return null;
        }

        $primary = Ticket::query()->find($primaryId);
        $secondary = Ticket::query()->find($secondaryId);

        if (! $primary || ! $secondary) {
            return null;
        }

        if ($primary->client_id !== $run->client_id || $secondary->client_id !== $run->client_id) {
            return null;
        }

        if ($primary->parent_ticket_id || $secondary->parent_ticket_id || $secondary->childTickets()->exists()) {
            return null;
        }

        return [$primary, $secondary];
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
