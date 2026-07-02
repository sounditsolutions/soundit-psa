<?php

namespace App\Services;

use App\Enums\EmailDirection;
use App\Enums\NoteType;
use App\Enums\TechnicianRunState;
use App\Enums\TicketPriority;
use App\Enums\TicketSource;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Enums\WhoType;
use App\Helpers\HtmlSanitizer;
use App\Helpers\MarkdownRenderer;
use App\Models\Client;
use App\Models\Email;
use App\Models\Person;
use App\Models\Setting;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Services\Agent\Intake\IntakeDecision;
use App\Services\Agent\Intake\IntakeRouter;
use App\Services\Email\ForwardedEmailParser;
use App\Services\Graph\GraphClient;
use App\Services\Graph\GraphClientException;
use App\Services\Mesh\MeshEmailParser;
use App\Services\Zorus\ZorusEmailParser;
use App\Support\AgentConfig;
use App\Support\AiConfig;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Soundasleep\Html2Text;

class EmailService
{
    private const FREE_EMAIL_DOMAINS = [
        'gmail.com', 'outlook.com', 'hotmail.com', 'yahoo.com', 'live.com',
        'icloud.com', 'aol.com', 'protonmail.com', 'me.com', 'msn.com',
    ];

    private const GRAPH_SELECT_FIELDS = 'id,internetMessageId,conversationId,from,toRecipients,ccRecipients,subject,bodyPreview,body,hasAttachments,importance,receivedDateTime,internetMessageHeaders';

    public function __construct(
        private readonly GraphClient $graphClient,
        private readonly TicketService $ticketService,
    ) {}

    /**
     * Poll the mailbox for new emails and import them.
     */
    public function pollMailbox(?string $sinceDate = null): SyncResult
    {
        $result = new SyncResult;

        $mailbox = Setting::getValue('graph_mailbox');
        if (! $mailbox) {
            $result->recordError('No mailbox configured (graph_mailbox setting is empty).');

            return $result;
        }

        $since = $sinceDate
            ?? Setting::getValue('graph_last_poll_at')
            ?? now()->subDays(30)->toIso8601String();

        // Look back 4 hours from the cursor to catch emails that were created
        // in the DB (by a failed webhook) but never fully processed.
        $sinceFormatted = Carbon::parse($since)->subHours(4)->toIso8601String();

        try {
            $messages = $this->graphClient->getMailboxMessages($mailbox, [
                '$select' => self::GRAPH_SELECT_FIELDS,
                '$filter' => "receivedDateTime ge {$sinceFormatted}",
                '$orderby' => 'receivedDateTime asc',
                '$top' => 50,
            ]);
        } catch (GraphClientException $e) {
            $result->recordError('Graph API error: '.$e->getMessage());

            return $result;
        }

        $lastSuccessfulReceivedAt = null;
        $hitPageLimit = count($messages) >= 2500; // 50 pages * 50 per page

        foreach ($messages as $msg) {
            try {
                $graphId = $msg['id'] ?? null;
                if (! $graphId) {
                    continue;
                }

                $mapped = $this->mapGraphMessage($msg);
                $internetMessageId = $mapped['internet_message_id'] ?? null;

                // Dedup by internet_message_id (globally unique RFC 5322 ID).
                // Graph's internal id can be recycled when messages are deleted/moved.
                $email = null;
                if ($internetMessageId) {
                    $email = Email::where('internet_message_id', $internetMessageId)->first();
                }

                if ($email) {
                    // Exact match by internet_message_id — same email, maybe different graph_id
                    if ($email->graph_id !== $graphId) {
                        $email->update(['graph_id' => $graphId]);
                    }

                    // Retry processing for unprocessed inbound emails
                    if ($email->direction === \App\Enums\EmailDirection::Inbound
                        && $email->ticket_id === null
                        && $email->dismissed_at === null
                    ) {
                        $this->resolveSender($email);
                        $this->processInbound($email);
                        if ($email->fresh()->ticket_id !== null) {
                            $result->created++;
                        }
                    }
                } else {
                    // New email — clear any stale graph_id from old records before creating
                    Email::where('graph_id', $graphId)->update(['graph_id' => null]);

                    $email = Email::create(array_merge(['graph_id' => $graphId], $mapped));
                    $result->created++;
                    $this->resolveSender($email);
                    $this->processInbound($email);
                }

                $lastSuccessfulReceivedAt = $msg['receivedDateTime'] ?? $lastSuccessfulReceivedAt;
            } catch (\Throwable $e) {
                $result->recordError("Failed to import message {$graphId}: ".$e->getMessage());
                Log::error('[EmailService] Failed to import email', [
                    'graph_id' => $graphId ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Only advance the poll cursor on success
        if ($result->errors === 0 && ! $hitPageLimit && $lastSuccessfulReceivedAt) {
            Setting::setValue('graph_last_poll_at', $lastSuccessfulReceivedAt);
        } elseif ($hitPageLimit) {
            Log::warning('[EmailService] Hit page limit during poll — not advancing cursor', [
                'since' => $sinceFormatted,
                'imported' => $result->created,
            ]);
            // Advance to last successful message so next run picks up from there
            if ($lastSuccessfulReceivedAt) {
                Setting::setValue('graph_last_poll_at', $lastSuccessfulReceivedAt);
            }
        }

        return $result;
    }

    /**
     * Import a single message from its Graph resource data.
     * Used by webhook handler after fetching the full message.
     *
     * Wrapped in a transaction with lockForUpdate to prevent duplicate processing
     * when Graph delivers the same webhook notification concurrently.
     */
    public function importSingleMessage(array $msg): ?Email
    {
        $graphId = $msg['id'] ?? null;
        if (! $graphId) {
            return null;
        }

        return DB::transaction(function () use ($msg, $graphId) {
            $mapped = $this->mapGraphMessage($msg);
            $internetMessageId = $mapped['internet_message_id'] ?? null;

            // Dedup by internet_message_id (globally unique RFC 5322 ID)
            $email = null;
            if ($internetMessageId) {
                $email = Email::lockForUpdate()
                    ->where('internet_message_id', $internetMessageId)
                    ->first();
            }

            if (! $email) {
                // New email — clear any stale graph_id from old records
                Email::where('graph_id', $graphId)->update(['graph_id' => null]);

                $email = Email::create(array_merge(['graph_id' => $graphId], $mapped));
                $this->resolveSender($email);
                $this->processInbound($email);
            } else {
                // Update graph_id if it changed
                if ($email->graph_id !== $graphId) {
                    $email->update(['graph_id' => $graphId]);
                }
            }

            return $email;
        });
    }

    /**
     * Process an inbound email after import: thread-match to existing tickets,
     * add client reply notes, auto-transition statuses, or auto-create a ticket.
     *
     * Public for testability. Called from both importSingleMessage() (webhook path)
     * and pollMailbox() (poll path) — these are separate code paths that each
     * handle their own import loop.
     */
    public function processInbound(Email $email): void
    {
        // direction is cast to EmailDirection enum — must compare to enum, not string
        if ($email->direction !== EmailDirection::Inbound || $email->ticket_id !== null) {
            return;
        }

        // Auto-dismiss auto-replies, OOO messages, bounces — they create junk tickets
        // and should never appear in the "Needs Attention" queue
        if ($this->isAutoReply($email)) {
            $email->update(['dismissed_at' => now()]);
            Log::info('[EmailService] Auto-dismissed auto-reply email', ['email_id' => $email->id]);

            return;
        }

        $ticket = $this->matchToExistingTicket($email);

        if ($ticket) {
            $this->linkEmailToTicket($email, $ticket);
            Log::info('[EmailService] Email matched to existing ticket', [
                'email_id' => $email->id,
                'ticket_id' => $ticket->id,
            ]);

            return;
        }

        // Auto-dismiss spam from unknown senders (no client, no contact match).
        // Known contacts are never filtered — a client saying "I can't unsubscribe"
        // will always have person_id/client_id set from resolveSender().
        if (! $email->client_id && ! $email->person_id) {
            $spamResult = $this->evaluateSpam($email);
            if ($spamResult) {
                $email->update(['dismissed_at' => now()]);
                Log::info('[EmailService] Auto-dismissed spam from unknown sender', [
                    'email_id' => $email->id,
                    'from' => $email->from_address,
                    'subject' => $email->subject,
                    'method' => $spamResult['method'],
                    'score' => $spamResult['score'],
                    'reason' => $spamResult['reason'],
                ]);

                return;
            }
        }

        // Auto-create only if: setting on, client known, AND email is recent (< 24h)
        // The 24h guard prevents mass auto-ticketing during poll backfill runs
        if (Setting::getValue('email_auto_ticket')
            && $email->client_id
            && $email->received_at >= now()->subHours(24)
        ) {
            $this->routeInboundEmail($email);
        }

        // Notify if email remains unresolved after all processing paths
        $email->refresh();
        if ($email->ticket_id === null && $email->received_at >= now()->subHours(24)) {
            app(NotificationService::class)->notifyUnresolvedEmail($email);
        }
    }

    /**
     * Heuristic filter for OOO, bounces, and auto-responders.
     * False negatives (letting a junk email through) are safer than false positives
     * (dropping a real client reply). Bias toward false negatives is intentional.
     */
    private function isAutoReply(Email $email): bool
    {
        $from = strtolower($email->from_address);
        $subject = strtolower($email->subject ?? '');

        // Mesh delivery requests come from noreply@emailsecurity.app but are legitimate
        // support requests, not auto-replies. Narrowed to delivery requests only so other
        // Mesh notification types still get filtered. Also allowlisted in JunkDetector's
        // MONITORING_ALLOWLIST — both layers must agree to pass vendor notifications.
        if (str_ends_with($from, '@emailsecurity.app')
            && str_starts_with($subject, 'email delivery request:')) {
            return false;
        }

        // Zorus unblock requests come from no-reply@zorustech.com — legitimate
        // support requests from end users whose DNS queries were blocked.
        if ($from === 'no-reply@zorustech.com'
            && str_contains($subject, 'requests to unblock')) {
            return false;
        }

        // Known bounce/system senders
        $systemSenders = ['mailer-daemon@', 'postmaster@', 'noreply@', 'no-reply@', 'donotreply@', 'microsoftexchange'];
        foreach ($systemSenders as $prefix) {
            if (str_contains($from, $prefix)) {
                return true;
            }
        }

        // Common auto-reply subject patterns
        $autoSubjects = [
            'out of office', 'auto:', 'automatic reply', 'autoreply',
            'delivery status notification', 'undeliverable', 'delivery failed',
            'failure notice', 'mail delivery failed', 'returned mail',
        ];
        foreach ($autoSubjects as $pattern) {
            if (str_contains($subject, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Evaluate an unknown-sender email for spam. Hybrid approach:
     * 1. Deterministic scoring for obvious spam (score >= 5) — instant, no AI cost
     * 2. AI evaluation for gray zone (score 1-4) — returns spam likelihood percentage
     *
     * Only called when client_id and person_id are both null, so known contacts
     * are never affected.
     *
     * @return array{method: string, score: int|float, reason: string}|null Null = not spam
     */
    private function evaluateSpam(Email $email): ?array
    {
        $score = $this->spamScore($email);

        // High confidence: obvious spam, no AI needed
        if ($score >= 5) {
            return [
                'method' => 'deterministic',
                'score' => $score,
                'reason' => "Deterministic spam score {$score}/10",
            ];
        }

        // Gray zone: some signals but not conclusive — ask AI if available
        if ($score >= 1 && AiConfig::isConfigured()) {
            $aiResult = $this->aiSpamCheck($email);
            if ($aiResult && $aiResult['spam_percent'] >= 70) {
                return [
                    'method' => 'ai',
                    'score' => $aiResult['spam_percent'],
                    'reason' => $aiResult['reason'] ?? "AI spam likelihood: {$aiResult['spam_percent']}%",
                ];
            }
        }

        return null;
    }

    /**
     * Deterministic spam scoring for unknown-sender emails.
     * Returns 0-10 score based on multiple weighted signals.
     */
    private function spamScore(Email $email): int
    {
        $body = strtolower(strip_tags($email->body_html ?? $email->body_text ?? ''));
        $subject = strtolower($email->subject ?? '');
        $from = strtolower($email->from_address ?? '');

        $score = 0;

        // Signal 1: Body contains unsubscribe language (weight: 2)
        $unsubPhrases = [
            'unsubscribe', 'opt out', 'opt-out', 'manage your preferences',
            'email preferences', 'stop receiving', 'remove from',
            'mailing list', 'you are receiving this',
        ];
        foreach ($unsubPhrases as $phrase) {
            if (str_contains($body, $phrase)) {
                $score += 2;
                break;
            }
        }

        // Signal 2: Body contains sales/marketing language (weight: 1 each, max 3)
        $salesPhrases = [
            'pre-qualified', 'pre-approved', 'limited time', 'act now',
            'exclusive offer', 'special offer', 'free consultation',
            'schedule a call', 'book a demo', 'get started today',
            'click here', 'apply now', 'get approved', 'no obligation',
            'qualified for up to', 'business funding', 'business loan',
            'line of credit', 'working capital', 'merchant cash advance',
        ];
        $salesMatches = 0;
        foreach ($salesPhrases as $phrase) {
            if (str_contains($body, $phrase) || str_contains($subject, $phrase)) {
                $salesMatches++;
                if ($salesMatches >= 3) {
                    break;
                }
            }
        }
        $score += min($salesMatches, 3);

        // Signal 3: Free email domain is extra suspicious for "business" outreach (weight: 1)
        $senderDomain = str_contains($from, '@') ? explode('@', $from, 2)[1] : '';
        if (in_array($senderDomain, self::FREE_EMAIL_DOMAINS)) {
            $score += 1;
        }

        // Signal 4: Physical address pattern typical of CAN-SPAM compliance (weight: 1)
        if (preg_match('/\b[A-Z]{2}\s+\d{5}\b/i', $body) || preg_match('/\b\d{5}[-–]\d{4}\b/', $body)) {
            $score += 1;
        }

        // Signal 5: Links to known marketing/tracking platforms (weight: 2)
        // Check raw HTML too — strip_tags removes href URLs but spammers embed tracking links
        $htmlLower = strtolower($email->body_html ?? '');
        $marketingDomains = [
            'acemlnd.com', 'activehosted.com', 'mailchimp.com', 'sendgrid.net',
            'constantcontact.com', 'hubspot.com', 'mailgun.net', 'aweber.com',
            'getresponse.com', 'activecampaign.com', 'drip.com', 'convertkit.com',
            'mailerlite.com', 'brevo.com', 'sendinblue.com', 'campaign-archive.com',
            'list-manage.com', 'click.pstmrk.it', 'emltrk.com', 'mktomail.com',
        ];
        foreach ($marketingDomains as $domain) {
            if (str_contains($body, $domain) || str_contains($htmlLower, $domain)) {
                $score += 2;
                break;
            }
        }

        return $score;
    }

    /**
     * Ask AI to evaluate whether an email is spam.
     * Returns spam likelihood percentage (0-100) and reason.
     * Returns null on AI failure (fail-open — email passes through).
     *
     * @return array{spam_percent: int, reason: string}|null
     */
    private function aiSpamCheck(Email $email): ?array
    {
        $body = mb_substr(strip_tags($email->body_html ?? $email->body_text ?? ''), 0, 3000);

        $prompt = <<<PROMPT
You are a spam filter for an IT Managed Services Provider's support inbox.

Evaluate this email and determine the likelihood it is spam, marketing, or unsolicited outreach (NOT a legitimate support request from a client or potential client).

Context: This email is from an unknown sender — it did not match any known client or contact in our system. Legitimate new support requests DO come from unknown senders sometimes (new employees, new clients, referrals), so unknown sender alone does not mean spam.

SENDER: {$email->from_address}
SENDER NAME: {$email->from_name}
SUBJECT: {$email->subject}

BODY:
{$body}

Respond with a JSON object only. No markdown fences.
{"spam_percent": 0-100, "reason": "Brief explanation"}

- 0-30: Likely legitimate (support request, inquiry, vendor notification)
- 31-69: Uncertain
- 70-100: Likely spam (cold sales, marketing, phishing, unsolicited outreach)
PROMPT;

        try {
            $ai = new \App\Services\Ai\AiClient;
            $result = $ai->completeJson(
                'You are a spam detection assistant. Respond only with the requested JSON.',
                $prompt,
                200,
            );

            $percent = (int) ($result['spam_percent'] ?? 50);
            $reason = $result['reason'] ?? 'No reason provided';

            Log::info('[EmailService] AI spam check', [
                'email_id' => $email->id,
                'from' => $email->from_address,
                'spam_percent' => $percent,
                'reason' => $reason,
            ]);

            return ['spam_percent' => $percent, 'reason' => $reason];
        } catch (\Throwable $e) {
            Log::warning('[EmailService] AI spam check failed, passing email through', [
                'email_id' => $email->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Match an inbound email to an existing ticket, in priority order:
     *  1. conversation_id  — same Graph conversation thread (most reliable)
     *  2. In-Reply-To      — RFC 5322 header chain
     *  3. Subject [T-123]  — Sound PSA ticket ID
     *  4. Subject [ID:123] — Halo ticket ID (legacy)
     *  5. Subject [#123]   — Halo display ID (legacy)
     *
     * Subject-token matching lets staff thread a forwarded email onto an
     * existing ticket by putting the ticket's display ID in the subject — the
     * basis of the forward-attribution flow (see ForwardedEmailParser).
     */
    private function matchToExistingTicket(Email $email): ?Ticket
    {
        // 1. conversation_id — same Graph conversation thread
        if ($email->conversation_id) {
            $match = Email::where('conversation_id', $email->conversation_id)
                ->whereNotNull('ticket_id')
                ->where('id', '!=', $email->id)
                ->orderByDesc('received_at')
                ->with('ticket')
                ->first();
            if ($match?->ticket) {
                return $match->ticket;
            }
        }

        // 2. In-Reply-To — RFC 5322 header chain
        if ($email->in_reply_to) {
            $match = Email::where('internet_message_id', $email->in_reply_to)
                ->whereNotNull('ticket_id')
                ->with('ticket')
                ->first();
            if ($match?->ticket) {
                return $match->ticket;
            }
        }

        // 3. Subject [T-123] — Sound PSA ticket ID
        $subject = $email->subject ?? '';
        if (preg_match('/\[T-(\d+)\]/i', $subject, $m)) {
            $ticket = Ticket::find((int) $m[1]);
            if ($ticket) {
                return $ticket;
            }
        }

        // 4. Subject [ID:123] — Halo ticket ID
        if (preg_match('/\[ID:(\d+)\]/i', $subject, $m)) {
            $ticket = Ticket::where('halo_id', (int) $m[1])->first();
            if ($ticket) {
                return $ticket;
            }
        }

        // 5. Subject [#123] — Halo display ID (e.g., [#72504])
        if (preg_match('/\[#(\d+)\]/i', $subject, $m)) {
            $ticket = Ticket::where('halo_id', (int) $m[1])->first();
            if ($ticket) {
                return $ticket;
            }
        }

        return null;
    }

    /**
     * Link an email to a ticket: set ticket_id, create a client reply note,
     * and auto-transition PendingClient or Resolved tickets to InProgress.
     *
     * - Downloads email attachments via Graph and links them to the note
     * - If inline attachments exist, replaces CID refs in HTML body (sanitized via HtmlSanitizer)
     * - Falls back to body_text when no inline images present
     * - author_id = null, who_type = WhoType::EndUser — renders green avatar in timeline
     * - noted_at = email->received_at — note appears at email arrival time, not processing time
     * - Closed excluded from auto-reopen — explicit team decision must not be overridden
     * - PendingThirdParty excluded — client reply does not mean the third party responded
     */
    public function linkEmailToTicket(Email $email, Ticket $ticket, array $predownloadedAttachments = []): void
    {
        // Mark as read so the email UI unread count stays meaningful
        $email->update(['ticket_id' => $ticket->id, 'is_read' => true]);

        $body = trim($email->body_text ?? $this->extractPlainText($email->body_html) ?? '');

        // Always try to download attachments when we have a Graph ID — Microsoft
        // Graph's hasAttachments field is FALSE for messages containing only
        // inline images, so gating on it loses screenshot-only emails.
        if ($body !== '' || $email->has_attachments || $email->graph_id) {
            $attachmentService = app(AttachmentService::class);
            $emailAttachments = $predownloadedAttachments;
            if (empty($emailAttachments) && $email->graph_id) {
                $graph = app(GraphClient::class);
                $mailbox = Setting::getValue('graph_mailbox');
                if ($mailbox) {
                    $emailAttachments = $attachmentService->downloadEmailAttachments($email, $graph, $mailbox);
                }
            }

            // Build body_html: use email HTML with CID replacement if we have inline attachments
            $bodyHtml = null;
            $hasInline = collect($emailAttachments)->contains(fn ($a) => $a->is_inline);
            if ($hasInline && $email->body_html) {
                $bodyHtml = $attachmentService->replaceCidReferences($email->body_html, $emailAttachments);
                $bodyHtml = HtmlSanitizer::sanitize($bodyHtml);
            }

            // Forwarded customer emails arrive with the forwarder (a technician)
            // as the envelope sender. Recover the original sender so the note is
            // attributed to the customer, with a provenance line naming the forwarder.
            $authorName = $email->from_name ?? $email->from_address;
            if (ForwardedEmailParser::isForwarded($email)) {
                $sender = ForwardedEmailParser::parseOriginalSender($email);
                if ($sender && $sender['email'] !== strtolower($email->from_address)) {
                    $authorName = $sender['name'] ?? $sender['email'];
                    $forwarder = $email->from_name ?? $email->from_address;
                    $provenance = "[Forwarded into {$ticket->display_id} by {$forwarder}]";
                    $body = $provenance."\n\n".($body !== '' ? $body : '[see attachments]');
                    if ($bodyHtml !== null) {
                        $bodyHtml = '<p>'.e($provenance).'</p>'.$bodyHtml;
                    }
                }
            }

            $note = TicketNote::create([
                'ticket_id' => $ticket->id,
                'author_id' => null,
                'author_name' => $authorName,
                'who_type' => WhoType::EndUser,
                'email_id' => $email->id,
                'body' => $body ?: '[see attachments]',
                'body_html' => $bodyHtml,
                'note_type' => NoteType::Reply,
                'is_private' => false,
                'noted_at' => $email->received_at,
            ]);

            // Link attachments to the note
            foreach ($emailAttachments as $attachment) {
                $attachmentService->linkTo($attachment, 'App\\Models\\TicketNote', $note->id);
            }

            // AI Technician (Plan 1B): a client reply re-opens drafting. The pipeline's
            // own substance/idempotency logic (Task 10) decides whether to actually draft.
            if (\App\Support\TechnicianConfig::enabled()) {
                \App\Jobs\RunTechnicianLoop::dispatch($ticket->id);
            }
        }

        // Touch ticket so updated_at reflects latest activity
        $ticket->touch();

        app(NotificationService::class)->notifyEmailAdded($ticket, $email);

        // Auto-transition PendingClient or Resolved → InProgress when client replies
        $reopenable = [TicketStatus::PendingClient, TicketStatus::Resolved];
        if (in_array($ticket->status, $reopenable, true)) {
            if ($ticket->status === TicketStatus::PendingClient && $ticket->pending_since) {
                // Use email->received_at (not now()) so webhook delivery delays don't inflate SLA pending time
                $elapsed = (int) $ticket->pending_since->diffInMinutes($email->received_at);
                $ticket->update([
                    'status' => TicketStatus::InProgress,
                    'pending_since' => null,
                    'total_pending_minutes' => (int) $ticket->total_pending_minutes + $elapsed,
                ]);
            } else {
                // Clear resolved_at so net_elapsed_minutes and isOverdue() are not frozen at old resolution time
                $ticket->update(['status' => TicketStatus::InProgress, 'resolved_at' => null]);
            }

            TicketNote::create([
                'ticket_id' => $ticket->id,
                'author_id' => null,
                'author_name' => 'System',
                'who_type' => WhoType::System,
                'body' => 'Client replied via email — status updated to In Progress.',
                'note_type' => NoteType::StatusChange,
                'is_private' => true,
                'noted_at' => now(),
            ]);
        }
    }

    /**
     * Intake front-door (psa-xcyo): when enabled, ask the IntakeRouter whether this
     * known-sender email belongs to an existing OPEN ticket (attach) or is a new issue
     * (create). DORMANT → calls autoCreateTicketFromEmail exactly as before.
     *
     * Behaviour:
     *  - DORMANT (intake_enabled off): autoCreateTicketFromEmail — byte-identical.
     *  - enabled + create decision: autoCreateTicketFromEmail, no intake_route record.
     *  - enabled + attach, conf >= threshold (set): linkEmailToTicket (no new ticket) + Done record.
     *  - enabled + attach, threshold null / below threshold (held-first): autoCreate + AwaitingApproval record.
     *  - enabled + attach but ticket vanished/closed/cross-client: falls through to autoCreate (safe).
     *  - any exception: fail-soft → autoCreateTicketFromEmail, never loses an email.
     *
     * DOUBLE-CREATE GUARD: autoCreateTicketFromEmail is called ONCE, OUTSIDE the try block.
     * The old pattern (autoCreate inside try + retry in catch when ticket_id is null) created
     * an orphan ticket when autoCreate threw after persisting the ticket row but before
     * linkEmailToTicket set ticket_id. The restructured path has a single call site with no
     * catch-block retry — a throw propagates immediately rather than triggering a second create.
     */
    private function routeInboundEmail(Email $email): void
    {
        if (! AgentConfig::intakeEnabled()) {
            $this->autoCreateTicketFromEmail($email);

            return;
        }

        // Initialise with a safe "router unavailable" fallback so $decision is always defined
        // after the try block, even when the router throws.
        $decision = \App\Services\Agent\Intake\IntakeDecision::create('router unavailable');
        try {
            $decision = app(IntakeRouter::class)->route($email);
            $threshold = AgentConfig::intakeAttachAutoThreshold();

            // GRADUATED auto-attach — only when confident AND the threshold is set.
            if ($decision->isAttach() && $threshold !== null && $decision->confidence >= $threshold) {
                $ticket = Ticket::find($decision->ticketId);
                // Re-validate server-side: still the same client + still open (may have changed since route).
                if ($ticket && $ticket->client_id === $email->client_id && $ticket->status->isOpen()) {
                    $this->linkEmailToTicket($email, $ticket);
                    $this->recordIntakeRoute($email, $decision, attachedTicketId: $ticket->id, createdTicketId: null);

                    return;
                }
                // ticket vanished/closed/cross-client → fall through to the single safe create below.
            }
        } catch (\Throwable $e) {
            Log::warning('[Intake] route/attach failed — falling back to create', [
                'email_id' => $email->id,
                'error' => $e->getMessage(),
            ]);
            // fall through to the single safe create below
        }

        // Single create path — runs at most once, OUTSIDE the try (no retry double-create).
        // The ticket_id guard handles the rare case linkEmailToTicket (inside try) already ticketed
        // the email before throwing, so we don't double-create on attach-path exceptions either.
        if ($email->fresh()->ticket_id === null) {
            $this->autoCreateTicketFromEmail($email);
        }
        // If the router suggested an attach (held-first / below threshold) record it as an
        // observational suggestion even though we created a new ticket (the safe default).
        if ($decision->isAttach()) {
            $this->recordIntakeRoute($email, $decision, attachedTicketId: null, createdTicketId: $email->fresh()->ticket_id);
        }
    }

    /**
     * Write an observational intake_route TechnicianRun for the cockpit Intake lane.
     *
     * - attachedTicketId non-null → auto-attach already actioned → Done state.
     * - createdTicketId non-null → held suggestion (duplicate created) → AwaitingApproval state.
     *
     * content_hash is keyed on the email id so re-runs on the same email are idempotent.
     * ticket_id is the attached or newly created ticket (non-null FK required by the schema).
     */
    private function recordIntakeRoute(Email $email, IntakeDecision $decision, ?int $attachedTicketId, ?int $createdTicketId): void
    {
        $auto = $attachedTicketId !== null;

        TechnicianRun::create([
            'ticket_id' => $attachedTicketId ?? $createdTicketId,
            'client_id' => $email->client_id,
            'action_type' => 'intake_route',
            'content_hash' => hash('sha256', 'intake:'.$email->id),
            'state' => $auto ? TechnicianRunState::Done : TechnicianRunState::AwaitingApproval,
            'proposed_content' => mb_substr($decision->reason, 0, 1000),
            'proposed_meta' => [
                'email_id' => $email->id,
                'decision' => $decision->decision,
                'suggested_ticket_id' => $decision->ticketId,
                'confidence' => $decision->confidence,
                'attached' => $auto,
                'created_ticket_id' => $createdTicketId,
            ],
            'tokens_used' => 0,
        ]);
    }

    /**
     * Auto-create a ticket from an inbound email for a known client.
     * Only called when: email_auto_ticket setting is on, client_id is set,
     * and the email was received within the last 24 hours (backfill guard).
     */
    private function autoCreateTicketFromEmail(Email $email): void
    {
        $isMeshDeliveryRequest = MeshEmailParser::isMeshDeliveryRequest($email);
        $isZorusUnblockRequest = ZorusEmailParser::isZorusUnblockRequest($email);
        $isVendorRequest = $isMeshDeliveryRequest || $isZorusUnblockRequest;

        // Dedup: vendor notification emails often arrive in bursts for the same issue.
        // If an open ticket with the same subject exists for this client within 2 hours, link instead of creating a duplicate.
        if ($isVendorRequest) {
            $existing = Ticket::where('client_id', $email->client_id)
                ->where('subject', $email->subject)
                ->whereNotIn('status', [TicketStatus::Closed, TicketStatus::Resolved])
                ->where('created_at', '>=', now()->subHours(2))
                ->first();

            if ($existing) {
                $this->linkEmailToTicket($email, $existing);
                Log::info('[EmailService] Linked duplicate vendor request to existing ticket', [
                    'email_id' => $email->id,
                    'ticket_id' => $existing->id,
                    'type' => $isMeshDeliveryRequest ? 'Mesh' : 'Zorus',
                ]);

                return;
            }
        }

        $subject = trim(preg_replace('/^(Re:|Fwd?:)\s*/i', '', $email->subject ?? ''));
        if (! $subject) {
            $subject = 'Email from '.($email->from_name ?? $email->from_address);
        }
        $subject = Str::limit($subject, 250); // Guard against DB truncation on long subjects

        // Vendor requests get enriched ticket data
        $ticketData = [
            'subject' => $subject,
            'client_id' => $email->client_id,
            'contact_id' => $email->person_id,
            'priority' => TicketPriority::P3->value,
            'source' => TicketSource::Email->value,
        ];

        if ($isMeshDeliveryRequest) {
            $parsed = MeshEmailParser::parse($email);
            $ticketData['type'] = TicketType::ServiceRequest->value;

            if ($parsed) {
                $ticketData['description'] = MeshEmailParser::buildDescription($parsed);
            }
        } elseif ($isZorusUnblockRequest) {
            $parsed = ZorusEmailParser::parse($email);
            $ticketData['type'] = TicketType::ServiceRequest->value;

            if ($parsed) {
                $ticketData['description'] = ZorusEmailParser::buildDescription($parsed);

                // Link the asset by hostname so the ticket has the device attached
                $zorusHostname = $parsed['hostname'] ? html_entity_decode($parsed['hostname']) : null;
                if ($zorusHostname && $email->client_id) {
                    $zorusAsset = \App\Models\Asset::where('client_id', $email->client_id)
                        ->where(fn ($q) => $q->whereRaw('LOWER(hostname) = ?', [strtolower($zorusHostname)])
                            ->orWhereRaw('LOWER(name) = ?', [strtolower($zorusHostname)]))
                        ->first();
                }
            }
        } else {
            $ticketData['type'] = TicketType::Incident->value;
        }

        $ticket = $this->ticketService->createTicket($ticketData, null);

        // Link Zorus asset to ticket
        if (isset($zorusAsset)) {
            $ticket->assets()->syncWithoutDetaching([$zorusAsset->id]);
        }

        // Download email attachments for the description. We always attempt
        // the download when we have a Graph ID — Microsoft Graph's
        // hasAttachments field is FALSE for messages containing only inline
        // images (screenshots in Outlook), so gating on has_attachments
        // silently strips screenshot-only emails of their images.
        $attachmentService = app(AttachmentService::class);
        if ($email->graph_id) {
            $graph = app(GraphClient::class);
            $mailbox = Setting::getValue('graph_mailbox');
            if ($mailbox) {
                $emailAttachments = $attachmentService->downloadEmailAttachments($email, $graph, $mailbox);

                if (! empty($emailAttachments)) {
                    $hasInline = collect($emailAttachments)->contains(fn ($a) => $a->is_inline);
                    if ($hasInline && $email->body_html) {
                        $descHtml = $attachmentService->replaceCidReferences($email->body_html, $emailAttachments);
                        $descHtml = HtmlSanitizer::sanitize($descHtml);
                        $ticket->update(['description_html' => $descHtml]);
                    }

                    foreach ($emailAttachments as $attachment) {
                        $attachmentService->linkTo($attachment, 'App\\Models\\Ticket', $ticket->id);
                    }
                }
            }
        }

        $this->linkEmailToTicket($email, $ticket, $emailAttachments ?? []);

        Log::info('[EmailService] Auto-created ticket from email', [
            'email_id' => $email->id,
            'ticket_id' => $ticket->id,
            'mesh_delivery_request' => $isMeshDeliveryRequest,
        ]);
    }

    /**
     * Resolve the sender to a person and/or client.
     */
    public function resolveSender(Email $email): Email
    {
        $fromAddress = strtolower($email->from_address);

        // 0. Vendor notification emails: resolve by the actual user in the body,
        // not the vendor's noreply address. Must run before generic person lookup
        // to avoid matching a stale Person record for the noreply address.
        if (MeshEmailParser::isMeshDeliveryRequest($email)) {
            return $this->resolveFromMeshDeliveryRequest($email);
        }
        if (ZorusEmailParser::isZorusUnblockRequest($email)) {
            return $this->resolveFromZorusUnblockRequest($email);
        }

        // 1. Check if sender is a staff member
        $user = User::where('email', $fromAddress)->first();
        if ($user) {
            $email->update(['user_id' => $user->id]);

            return $email;
        }

        // 2. Exact match by email address (includes additional emails)
        $person = Person::whereEmailMatch($fromAddress)->first();
        if ($person) {
            $email->update([
                'person_id' => $person->id,
                'client_id' => $person->client_id,
            ]);

            return $email;
        }

        $domain = $email->fromDomain();
        if (! $domain || in_array(strtolower($domain), self::FREE_EMAIL_DOMAINS)) {
            return $email; // Can't resolve free email domains
        }

        // 3. Match client by website domain
        $client = Client::where('website', 'like', "%{$domain}%")
            ->active()
            ->first();

        if ($client) {
            $email->update(['client_id' => $client->id]);

            return $email;
        }

        // 4. Match client via people with same domain (includes additional emails)
        $personWithDomain = Person::whereEmailDomain($domain)
            ->whereNotNull('client_id')
            ->first();

        if ($personWithDomain) {
            $email->update(['client_id' => $personWithDomain->client_id]);

            return $email;
        }

        return $email;
    }

    /**
     * Resolve client/contact from a Mesh delivery request email.
     * Extracts the recipient email from the parsed body and runs the same
     * resolution chain (person match → website domain → people domain).
     */
    private function resolveFromMeshDeliveryRequest(Email $email): Email
    {
        $parsed = MeshEmailParser::parse($email);
        $recipientEmail = $parsed['recipient'] ?? null;

        if (! $recipientEmail) {
            return $email;
        }

        // Note: parse() HTML-escapes values, but we need the raw email for lookups
        $recipientEmail = strtolower(html_entity_decode($recipientEmail));

        // Try exact person match on the recipient
        $person = Person::whereEmailMatch($recipientEmail)->first();
        if ($person) {
            $email->update([
                'person_id' => $person->id,
                'client_id' => $person->client_id,
            ]);

            return $email;
        }

        // Fall back to domain-based resolution (same as steps 2-3 above)
        $recipientDomain = Str::after($recipientEmail, '@');
        if (! $recipientDomain || in_array($recipientDomain, self::FREE_EMAIL_DOMAINS)) {
            return $email;
        }

        $client = Client::where('website', 'like', "%{$recipientDomain}%")
            ->active()
            ->first();

        if ($client) {
            $email->update(['client_id' => $client->id]);

            return $email;
        }

        $personWithDomain = Person::whereEmailDomain($recipientDomain)
            ->whereNotNull('client_id')
            ->first();

        if ($personWithDomain) {
            $email->update(['client_id' => $personWithDomain->client_id]);

            return $email;
        }

        return $email;
    }

    /**
     * Resolve client/contact from a Zorus domain unblock request email.
     *
     * Resolution strategy (two paths, first match wins):
     * 1. Company name → Zorus API → zorus_customer_id → client
     * 2. Hostname → asset (by hostname or zorus_endpoint_id) → client
     *
     * Then: person by name match within client, or via shared contract assignments.
     */
    private function resolveFromZorusUnblockRequest(Email $email): Email
    {
        $parsed = ZorusEmailParser::parse($email);
        if (! $parsed) {
            return $email;
        }

        $companyName = $parsed['company_name'] ? html_entity_decode($parsed['company_name']) : null;
        $hostname = $parsed['hostname'] ? html_entity_decode($parsed['hostname']) : null;

        $client = null;
        $asset = null;
        $resolvedVia = 'none';

        // Strategy 1: company name → Zorus API → client
        if ($companyName) {
            $client = ZorusEmailParser::resolveClient($companyName);
            if ($client) {
                $resolvedVia = 'api';
            }
        }

        // Strategy 2: hostname → asset → client (fallback if API lookup fails)
        if (! $client && $hostname) {
            $asset = \App\Models\Asset::where(fn ($q) => $q
                ->whereRaw('LOWER(hostname) = ?', [strtolower($hostname)])
                ->orWhereRaw('LOWER(name) = ?', [strtolower($hostname)]))
                ->whereHas('client', fn ($q) => $q->whereNotNull('zorus_customer_id'))
                ->first();

            if ($asset) {
                $client = $asset->client;
                $resolvedVia = 'hostname';
            }
        }

        if (! $client) {
            Log::info('[EmailService] Zorus unblock request: no client match', [
                'email_id' => $email->id,
                'company_name' => $companyName,
                'hostname' => $hostname,
            ]);

            return $email;
        }

        $updates = ['client_id' => $client->id];

        // Find the asset within this client if we didn't already
        if (! $asset && $hostname) {
            $asset = \App\Models\Asset::where('client_id', $client->id)
                ->where(fn ($q) => $q->whereRaw('LOWER(hostname) = ?', [strtolower($hostname)])
                    ->orWhereRaw('LOWER(name) = ?', [strtolower($hostname)]))
                ->first();
        }

        // Resolve person: try name match first, then contract-based
        if ($companyName) {
            $person = Person::where('client_id', $client->id)
                ->whereRaw("CONCAT(first_name, ' ', last_name) = ?", [$companyName])
                ->first();

            if ($person) {
                $updates['person_id'] = $person->id;
            }
        }

        if (! isset($updates['person_id']) && $asset) {
            $sharedContractIds = $asset->contracts()->pluck('contracts.id');
            if ($sharedContractIds->isNotEmpty()) {
                $person = Person::where('client_id', $client->id)
                    ->whereHas('contracts', fn ($q) => $q->whereIn('contracts.id', $sharedContractIds))
                    ->first();

                if ($person) {
                    $updates['person_id'] = $person->id;
                }
            }
        }

        $email->update($updates);

        Log::info('[EmailService] Resolved Zorus unblock request', [
            'email_id' => $email->id,
            'client_id' => $client->id,
            'person_id' => $updates['person_id'] ?? null,
            'hostname' => $hostname,
            'strategy' => $resolvedVia,
        ]);

        return $email;
    }

    /**
     * Get a filtered, paginated list of emails.
     */
    public function getEmailList(array $filters): LengthAwarePaginator
    {
        $query = Email::with(['client', 'person', 'user', 'ticket' => fn ($q) => $q->select('id', 'halo_id', 'subject')])
            ->orderByDesc('received_at');

        $preset = $filters['preset'] ?? null;

        // Preset-based filtering
        if ($preset === 'needs_attention') {
            $query->needsAttention();
        } elseif ($preset === 'inbound') {
            $query->inbound();
        } elseif ($preset === 'outbound') {
            $query->where('direction', EmailDirection::Outbound);
        } elseif ($preset === 'dismissed') {
            $query->whereNotNull('dismissed_at');
        }

        // Direction filter (when not using a preset)
        if (! $preset && ! empty($filters['direction'])) {
            $query->where('direction', $filters['direction']);
        }

        if (! empty($filters['search'])) {
            $query->search($filters['search']);
        }

        if (isset($filters['is_read']) && $filters['is_read'] !== '') {
            $query->where('is_read', (bool) $filters['is_read']);
        }

        if (! empty($filters['date_from'])) {
            $query->where('received_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->where('received_at', '<=', Carbon::parse($filters['date_to'])->endOfDay());
        }

        if (! empty($filters['client_id'])) {
            $query->where('client_id', $filters['client_id']);
        }

        if (! empty($filters['no_client'])) {
            $query->noClient();
        }

        // Default to last 7 days only for "all" view with no date/search filters
        if ($preset === 'all' || (! $preset && empty($filters['direction']))) {
            if (empty($filters['date_from']) && empty($filters['date_to']) && empty($filters['search'])) {
                $query->where('received_at', '>=', now()->subDays(7));
            }
        }

        return $query->paginate(50)->withQueryString();
    }

    public function dismissEmail(Email $email, int $userId): void
    {
        $email->update([
            'dismissed_at' => now(),
            'dismissed_by' => $userId,
        ]);
    }

    public function undismissEmail(Email $email): void
    {
        $email->update([
            'dismissed_at' => null,
            'dismissed_by' => null,
        ]);
    }

    public function bulkDismiss(array $emailIds, int $userId): int
    {
        return Email::whereIn('id', $emailIds)
            ->whereNull('dismissed_at')
            ->update([
                'dismissed_at' => now(),
                'dismissed_by' => $userId,
            ]);
    }

    public function bulkLinkToTicket(array $emailIds, int $ticketId): int
    {
        $ticket = Ticket::findOrFail($ticketId);
        $count = 0;

        foreach (Email::whereIn('id', $emailIds)->whereNull('ticket_id')->get() as $email) {
            $this->linkEmailToTicket($email, $ticket);
            $count++;
        }

        return $count;
    }

    /**
     * Send a reply to an inbound email via Graph API.
     *
     * Body is plain text — converted to HTML server-side to avoid XSS.
     * Outbound Email record is stored only after Graph confirms delivery (202).
     *
     * Uses Graph's native reply endpoint when graph_id is available (proper threading).
     * Falls back to sendMail for emails without graph_id (no threading).
     */
    public function sendReply(Email $original, string $bodyText, ?array $cc = null): Email
    {
        $mailbox = Setting::getValue('graph_mailbox');
        if (! $mailbox) {
            throw new GraphClientException('No mailbox configured (graph_mailbox setting is empty).');
        }

        // Build HTML body from plain text + signature
        $bodyHtml = $this->buildHtmlBody($bodyText, auth()->id());

        $replySubject = str_starts_with($original->subject, 'Re:')
            ? $original->subject
            : "Re: {$original->subject}";

        if ($original->graph_id) {
            // Use Graph's native reply endpoint — handles In-Reply-To/References automatically
            $payload = [
                'message' => [
                    'body' => ['contentType' => 'HTML', 'content' => $bodyHtml],
                ],
                'comment' => '', // Required but we use body instead
            ];

            if ($cc) {
                $payload['message']['ccRecipients'] = array_map(fn (string $addr) => [
                    'emailAddress' => ['address' => strtolower(trim($addr))],
                ], $cc);
            }

            $this->graphClient->post("users/{$mailbox}/messages/{$original->graph_id}/reply", $payload);
        } else {
            // Fallback: sendMail without threading headers (Graph doesn't allow In-Reply-To via sendMail)
            Log::warning('[EmailService] Replying to email without graph_id — reply will not thread', [
                'email_id' => $original->id,
            ]);

            $payload = [
                'message' => [
                    'subject' => $replySubject,
                    'body' => ['contentType' => 'HTML', 'content' => $bodyHtml],
                    'toRecipients' => [['emailAddress' => [
                        'address' => $original->from_address,
                        'name' => $original->from_name,
                    ]]],
                ],
            ];

            if ($cc) {
                $payload['message']['ccRecipients'] = array_map(fn (string $addr) => [
                    'emailAddress' => ['address' => strtolower(trim($addr))],
                ], $cc);
            }

            $this->graphClient->post("users/{$mailbox}/sendMail", $payload);
        }

        // Store outbound record only after successful send
        return Email::create([
            'graph_id' => null,
            'direction' => 'outbound',
            'from_address' => strtolower($mailbox),
            'from_name' => null,
            'to_recipients' => [['name' => $original->from_name, 'address' => $original->from_address]],
            'cc_recipients' => $cc ? array_map(fn ($a) => ['address' => strtolower(trim($a))], $cc) : null,
            'subject' => $replySubject,
            'body_preview' => mb_substr($bodyText, 0, 500),
            'body_text' => $bodyText,
            'body_html' => $bodyHtml,
            'conversation_id' => $original->conversation_id,
            'internet_message_id' => null,
            'in_reply_to' => $original->internet_message_id,
            'has_attachments' => false,
            'importance' => 'normal',
            'received_at' => now(),
            'is_read' => true,
            'client_id' => $original->client_id,
            'person_id' => $original->person_id,
            'user_id' => $original->user_id,
            'ticket_id' => $original->ticket_id,
        ]);
    }

    /**
     * Send a new email (not a reply) via Graph API.
     */
    public function sendNew(string $to, string $subject, string $bodyText, ?string $toName = null, ?array $cc = null, ?int $userId = null): Email
    {
        $mailbox = Setting::getValue('graph_mailbox');
        if (! $mailbox) {
            throw new GraphClientException('No mailbox configured (graph_mailbox setting is empty).');
        }

        $bodyHtml = $this->buildHtmlBody($bodyText, $userId);

        $payload = [
            'message' => [
                'subject' => $subject,
                'body' => ['contentType' => 'HTML', 'content' => $bodyHtml],
                'toRecipients' => [['emailAddress' => [
                    'address' => strtolower(trim($to)),
                    'name' => $toName,
                ]]],
            ],
        ];

        if ($cc) {
            $payload['message']['ccRecipients'] = array_map(fn (string $addr) => [
                'emailAddress' => ['address' => strtolower(trim($addr))],
            ], $cc);
        }

        $this->graphClient->post("users/{$mailbox}/sendMail", $payload);

        $resolved = $this->resolveRecipient(strtolower(trim($to)));

        return Email::create([
            'graph_id' => null,
            'direction' => 'outbound',
            'from_address' => strtolower($mailbox),
            'from_name' => null,
            'to_recipients' => [['name' => $toName, 'address' => strtolower(trim($to))]],
            'cc_recipients' => $cc ? array_map(fn ($a) => ['address' => strtolower(trim($a))], $cc) : null,
            'subject' => $subject,
            'body_preview' => mb_substr($bodyText, 0, 500),
            'body_text' => $bodyText,
            'body_html' => $bodyHtml,
            'conversation_id' => null,
            'internet_message_id' => null,
            'in_reply_to' => null,
            'has_attachments' => false,
            'importance' => 'normal',
            'received_at' => now(),
            'is_read' => true,
            'client_id' => $resolved['client_id'],
            'person_id' => $resolved['person_id'],
            'user_id' => $resolved['user_id'],
        ]);
    }

    /**
     * Send a ticket reply note to the ticket's contact via Graph API.
     *
     * Returns the Email record if sent, null if silently skipped (no mailbox configured
     * or no contact email). Caller is responsible for showing appropriate flash messages.
     */
    public function sendTicketReplyNote(Ticket $ticket, TicketNote $note, ?string $toEmail = null, array $ccEmails = []): ?Email
    {
        $mailbox = Setting::getValue('graph_mailbox');
        $toEmail = $toEmail ?: $ticket->contact?->email;

        if (! $mailbox || ! $toEmail) {
            Log::info('[EmailService] Skipping ticket reply email', [
                'ticket_id' => $ticket->id,
                'reason' => ! $mailbox ? 'no graph_mailbox configured' : 'no contact email',
            ]);

            return null;
        }

        // Use "Re:" only for email-sourced tickets — other sources are first contact
        $prefix = $ticket->source === TicketSource::Email ? 'Re: ' : '';
        $subject = $prefix.'['.$ticket->display_id.'] '.$ticket->subject;
        $bodyHtml = $this->buildHtmlBody($note->body, $note->author_id);

        // Resolve TO recipient name from contacts if possible
        $toName = '';
        if (strtolower(trim($toEmail)) === strtolower($ticket->contact?->email ?? '')) {
            $toName = $ticket->contact->fullName ?? '';
        }

        $payload = [
            'message' => [
                'subject' => $subject,
                'body' => ['contentType' => 'HTML', 'content' => $bodyHtml],
                'toRecipients' => [[
                    'emailAddress' => [
                        'address' => strtolower(trim($toEmail)),
                        'name' => $toName,
                    ],
                ]],
            ],
        ];

        if ($ccEmails) {
            $payload['message']['ccRecipients'] = array_map(fn (string $addr) => [
                'emailAddress' => ['address' => strtolower(trim($addr))],
            ], $ccEmails);
        }

        $this->graphClient->post("users/{$mailbox}/sendMail", $payload);

        // Build CC recipients array for Email record
        $ccRecipientsData = $ccEmails
            ? array_map(fn ($addr) => ['address' => strtolower(trim($addr)), 'name' => null], $ccEmails)
            : null;

        // Record outbound email — graph_id is NULL (Graph returns 202 with no body)
        try {
            return Email::create([
                'graph_id' => null,
                'direction' => 'outbound',
                'from_address' => strtolower($mailbox),
                'from_name' => null,
                'to_recipients' => [['address' => strtolower(trim($toEmail)), 'name' => $toName ?: null]],
                'cc_recipients' => $ccRecipientsData,
                'subject' => $subject,
                'body_preview' => mb_substr($note->body, 0, 500),
                'body_text' => $note->body,
                'body_html' => $bodyHtml,
                'conversation_id' => null,
                'internet_message_id' => null,
                'in_reply_to' => null,
                'has_attachments' => false,
                'importance' => 'normal',
                'received_at' => now(),
                'is_read' => true,
                'client_id' => $ticket->client_id,
                'person_id' => $ticket->contact_id,
                'ticket_id' => $ticket->id,
            ]);
        } catch (\Throwable $e) {
            Log::error('[EmailService] Email sent but record creation failed', [
                'ticket_id' => $ticket->id,
                'to' => $toEmail,
                'subject' => $subject,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Resolve an outbound recipient address to client_id/person_id.
     * Same 3-step logic as resolveSender, but for outbound emails.
     */
    public function resolveRecipient(string $address): array
    {
        $result = ['client_id' => null, 'person_id' => null, 'user_id' => null];

        // 0. Check if recipient is a staff member
        $user = User::where('email', $address)->first();
        if ($user) {
            $result['user_id'] = $user->id;

            return $result;
        }

        // 1. Exact match by email address (includes additional emails)
        $person = Person::whereEmailMatch($address)->first();
        if ($person) {
            $result['person_id'] = $person->id;
            $result['client_id'] = $person->client_id;

            return $result;
        }

        $parts = explode('@', $address);
        $domain = $parts[1] ?? null;
        if (! $domain || in_array(strtolower($domain), self::FREE_EMAIL_DOMAINS)) {
            return $result;
        }

        // 2. Match client by website domain
        $client = Client::where('website', 'like', "%{$domain}%")
            ->active()
            ->first();

        if ($client) {
            $result['client_id'] = $client->id;

            return $result;
        }

        // 3. Match client via people with same domain (includes additional emails)
        $personWithDomain = Person::whereEmailDomain($domain)
            ->whereNotNull('client_id')
            ->first();

        if ($personWithDomain) {
            $result['client_id'] = $personWithDomain->client_id;

            return $result;
        }

        return $result;
    }

    /**
     * Convert markdown body to email-safe HTML and append email signature.
     *
     * Signature priority: user's personal signature → global signature → none.
     */
    private function buildHtmlBody(string $text, ?int $userId = null): string
    {
        $style = 'font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; font-size: 14px; line-height: 1.6; color: #374151;';
        $html = '<div style="'.$style.'">'.MarkdownRenderer::render($text).'</div>';

        // User signature takes priority, fall back to global
        $signature = null;
        if ($userId) {
            $signature = User::where('id', $userId)->value('email_signature');
        }
        $signature = $signature ?: Setting::getValue('email_signature');

        if ($signature) {
            $sigStyle = $style.' color: #6b7280; font-size: 13px;';
            $html .= '<br><div style="'.$sigStyle.'">'.MarkdownRenderer::render($signature).'</div>';
        }

        return $html;
    }

    /**
     * Map a Graph API message response to Email model attributes.
     */
    private function mapGraphMessage(array $msg): array
    {
        $from = $msg['from']['emailAddress'] ?? [];
        $headers = $msg['internetMessageHeaders'] ?? [];

        $inReplyTo = null;
        foreach ($headers as $header) {
            if (strcasecmp($header['name'] ?? '', 'In-Reply-To') === 0) {
                $inReplyTo = $header['value'] ?? null;
                break;
            }
        }

        return [
            'internet_message_id' => $msg['internetMessageId'] ?? null,
            'conversation_id' => $msg['conversationId'] ?? null,
            'in_reply_to' => $inReplyTo,
            'direction' => 'inbound',
            'from_address' => strtolower($from['address'] ?? ''),
            'from_name' => $from['name'] ?? null,
            'to_recipients' => $this->mapRecipients($msg['toRecipients'] ?? []),
            'cc_recipients' => $this->mapRecipients($msg['ccRecipients'] ?? []),
            'subject' => $msg['subject'] ?? '(no subject)',
            'body_preview' => mb_substr($msg['bodyPreview'] ?? '', 0, 500),
            'body_text' => $this->extractPlainText($msg['body']['content'] ?? null),
            'body_html' => $msg['body']['content'] ?? null,
            'has_attachments' => $msg['hasAttachments'] ?? false,
            'importance' => strtolower($msg['importance'] ?? 'normal'),
            'received_at' => $msg['receivedDateTime'] ?? now(),
        ];
    }

    /**
     * Extract readable plain text from HTML email body.
     */
    public function extractPlainText(?string $html): ?string
    {
        if (! $html) {
            return null;
        }

        try {
            return Html2Text::convert($html, ['ignore_errors' => true]);
        } catch (\Throwable $e) {
            Log::warning('[EmailService] Failed to extract plain text from email', [
                'error' => $e->getMessage(),
            ]);

            return strip_tags($html);
        }
    }

    /**
     * Map Graph recipient arrays to [{name, address}, ...] format.
     */
    private function mapRecipients(array $recipients): ?array
    {
        if (empty($recipients)) {
            return null;
        }

        return array_map(fn ($r) => [
            'name' => $r['emailAddress']['name'] ?? null,
            'address' => strtolower($r['emailAddress']['address'] ?? ''),
        ], $recipients);
    }
}
