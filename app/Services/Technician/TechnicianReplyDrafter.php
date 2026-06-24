<?php

namespace App\Services\Technician;

use App\Models\Person;
use App\Models\Ticket;
use App\Services\Ai\AiClient;
use App\Services\Triage\ContextBuilder;
use App\Services\Wiki\Mining\WikiRedactor;
use App\Support\AiConfig;
use Illuminate\Support\Facades\Log;

/**
 * Drafts the substantive client reply in house voice (spec §4.2). Reuses the
 * redaction-wired ContextBuilder + the configured reply guidelines, FENCES the
 * untrusted client conversation, and SCANS the output before returning it
 * (quarantine on any violation). The disclosure is appended by the sending layer
 * at approval time (Plan 1B) — never here, and the prompt forbids a human sign-off.
 */
class TechnicianReplyDrafter
{
    private const MAX_TOKENS = 1500;

    private const SYSTEM_PROMPT =
        "You are drafting a client-facing reply for an MSP IT support ticket, in the team's house voice. "
        .'Write ONLY the message body — no subject line, no email headers, no signature, and never sign off '
        .'as a named human (a disclosure line is appended automatically by the system). Be warm, clear, '
        .'specific, and honest about next steps. '.PromptFence::UNTRUSTED_INPUT_NOTICE.' '
        .'Respond ONLY with a JSON object {"draft": string, "to": string or null}.';

    public function __construct(
        private readonly AiClient $ai,
        private readonly WikiRedactor $redactor,
    ) {}

    public function draft(Ticket $ticket, string $actorName): ?TechnicianDraft
    {
        if (! AiConfig::isConfigured()) {
            return null;
        }

        $fence = new PromptFence;

        // Fail-closed context build (v2): ContextBuilder touches many integration
        // accessors that can throw; never let a context-build error crash the job.
        try {
            $context = ContextBuilder::buildForTicket($ticket, skipNotes: true);
            $conversation = ContextBuilder::buildConversationContext($ticket, 20, false);
        } catch (\Throwable $e) {
            Log::warning('[Technician] Context build failed; using minimal context', [
                'ticket_id' => $ticket->id,
                'error' => $e->getMessage(),
            ]);
            $context = 'Ticket subject: '.($ticket->subject ?? '');
            $conversation = '';
        }

        $parts = ['Draft a client-facing reply for this ticket.'];
        if ($guidelines = AiConfig::replyGuidelines()) {
            $parts[] = "HOUSE VOICE GUIDELINES:\n".$guidelines;
        }
        // FENCE the context too (v2 BLOCKER fix): ContextBuilder embeds the RAW
        // ticket description + client/asset/site notes (only its wiki-overview
        // branch is scanned), so it is NOT injection-safe — fence every untrusted
        // segment per spec §7.
        $parts[] = $fence->fence('TICKET CONTEXT', $context);
        $parts[] = $fence->fence('CLIENT CONVERSATION', $conversation);
        $user = implode("\n\n", $parts);

        try {
            $res = $this->ai->completeJson(self::SYSTEM_PROMPT, $user, self::MAX_TOKENS);
        } catch (\Throwable $e) {
            Log::warning('[Technician] Reply drafter AI error', ['ticket_id' => $ticket->id, 'error' => $e->getMessage()]);

            return null;
        }

        $body = trim((string) ($res['draft'] ?? ''));
        if ($body === '') {
            return null;
        }

        // MANDATORY output scan (spec §7) — quarantine on any violation.
        if ($this->redactor->scan($body) !== []) {
            Log::warning('[Technician] Reply draft quarantined by output scan', ['ticket_id' => $ticket->id]);

            return null;
        }

        $tokens = $this->ai->cumulativeInputTokens() + $this->ai->cumulativeOutputTokens();
        $to = $this->sanitizeRecipient($res['to'] ?? null, $ticket);

        return new TechnicianDraft($body, $to, $tokens);
    }

    /** Only ever a real client contact email, else the ticket's own contact. */
    private function sanitizeRecipient(?string $email, Ticket $ticket): ?string
    {
        $fallback = $ticket->contact?->email;

        if (! $email || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $fallback;
        }

        if ($ticket->client_id && Person::where('client_id', $ticket->client_id)->whereEmailMatch($email)->exists()) {
            return $email;
        }

        return $fallback;
    }
}
