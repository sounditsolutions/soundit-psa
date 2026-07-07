<?php

namespace App\Services\Agent;

use App\Enums\NoteType;
use App\Enums\TechnicianRunState;
use App\Enums\WhoType;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Services\Technician\TechnicianActionGate;
use App\Services\Technician\TechnicianReplyDrafter;
use App\Support\TechnicianConfig;
use LogicException;

/**
 * The AI Technician's HELD client-reply tool (A2).
 *
 * Calling send_reply lets the agent CHOOSE to draft a client-facing reply — but the
 * BODY is composed by the FENCED, output-scanned TechnicianReplyDrafter, never model
 * free-text. The draft is recorded as a held TechnicianRun in DraftPipeline's exact
 * shape (proposed_content = undisclosed body; proposed_meta = ['to', 'reasons'];
 * content_hash = sha256('send_reply:'.ticketId.':'.body)) so the existing
 * approveAndSend + cockpit approve arm work UNCHANGED. The disclosure is appended and
 * the recipient is re-derived from $ticket->contact at approval time — never model
 * free-text here. (Scope: this is the send_reply rule. Staff-directed To/CC control
 * lives in the separate MCP `send_email` tool, which validates any supplied recipients
 * against the ticket contact, the client's contacts, and the ticket's existing email
 * thread via EmailRecipientResolver — arbitrary addresses are rejected.)
 *
 * NEVER AUTO-SENDS. send_reply is Approve-tier always (TechnicianTierClassifier
 * hard-codes it), so the gate records awaiting_approval WITHOUT executing. The
 * executor below is a throwing tripwire: if a misconfiguration ever routes send_reply
 * to execution, it fails loudly rather than sending AI text to a client unapproved.
 * Defense in depth, mirroring DraftPipeline::recordHeld + FlagAttentionTool.
 *
 * NOTE (A2a): this tool is built + unit-tested but is NOT yet offered to the live
 * agent loop (it is intentionally absent from TechnicianAgent's $tools). A2b wires it
 * in atomically with the DraftPipeline reply-branch subsumption, so the two never
 * double-produce a held reply.
 *
 * MCP/staff uses executeHeld(): if Chet supplies a body, that body is held verbatim
 * for cockpit review with attribution and no native drafter token spend.
 */
class SendReplyTool
{
    public function __construct(
        private readonly TechnicianActionGate $gate,
        private readonly TechnicianReplyDrafter $replyDrafter,
    ) {}

    /** The Anthropic tool definition for `send_reply`. */
    public static function definition(): array
    {
        return [
            'name' => 'send_reply',
            'description' => 'Draft a client-facing reply for this ticket when the client is awaiting a substantive response and you can genuinely move it forward. The reply is ALWAYS held for operator approval — it is never sent automatically. You do NOT write the message yourself: the system drafts the body in the team house voice; you are only deciding that a reply is warranted and why. Use only when there is an unaddressed client message that needs a real answer; if the ticket is merely awaiting us internally, awaiting the client, or has nothing new to answer, do NOT call this.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'reason' => [
                        'type' => 'string',
                        'description' => 'One to three sentences on why a client reply is warranted now and what it should address (e.g. "The client asked for an ETA on the mailbox migration; we can confirm it completes tonight"). Recorded for the approving operator — it is NOT the reply body.',
                    ],
                ],
                'required' => ['reason'],
            ],
        ];
    }

    /**
     * Draft a held client reply (or leave the ticket). Returns a short string the model
     * sees in its tool_result.
     *
     * @param  array|null  $correctionContext  When the run was correction-driven, merged into
     *                                         proposed_meta as 'informed_by_correction' so the run
     *                                         is traceable to the operator correction. Null on a
     *                                         normal run — key absent from proposed_meta.
     */
    public function execute(Ticket $ticket, array $input, ?array $correctionContext = null): string
    {
        return $this->executeInternal(
            ticket: $ticket,
            input: $input,
            correctionContext: $correctionContext,
            allowSuppliedBody: false,
            draftedBy: 'technician-drafter',
        );
    }

    /**
     * Create a held reply draft from MCP/staff. A supplied body is held verbatim
     * for cockpit approval; bodyless calls preserve the native drafter fallback.
     */
    public function executeHeld(Ticket $ticket, array $input, string $draftedBy): string
    {
        return $this->executeInternal(
            ticket: $ticket,
            input: $input,
            correctionContext: null,
            allowSuppliedBody: true,
            draftedBy: $draftedBy,
        );
    }

    private function executeInternal(
        Ticket $ticket,
        array $input,
        ?array $correctionContext,
        bool $allowSuppliedBody,
        string $draftedBy,
    ): string {
        $reason = trim((string) ($input['reason'] ?? ''));

        // "Should I reply at all?" — carried from DraftPipeline. Only draft when there is
        // an unaddressed (non-AI) client message; the bot's own ack is ai_authored and is
        // ignored, so intake-only tickets don't trigger a needless reply.
        if (! $this->hasUnaddressedClientReply($ticket)) {
            return "Left ticket #{$ticket->id} (nothing unaddressed to reply to).";
        }

        $body = null;
        $to = null;
        $tokensUsed = 0;
        if ($allowSuppliedBody && array_key_exists('body', $input) && is_string($input['body']) && trim($input['body']) !== '') {
            $body = $input['body'];
        } else {
            // The BODY comes from the fenced + output-scanned drafter. A null draft means
            // the drafter declined or the output scan quarantined it.
            $draft = $this->replyDrafter->draft($ticket, TechnicianConfig::aiActorName());
            if ($draft === null) {
                return "Left ticket #{$ticket->id} (no reply drafted).";
            }

            $body = $draft->body;
            $to = $draft->to;
            $tokensUsed = $draft->tokensUsed;
            $draftedBy = 'technician-drafter';
        }

        $hash = hash('sha256', 'send_reply:'.$ticket->id.':'.$body);
        $meta = $this->draftMeta($to, $reason, $draftedBy, $correctionContext);

        $run = TechnicianRun::firstOrCreate(
            [
                'ticket_id' => $ticket->id,
                'action_type' => 'send_reply',
                'content_hash' => $hash,
            ],
            [
                'client_id' => $ticket->client_id,
                'state' => TechnicianRunState::AwaitingApproval,
                'proposed_content' => $body,
                'proposed_meta' => $meta,
                'confidence' => null,
                'tokens_used' => $tokensUsed,
            ],
        );

        // Idempotency (mirrors ProposeCloseTool/recordHeld): an identical held draft is
        // not re-dispatched; a stale (Superseded/Done/Denied) run with the same body is
        // revived so the cockpit re-surfaces it.
        if (! $run->wasRecentlyCreated) {
            if ($run->state === TechnicianRunState::AwaitingApproval) {
                return "Already drafted a reply for ticket #{$ticket->id}; awaiting approval.";
            }

            $run->update([
                'state' => TechnicianRunState::AwaitingApproval->value,
                'proposed_content' => $body,
                'proposed_meta' => $meta,
                'confidence' => null,
                'tokens_used' => $tokensUsed,
            ]);
        }

        // Latest-held-reply-wins (carried from DraftPipeline): a fresh draft supersedes any
        // OTHER held reply for this ticket, so the cockpit shows only the newest. Safe now
        // that the agent is the SOLE producer of send_reply runs (A2b) — this can never
        // clobber another producer's draft, the very collision A2b was designed to prevent.
        TechnicianRun::where('ticket_id', $ticket->id)
            ->where('action_type', 'send_reply')
            ->where('state', TechnicianRunState::AwaitingApproval->value)
            ->where('id', '!=', $run->id)
            ->get()
            ->each
            ->markSuperseded();

        $this->gate->dispatch(
            actionType: 'send_reply',
            ticketId: $ticket->id,
            clientId: $ticket->client_id,
            contentHash: $hash,
            summary: "The AI Technician drafted a client reply for ticket #{$ticket->id} (awaiting approval).",
            runId: $run->id,
            // Tripwire: send_reply is Approve-tier-without-grant, so the gate records
            // awaiting_approval WITHOUT calling this. If it ever runs, a misconfiguration
            // is trying to auto-send AI text to a client — fail loudly instead.
            executor: function () use ($ticket): void {
                throw new LogicException("[Technician] send_reply must not auto-execute — a client reply is hold-for-approval. Ticket #{$ticket->id}.");
            },
            confidence: null,
        );

        return "Drafted a client reply for #{$ticket->id}; held for approval.";
    }

    /**
     * @return array<string, mixed>
     */
    private function draftMeta(?string $to, string $reason, string $draftedBy, ?array $correctionContext): array
    {
        $meta = [];
        if ($to !== null && trim($to) !== '') {
            $meta['to'] = $to;
        }

        $meta['reasons'] = $reason !== '' ? [$reason] : [];
        $meta['drafted_by'] = $draftedBy;

        if ($correctionContext !== null) {
            $meta['informed_by_correction'] = $correctionContext;
        }

        return $meta;
    }

    /**
     * True when the latest client (non-AI EndUser) reply is newer than our latest reply
     * draft — i.e. there is an unaddressed client message. At intake (no client reply note
     * yet) it is true iff we have never drafted a reply. Carried verbatim from
     * DraftPipeline so the agent's "should I reply" judgement matches the retired pipeline.
     * (A2b consolidates this when DraftPipeline's reply branch is retired.)
     */
    private function hasUnaddressedClientReply(Ticket $ticket): bool
    {
        $latestClientReply = $ticket->notes()
            ->where('note_type', NoteType::Reply->value)
            ->where('ai_authored', false)
            ->where('who_type', WhoType::EndUser->value)
            ->latest('noted_at')
            ->first();

        $latestReplyRun = TechnicianRun::where('ticket_id', $ticket->id)
            ->where('action_type', 'send_reply')
            ->latest('created_at')
            ->first();

        if (! $latestClientReply) {
            return $latestReplyRun === null; // intake: draft once
        }

        return $latestReplyRun === null
            || $latestReplyRun->created_at === null
            || $latestReplyRun->created_at->lt($latestClientReply->noted_at);
    }
}
