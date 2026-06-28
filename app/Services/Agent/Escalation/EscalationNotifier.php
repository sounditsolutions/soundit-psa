<?php

namespace App\Services\Agent\Escalation;

use App\Enums\FlagAttentionCategory;
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
 * Delivery seam for AI Technician escalations (Increment H, Task 2).
 *
 * Given a held flag_attention run, posts to the shared "Day to Day" Teams chat
 * addressing the role-routed person, and emails them. The recipient is resolved
 * SERVER-SIDE from the flag category (via TechnicianConfig::escalationRecipientFor)
 * — never passed in and never model-chosen. That routing is the load-bearing safety
 * point: the blocker text (attacker-controlled) cannot redirect delivery.
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
 * Fail-soft: no exception ever escapes to the caller. Each delivery channel is
 * independently wrapped so a failure in one never blocks another.
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
            $this->doNotify($ticket, $flagRun, $category, $blocker);
        } catch (\Throwable $e) {
            Log::warning('[EscalationNotifier] Unhandled error in notify', [
                'ticket_id' => $ticket->id,
                'category' => $category->value,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function doNotify(
        Ticket $ticket,
        TechnicianRun $flagRun,
        FlagAttentionCategory $category,
        string $blocker,
    ): void {
        // ── 1. Resolve recipient SERVER-SIDE ─────────────────────────────────────
        // This is the security crux: the flag category (set by the agent from a
        // fixed enum) drives who receives the escalation, NOT anything in the
        // blocker text or any caller-supplied argument.
        $userId = TechnicianConfig::escalationRecipientFor($category);
        $user = $userId ? User::find($userId) : null;

        // ── 2. Output-scan and cap the blocker ───────────────────────────────────
        if ($this->redactor->scan($blocker) !== []) {
            Log::warning('[EscalationNotifier] Blocker text failed output scan — detail withheld', [
                'ticket_id' => $ticket->id,
            ]);
            $blocker = '[escalation detail withheld — open the ticket]';
        }
        $blocker = mb_substr($blocker, 0, 500);

        // ── 3. Build the message ─────────────────────────────────────────────────
        // client / subject are UNTRUSTED (operator-set, client-typed) — escape
        // them with TeamsText::escape before embedding in any Teams-bound string.
        $subject = "AI Technician needs a human — ticket #{$ticket->id}";
        $name = $user?->name ?? 'the on-call operator';
        $clientName = TeamsText::escape($ticket->client?->name ?? '');
        $ticketSubject = TeamsText::escape($ticket->subject ?? '');
        $url = route('cockpit.index');

        $body = "🤖 The AI Technician needs {$name} on #{$ticket->id}"
            ." ({$clientName} — {$ticketSubject}): {$blocker}."
            ." Open the cockpit: {$url}";

        // ── 4. Deliver to the Day-to-Day chat ────────────────────────────────────
        $convId = TeamsBotConfig::escalationConversationId();
        $serviceUrl = TeamsBotConfig::escalationServiceUrl();

        if (TeamsBotConfig::enabled() && $convId !== null && $serviceUrl !== null) {
            // Bot proactive-post path.
            try {
                $mentions = [];
                $postBody = $body;

                // Best-effort @mention: look up the conversation-scoped member id.
                if ($user?->microsoft_id !== null) {
                    $member = $this->bot->getConversationMember($serviceUrl, $convId, $user->microsoft_id);
                    if ($member !== null && isset($member['id'])) {
                        $mentions = [['mentionId' => $member['id'], 'name' => $user->name]];
                        $postBody = "<at>{$user->name}</at> ".$body;
                    }
                }

                $this->bot->sendMessageWithMentions($serviceUrl, $convId, $postBody, $mentions);
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

        // ── 5. Email the recipient (always, secondary channel) ───────────────────
        if ($user?->email !== null && $user->email !== '') {
            try {
                $this->email->sendNew($user->email, $subject, $body, null, null, null);
            } catch (\Throwable $e) {
                Log::warning('[EscalationNotifier] Email delivery failed', [
                    'ticket_id' => $ticket->id,
                    'user_id' => $userId,
                    'error' => $e->getMessage(),
                ]);
            }
        } elseif ($userId !== null) {
            Log::warning('[EscalationNotifier] Routed user has no email address — email skipped', [
                'ticket_id' => $ticket->id,
                'user_id' => $userId,
            ]);
        } else {
            Log::warning('[EscalationNotifier] No recipient configured for category — no email sent', [
                'ticket_id' => $ticket->id,
                'category' => $category->value,
            ]);
        }

        // ── 6. Record escalation state on the run (no migration) ─────────────────
        // Step=0 marks the first escalation attempt. Task 4 reads this to detect
        // unacked escalations and advance the degradation sweep.
        $meta = $flagRun->proposed_meta ?? [];
        $meta['escalation'] = [
            'recipient_user_id' => $userId,
            'category' => $category->value,
            'notified_at' => now()->toIso8601String(),
            'step' => 0,
        ];
        $flagRun->proposed_meta = $meta;
        $flagRun->save();
    }
}
