<?php

namespace App\Services\Agent\Escalation;

use App\Enums\FlagAttentionCategory;
use App\Models\AssistantConversation;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\EmailService;
use App\Services\Teams\TeamsBotClient;
use App\Services\Technician\Notify\TeamsNotifier;
use App\Services\Technician\Notify\TeamsText;
use App\Services\Wiki\Mining\WikiRedactor;
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
        private readonly TeamsBotClient $bot,
        private readonly TeamsNotifier $teamsWebhook,
        private readonly EmailService $email,
        private readonly WikiRedactor $redactor,
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
        // Cap FIRST so WikiRedactor::scan never sees an unbounded string. A
        // pathological-length blocker can exhaust the preg backtrack budget,
        // causing scan() to return false-empty (no violation found) and evading
        // detection. Capping to 500 chars before scanning closes that gap.
        $blocker = mb_substr($blocker, 0, 500);
        if ($this->redactor->scan($blocker) !== []) {
            Log::warning('[EscalationNotifier] Blocker text failed output scan — detail withheld', [
                'ticket_id' => $ticket->id,
            ]);
            $blocker = '[escalation detail withheld — open the ticket]';
        }
        // Defang markdown/HTML control characters in the (capped, possibly-placeholder)
        // blocker before it reaches any sink. A markdown link [x](http://evil) or an
        // <at>-tag in an agent-authored blocker would otherwise render as a live link
        // or a real mention in the operator's Teams chat. The trusted @mention prefix
        // built from User->name below is NOT passed through this — only attacker-
        // reachable fields (blocker, subject, client name) are escaped.
        $blocker = TeamsText::escape($blocker);

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

        if (TeamsBotConfig::enabled() && $convId !== null && $serviceUrl !== null) {
            // Bot proactive-post path.
            try {
                $mentions = [];
                $postBody = $body;

                // Best-effort @mention: look up the conversation-scoped member id.
                if ($recipient?->microsoft_id !== null) {
                    $member = $this->bot->getConversationMember($serviceUrl, $convId, $recipient->microsoft_id);
                    if ($member !== null && isset($member['id'])) {
                        $mentions = [['mentionId' => $member['id'], 'name' => $recipient->name]];
                        $postBody = "<at>{$recipient->name}</at> ".$body;
                    }
                }

                $posted = $this->bot->sendMessageWithMentions($serviceUrl, $convId, $postBody, $mentions);

                // Record the escalation in the teammate's own conversation transcript so the bot
                // remembers having posted it and can engage when a human replies in-chat (psa-f7ft).
                // The escalation chat IS the teammate conversation (same conversationId), so this
                // lands in the row TeamsReplyService reads. Only the initial post (step 0) — a
                // Task-4 sweep re-ping re-posts the same message and must not duplicate the entry.
                if ($posted && $step === 0) {
                    $this->recordInTeammateTranscript($convId, $body);
                }
            } catch (\Throwable $e) {
                Log::warning('[EscalationNotifier] Bot send failed — escalation not lost; email follows', [
                    'ticket_id' => $ticket->id,
                    'error' => $e->getMessage(),
                ]);
            }
        } else {
            // Webhook fallback: bot chat ref not fully configured.
            try {
                $this->teamsWebhook->post($subject, $body);
            } catch (\Throwable $e) {
                Log::warning('[EscalationNotifier] Teams webhook post failed', [
                    'ticket_id' => $ticket->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // ── 4. Email the recipient (always, secondary channel) ───────────────────
        if ($recipient?->email !== null && $recipient->email !== '') {
            try {
                $this->email->sendNew($recipient->email, $subject, $body, null, null, null);
            } catch (\Throwable $e) {
                Log::warning('[EscalationNotifier] Email delivery failed', [
                    'ticket_id' => $ticket->id,
                    'user_id' => $recipient->id,
                    'error' => $e->getMessage(),
                ]);
            }
        } elseif ($recipient !== null) {
            Log::warning('[EscalationNotifier] Routed user has no email address — email skipped', [
                'ticket_id' => $ticket->id,
                'user_id' => $recipient->id,
            ]);
        } else {
            Log::warning('[EscalationNotifier] No recipient resolved — no email sent', [
                'ticket_id' => $ticket->id,
            ]);
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
