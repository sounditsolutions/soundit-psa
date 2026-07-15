<?php

namespace App\Services\Technician;

use App\Enums\NoteType;
use App\Enums\TechnicianRunState;
use App\Enums\TicketStatus;
use App\Enums\WhoType;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Services\Email\EmailRecipientResolver;
use App\Services\Email\RecipientContext;
use App\Services\Email\RecipientValidationException;
use App\Services\Email\ResolvedRecipients;
use App\Services\EmailService;
use App\Services\Mcp\StaffCippWriteToolExecutor;
use App\Services\Mcp\StaffTacticalActionToolExecutor;
use App\Services\Mcp\StaffTacticalAdminToolExecutor;
use App\Services\TicketService;
use App\Support\TechnicianConfig;
use Illuminate\Support\Facades\Log;

/**
 * Turns a held draft into a real, human-approved, single-use client send (Plan 1B).
 * The run-state CAS latch (claimForExecution) makes it exactly-once even on a
 * double-tap / replayed grant; the gate enforces the signed identity-bound grant;
 * disclosure is appended by this sending layer. Recipients resolve only from
 * server-validated sources (the ticket contact, the ticket-client's contacts, and
 * addresses already on the ticket's email thread) via EmailRecipientResolver,
 * re-resolved at approval time (gate 3): the model/operator supply REFERENCES, never
 * free-text. Arbitrary addresses (not a known contact or thread participant) are
 * rejected unless the STAGED arbitrary-recipients policy is on (psa-w4e0:
 * allow_arbitrary_email_recipients_staged, or the global knob — both default off);
 * when admitted they are syntax-validated, prefilled + highlighted on the approval
 * card, and counted in the audit summary. The approval-time grant + audit hash binds
 * the CANONICAL RESOLVED PAYLOAD — body AND final To/CC (ResolvedRecipients::
 * hashPayload) — and the full resolved audience is persisted on the append-only
 * audit row (approved_recipients) inside the gate transaction, so the approved
 * recipient set can neither be swapped under the signed hash nor lost when delivery
 * fails (psa-w4e0 revise). The email is sent AFTER the gate transaction, never
 * inside it.
 */
class TechnicianApprovalService
{
    public const OPERATOR_APPROVED_CLOSE_NOTE = 'Closed by AI Technician (operator-approved).';

    public function __construct(
        private readonly TechnicianActionGate $gate,
        private readonly TechnicianDisclosure $disclosure,
        private readonly EmailService $email,
        private readonly EmailRecipientResolver $recipients,
    ) {}

    /**
     * The persona that DRAFTED this run, for note attribution and the AI half of the
     * dual credit (psa-u51h). Read from the bare token label the staging path recorded
     * — the token itself is long out of scope by approval time. A run staged before
     * psa-u51h (or drafted natively, with no token at all) has no such key and degrades
     * to the global actor name, byte-identical to the pre-psa-u51h tagline.
     */
    private function drafterName(TechnicianRun $run): string
    {
        $label = data_get($run->proposed_meta, 'drafted_by_token');

        return TechnicianConfig::actorNameForTokenLabel(is_string($label) ? $label : null);
    }

    /**
     * Dual credit for the approved send: the AI that drafted it AND the technician who
     * reviewed and sent it (Charlie's rule — this path is human-approved, unlike the
     * auto-sent path, which keeps the AI-only banner). An approver that no longer
     * resolves to a named user degrades to AI-only rather than crediting a phantom
     * human (manager ruling, psa-u51h Q3).
     */
    private function disclosedForApproval(string $body, string $drafterName, int $approverId): string
    {
        $approverName = (string) (User::find($approverId)?->name ?? '');

        $disclosed = $this->disclosure->withDualDisclosure($body, $drafterName, $approverName);
        $this->disclosure->assertPresent($disclosed); // fail-closed pre-send check

        return $disclosed;
    }

    private function resolveRecipients(Ticket $ticket, array $to, array $cc): ResolvedRecipients
    {
        // Every send below is operator-approved from the cockpit (the recipient list is
        // on the card), so the STAGED arbitrary-recipients policy applies — never the
        // immediate path's global-only knob.
        return $this->recipients->resolve(
            $ticket, $to, $cc, RecipientContext::Staged,
            TechnicianConfig::stagedSendsAllowArbitraryRecipients(),
            TechnicianConfig::directEmailNewRecipients(),
        );
    }

    public function approveAndSend(TechnicianRun $run, string $body, int $approverId, array $to = [], array $cc = []): TechnicianApprovalResult
    {
        $body = trim($body);

        // Single-use latch: only the winner of the CAS proceeds.
        if ($body === '' || ! $run->claimForExecution()) {
            return new TechnicianApprovalResult('already_handled');
        }

        $ticket = $run->ticket;
        $actorId = TechnicianConfig::aiActorUserId();
        $actorName = $this->drafterName($run);

        // GATE 3: re-resolve the operator's recipients at execution time — a ref that no
        // longer resolves (person deleted/re-parented, arbitrary knob off) fails closed
        // BEFORE any note is written or email sent.
        if (! $ticket) {
            $run->releaseClaim();

            return new TechnicianApprovalResult('gate_declined');
        }
        try {
            $resolved = $this->resolveRecipients($ticket, $to, $cc);
        } catch (RecipientValidationException $e) {
            $run->releaseClaim();

            return new TechnicianApprovalResult('recipient_invalid', null, $e->getMessage());
        } catch (\Throwable $e) {
            // Unexpected error (e.g. a DB failure inside candidates()) — release the claim
            // so the run is retryable, never stranded in Executing.
            $run->releaseClaim();

            throw $e;
        }

        try {
            // The grant binds the EXACT (possibly edited) body the operator approved AND
            // the final resolved To/CC — a recipient swap is a different action (psa-w4e0
            // revise; same canonical payload as the stage/direct keys).
            $hash = hash('sha256', 'send_reply:'.$run->ticket_id.':'.$resolved->hashPayload($body));
            $token = TechnicianApprovalGrant::issue('send_reply', $run->ticket_id, $hash, $approverId);

            $disclosed = $this->disclosedForApproval($body, $actorName, $approverId);

            $createdNote = null;

            $result = $this->gate->dispatch(
                actionType: 'send_reply',
                ticketId: $run->ticket_id,
                clientId: $run->client_id,
                contentHash: $hash,
                summary: 'Operator-approved client reply. ['.$resolved->auditDescriptor().']',
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
                // Durable pre-send record: the exact approved audience lands on the
                // append-only audit row inside the gate transaction — a failed delivery
                // still leaves the attempted recipients on record.
                approvedRecipients: $resolved->toAuditArray(),
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

        // Recipients are the operator-approved, server-validated set (resolved above). Sent after the gate tx.
        $this->sendEmail($ticket, $createdNote, $resolved);

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

        $statusNoteId = null;

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
                executor: function () use ($run, &$statusNoteId): void {
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
                        self::OPERATOR_APPROVED_CLOSE_NOTE,
                    );
                    $statusNoteId = TicketNote::query()
                        ->where('ticket_id', $fresh->id)
                        ->where('note_type', NoteType::StatusChange->value)
                        ->where('status_to', TicketStatus::Closed->value)
                        ->where('body', self::OPERATOR_APPROVED_CLOSE_NOTE)
                        ->latest('id')
                        ->value('id');
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

        if ($statusNoteId === null) {
            return new TechnicianApprovalResult('already_handled');
        }

        return new TechnicianApprovalResult('closed', noteId: (int) $statusNoteId); // no client notification (CO-18)
    }

    public function approveStagedEmail(TechnicianRun $run, string $body, int $approverId, array $to = [], array $cc = []): TechnicianApprovalResult
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
            to: $to,
            cc: $cc,
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

    public function approveStagedTacticalAdminAction(TechnicianRun $run, int $approverId): TechnicianApprovalResult
    {
        return app(StaffTacticalAdminToolExecutor::class)->approveStagedRun($run, $approverId);
    }

    public function approveStagedCippWriteAction(TechnicianRun $run, int $approverId, array $approvalInputs = []): TechnicianApprovalResult
    {
        return app(StaffCippWriteToolExecutor::class)->approveStagedRun($run, $approverId, $approvalInputs);
    }

    public function deny(TechnicianRun $run): bool
    {
        return $run->deny();
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
        array $to = [],
        array $cc = [],
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

        // GATE 3: for the email-sending path, re-resolve recipients at execution — fail closed.
        $resolved = null;
        if ($sendsEmail) {
            try {
                $resolved = $this->resolveRecipients($ticket, $to, $cc);
            } catch (RecipientValidationException $e) {
                $run->releaseClaim();

                return new TechnicianApprovalResult('recipient_invalid', null, $e->getMessage());
            } catch (\Throwable $e) {
                // Unexpected error — release the claim so the run is retryable, not stranded.
                $run->releaseClaim();

                throw $e;
            }
        }

        $actorId = TechnicianConfig::aiActorUserId();
        $actorName = $this->drafterName($run);

        try {
            // Email-sending action: the grant + audit hash binds body AND the final
            // resolved To/CC (psa-w4e0 revise — recipient swap ⇒ different hash). The
            // no-email action (stage_public_note) has no audience, so body-only stands.
            $hash = hash('sha256', $actionType.':'.$run->ticket_id.':'.($resolved !== null ? $resolved->hashPayload($body) : $body));
            $token = TechnicianApprovalGrant::issue($actionType, $run->ticket_id, $hash, $approverId);

            $disclosed = $this->disclosedForApproval($body, $actorName, $approverId);

            $createdNote = null;

            $result = $this->gate->dispatch(
                actionType: $actionType,
                ticketId: $run->ticket_id,
                clientId: $run->client_id,
                contentHash: $hash,
                // Counts-only recipient descriptor (never addresses) — flags approvals
                // that included recipients outside the client's known contacts.
                summary: $resolved !== null ? $summary.' ['.$resolved->auditDescriptor().']' : $summary,
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
                // Durable pre-send record (email path only): the exact approved audience
                // is committed on the append-only audit row before the external send.
                approvedRecipients: $resolved?->toAuditArray(),
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
            $this->sendEmail($ticket, $createdNote, $resolved);
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

    private function sendEmail(\App\Models\Ticket $ticket, TicketNote $note, ResolvedRecipients $resolved): void
    {
        // Recipients were resolved + validated at approval time (gate 3). resolve() throws
        // when there is no To and no contact, so $resolved->to is always a real address here.
        try {
            $email = $this->email->sendTicketReplyNote($ticket, $note, $resolved->to, $resolved->cc);
            if ($email) {
                $note->update(['email_id' => $email->id]);
            }
        } catch (\Throwable $e) {
            Log::warning('[Technician] Approved reply email failed', ['ticket_id' => $ticket->id, 'error' => $e->getMessage()]);
        }
    }
}
