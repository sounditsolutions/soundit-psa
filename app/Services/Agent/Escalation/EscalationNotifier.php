<?php

namespace App\Services\Agent\Escalation;

use App\Enums\FlagAttentionCategory;
use App\Models\AssistantConversation;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Technician\Notify\TeamsText;
use App\Support\TeamsBotConfig;
use App\Support\TechnicianConfig;
use Illuminate\Support\Facades\Log;

/**
 * Delivery seam for AI Technician escalations (Increment H, Tasks 2 & 4).
 *
 * Two public entry points:
 *
 *   notify()     — category-routed initial delivery. Resolves the recipient
 *                  SERVER-SIDE from the flag category (security crux: the
 *                  agent's only escalation signal cannot redirect delivery),
 *                  then delegates to deliverTo() and stamps the category.
 *
 *   deliverTo()  — targeted re-delivery (Task 4 sweep) to a SPECIFIC recipient
 *                  already resolved by the caller (from category routing or the
 *                  escalation chain — never the model). Records recipient, step,
 *                  and notified_at in proposed_meta so the sweep can advance the
 *                  chain on each re-ping.
 *
 * Delivery strategy:
 *   1. Prefer the live bot posting proactively to the configured escalation chat
 *      (real @mention best-effort via a member lookup; degrades to name-in-text when
 *      the lookup fails).
 *   2. Fall back to the TeamsNotifier webhook when the bot chat ref is not
 *      configured.
 *   3. Always also email the routed person.
 *
 * Output-scanned: the blocker text is checked with WikiRedactor::scan() before it
 * is embedded in any message. If a violation is found, the detail is withheld and
 * replaced with a safe placeholder; the escalation is NOT dropped.
 *
 * Fail-soft: delivery failures are isolated per channel. The proposed_meta write
 * can surface exceptions; callers (notify() outer catch, sweep per-run try/catch)
 * handle those.
 *
 * State: the escalation record is merged into $flagRun->proposed_meta (no migration
 * — proposed_meta is a JSON column). Task 4 reads this to detect unacked escalations.
 *
 * Nothing in this class calls Task 3's flag_attention wiring — it is a pure delivery
 * primitive; Task 3 wires it in.
 */
class EscalationNotifier
{
    public function __construct(
        private readonly OperatorDelivery $delivery,
    ) {}

    /**
     * Deliver an escalation for a held flag. The recipient is resolved SERVER-SIDE
     * from $category (the agent's only signal) — never passed in / never
     * model-chosen. Fail-soft: a channel failure never throws and never blocks the
     * others. Records the escalation state on $flagRun->proposed_meta.
     */
    public function notify(
        Ticket $ticket,
        TechnicianRun $flagRun,
        FlagAttentionCategory $category,
        string $blocker,
    ): void {
        try {
            // ── 1. Resolve recipient SERVER-SIDE ─────────────────────────────────
            // This is the security crux: the flag category (set by the agent from a
            // fixed enum) drives who receives the escalation, NOT anything in the
            // blocker text or any caller-supplied argument.
            $userId = TechnicianConfig::escalationRecipientFor($category);
            $user = $userId ? User::find($userId) : null;

            // ── 2. Pre-set category in in-memory meta so deliverTo's merge preserves it
            // deliverTo() merges (not replaces) the escalation dict, so any key already
            // present is carried forward. We stamp category here so that the single DB
            // write inside deliverTo() includes all four escalation keys.
            $meta = $flagRun->proposed_meta ?? [];
            $existing = $meta['escalation'] ?? [];
            $existing['category'] = $category->value;
            $meta['escalation'] = $existing;
            $flagRun->proposed_meta = $meta;

            // ── 3. Deliver (chat + email + meta write) ───────────────────────────
            $this->deliverTo($ticket, $flagRun, $user, $blocker, 0);
        } catch (\Throwable $e) {
            Log::warning('[EscalationNotifier] Unhandled error in notify', [
                'ticket_id' => $ticket->id,
                'category' => $category->value,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Deliver an escalation to a SPECIFIC recipient (server-resolved — from category routing OR the
     * escalation chain, never the model). $step is recorded in proposed_meta so the sweep can
     * advance the chain on subsequent re-pings.
     *
     * Fail-soft per channel: each delivery sink is independently wrapped so a failure in one never
     * blocks another. The proposed_meta write is NOT wrapped — exceptions surface to the caller
     * (notify()'s outer catch or the sweep's per-run try/catch).
     */
    public function deliverTo(
        Ticket $ticket,
        TechnicianRun $flagRun,
        ?User $recipient,
        string $blocker,
        int $step = 0,
    ): void {
        // ── 1. Cap → scan → Teams-escape the blocker ────────────────────────────
        $blocker = $this->delivery->sanitize($blocker, '[escalation detail withheld - open the ticket]');

        // ── 2. Build the message ─────────────────────────────────────────────────
        // client / subject are UNTRUSTED (operator-set, client-typed) — escape
        // them with TeamsText::escape before embedding in any Teams-bound string.
        $subject = "AI Technician needs a human — ticket #{$ticket->id}";
        $name = $recipient?->name ?? 'the on-call operator';
        $clientName = TeamsText::escape($ticket->client?->name ?? '');
        $ticketSubject = TeamsText::escape($ticket->subject ?? '');
        $url = route('cockpit.index');

        $body = "🤖 The AI Technician needs {$name} on #{$ticket->id}"
            ." ({$clientName} — {$ticketSubject}): {$blocker}."
            ." Open the cockpit: {$url}";

        // ── 3. Deliver to the Day-to-Day chat ────────────────────────────────────
        $convId = TeamsBotConfig::escalationConversationId();
        $serviceUrl = TeamsBotConfig::escalationServiceUrl();
        $result = $this->delivery->send($recipient, $convId, $serviceUrl, $subject, $body);

        // Record only the initial successful bot post in the teammate transcript.
        if ($result->postedToChat && $step === 0 && $convId !== null) {
            $this->recordInTeammateTranscript($convId, $body);
        }

        // ── 5. Record escalation state on the run (merge — no migration) ─────────
        // MERGE with existing escalation dict so that keys stamped by notify() (category)
        // and by Task 3 are preserved across re-pings. Only the three mutable keys are
        // overwritten: who got paged, when, and which chain step we're at.
        $meta = $flagRun->proposed_meta ?? [];
        $existing = $meta['escalation'] ?? [];
        $meta['escalation'] = array_merge($existing, [
            'recipient_user_id' => $recipient?->id,
            'notified_at' => now()->toIso8601String(),
            'step' => $step,
        ]);
        $flagRun->proposed_meta = $meta;
        $flagRun->save();
    }

    /**
     * Record the just-posted escalation as an 'assistant' turn in the teammate conversation for
     * this chat, so the bot's reply loop (TeamsReplyService::history) sees its own escalation and
     * can engage when a human replies in-chat. Fail-soft: a transcript write must never lose the
     * escalation or surface to the caller.
     */
    private function recordInTeammateTranscript(string $conversationId, string $body): void
    {
        try {
            // Key MUST match TeamsReplyService::conversation() ('teams:'.<conversationId>) so the
            // escalation lands in the same row the teammate reads from and writes to.
            $conversation = AssistantConversation::createOrFirst(
                ['external_key' => 'teams:'.$conversationId],
                ['context_type' => 'teams_chat', 'user_id' => TechnicianConfig::aiActorUserId()],
            );
            $conversation->messages()->create(['role' => 'assistant', 'content' => $body]);
        } catch (\Throwable $e) {
            Log::warning('[EscalationNotifier] Failed to record escalation in teammate transcript — escalation still delivered', [
                'conversation_id' => $conversationId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
