<?php

namespace App\Services\Triage;

use App\Models\Ticket;
use App\Services\Ai\AiClient;
use Illuminate\Support\Facades\Log;

/**
 * Deterministic junk ticket detection with optional AI confirmation.
 * Ported from HaloClaude triage/junk_detector.py.
 */
class JunkDetector
{
    // ── Monitoring tool domains: NEVER flag as junk ──

    private const MONITORING_ALLOWLIST = [
        'ninjarmm.com',
        'ninjarmm.zendesk.com',
        'sentinelone.net',
        'sentinelone.com',
        'zorus.com',
        'zorustech.com',
        'tacticalrmm.com',
        'archon.com',
        'todyl.com',
        'datto.com',
        'kaseya.com',
        'dattobackup.com',
        'microsoft.com',
        'microsoftonline.com',
        'emailsecurity.app',
        'huntress.io',
        'huntresslabs.com',
        'autoelevate.com',
        'servosity.com',
        'printix.net',
        'keeper.io',
        'keepersecurity.com',
        'powerdmarc.com',
        'scalepadsoftware.com',
        'screenconnect.com',
        'connectwise.com',
    ];

    // ── Auto-reply / Out-of-Office ──

    private const AUTO_REPLY_SUBJECT_PREFIXES = [
        'automatic reply:',
        'auto:',
        'autoreply:',
        'out of office:',
        'ooo:',
        'absence:',
        'away:',
        'auto-reply:',
    ];

    private const AUTO_REPLY_BODY_PHRASES = [
        'i am currently out of the office',
        "i'm currently out of the office",
        'i will be out of the office',
        "i'm out of the office",
        'i am away from',
        "i'm away from",
        'limited access to email',
        'i will respond when i return',
        'i will have limited access',
        'i am on vacation',
        "i'm on vacation",
        'i am on leave',
        'i am on holiday',
    ];

    // ── Bounce / NDR ──

    private const BOUNCE_SUBJECT_PREFIXES = [
        'undeliverable:',
        'delivery status notification',
        'mail delivery failed',
        'returned mail:',
        'failure notice',
        'undelivered mail',
        'mail delivery subsystem',
    ];

    private const BOUNCE_SENDER_PREFIXES = [
        'mailer-daemon@',
        'postmaster@',
        'bounced-',
    ];

    private const BOUNCE_BODY_PHRASES = [
        '550 ',
        '5.1.1',
        '5.2.1',
        '5.1.0',
        'recipient rejected',
        'user unknown',
        'mailbox unavailable',
        'mailbox not found',
        'address rejected',
        'could not be delivered',
        'delivery has failed',
        'not delivered',
        'message was undeliverable',
    ];

    // ── Spam / Marketing ──

    private const MARKETING_SENDER_DOMAINS = [
        'mailchimp.com',
        'sendgrid.net',
        'constantcontact.com',
        'hubspot.com',
        'campaign-archive.com',
        'mailgun.net',
        'aweber.com',
        'getresponse.com',
        'activecampaign.com',
        'drip.com',
        'convertkit.com',
        'mailerlite.com',
        'sendinblue.com',
        'brevo.com',
        'emma.com',
        'moosend.com',
        'benchmark.email',
    ];

    private const MARKETING_BODY_PHRASES = [
        'click here to unsubscribe',
        'manage your preferences',
        'email preferences',
        'you are receiving this email because',
        'you opted in',
        'marketing communication',
        'to stop receiving these emails',
        'to unsubscribe from this mailing list',
        'update your email preferences',
        'opt out of',
    ];

    // ── Automated Notifications ──

    private const NOTIFICATION_SENDER_PREFIXES = [
        'noreply@',
        'no-reply@',
        'donotreply@',
        'do-not-reply@',
        'notifications@',
        'alert@',
        'alerts@',
    ];

    private const NOTIFICATION_BODY_PHRASES = [
        'this is an automated message',
        'do not reply to this email',
        'this message was automatically generated',
        'this is a system-generated',
        'this email was sent automatically',
        'please do not reply to this message',
        'this is a notification only',
        'no action is required',
    ];

    // ── Security keywords: prevent auto-closure ──

    private const SECURITY_KEYWORDS = [
        'breach',
        'compromised',
        'malware',
        'ransomware',
        'virus detected',
        'threat detected',
        'suspicious activity',
        'unauthorized access',
        'security incident',
        'data breach',
    ];

    // ── Pre-checks ──

    /**
     * Should we skip junk detection entirely for this ticket?
     */
    public static function shouldSkip(Ticket $ticket): bool
    {
        // Portal tickets are from authenticated users — never junk
        if ($ticket->source === \App\Enums\TicketSource::Portal) {
            return true;
        }

        // Ninja alert tickets are machine-generated monitoring events — not junk
        if ($ticket->source === \App\Enums\TicketSource::NinjaAlert) {
            return true;
        }

        // Unified alert tickets are machine-generated monitoring events — not junk
        if ($ticket->source === \App\Enums\TicketSource::Alert) {
            return true;
        }

        // Already assigned — someone chose to work on it
        if ($ticket->assignee_id) {
            return true;
        }

        // Real conversation happening (3+ notes)
        if ($ticket->notes()->count() >= 3) {
            return true;
        }

        // Resolve sender email
        $senderEmail = self::resolveSenderEmail($ticket);

        // Sender is a monitoring tool
        if ($senderEmail && self::isMonitoringSender($senderEmail)) {
            return true;
        }

        // Content mentions active security incidents
        $combinedText = ($ticket->subject ?? '').' '.($ticket->description ?? '');
        if (self::hasSecurityContent($combinedText)) {
            return true;
        }

        return false;
    }

    // ── Classification ──

    /**
     * Classify a ticket as junk based on deterministic pattern matching.
     * Returns null if not junk.
     */
    public static function classify(string $subject, string $body, string $senderEmail): ?JunkResult
    {
        $subjectLower = strtolower(trim($subject));
        $senderLower = strtolower(trim($senderEmail));
        $bodyLower = strtolower(strip_tags($body));

        // Pattern 1: Auto-Reply / Out-of-Office
        $result = self::checkAutoReply($subjectLower, $bodyLower);
        if ($result) {
            return $result;
        }

        // Pattern 2: Bounce / NDR
        $result = self::checkBounce($subjectLower, $senderLower, $bodyLower);
        if ($result) {
            return $result;
        }

        // Pattern 3: Spam / Marketing
        $result = self::checkSpam($senderLower, $bodyLower);
        if ($result) {
            return $result;
        }

        // Pattern 4: Automated Notification
        $result = self::checkNotification($senderLower, $bodyLower);
        if ($result) {
            return $result;
        }

        return null;
    }

    // ── AI Confirmation ──

    /**
     * Use AI to confirm a medium-confidence junk detection.
     * Defaults to false (safe side) on failure.
     */
    public static function aiConfirm(AiClient $ai, string $subject, string $senderEmail, string $content): bool
    {
        $truncatedContent = mb_substr(strip_tags($content), 0, 3000);

        $prompt = sprintf(
            "%s\n\nTICKET SUMMARY: %s\nSENDER: %s\nTICKET CONTENT:\n%s",
            Prompts::JUNK_CONFIRMATION_PROMPT,
            $subject,
            $senderEmail,
            $truncatedContent,
        );

        $confirmed = $ai->confirmYesNo($prompt);

        Log::info('[Triage] AI junk confirmation', [
            'subject' => mb_substr($subject, 0, 80),
            'sender' => $senderEmail,
            'confirmed' => $confirmed,
        ]);

        return $confirmed;
    }

    // ── Pattern Checkers ──

    private static function checkAutoReply(string $subjectLower, string $bodyLower): ?JunkResult
    {
        // Subject starts with known auto-reply prefix
        foreach (self::AUTO_REPLY_SUBJECT_PREFIXES as $prefix) {
            if (str_starts_with($subjectLower, $prefix)) {
                return new JunkResult(
                    isJunk: true,
                    confidence: 'high',
                    reason: 'Subject indicates auto-reply: '.mb_substr($subjectLower, 0, 80),
                    pattern: 'auto_reply',
                );
            }
        }

        // Subject contains "out of office"
        if (preg_match('/\bout of (?:the )?office\b/', $subjectLower)) {
            return new JunkResult(
                isJunk: true,
                confidence: 'high',
                reason: "Subject contains 'out of office'",
                pattern: 'auto_reply',
            );
        }

        // Body contains OOO phrases
        $matches = self::countPhraseMatches($bodyLower, self::AUTO_REPLY_BODY_PHRASES);

        if ($matches >= 2) {
            return new JunkResult(
                isJunk: true,
                confidence: 'high',
                reason: 'Body contains multiple out-of-office phrases',
                pattern: 'auto_reply',
            );
        }

        if ($matches === 1) {
            return new JunkResult(
                isJunk: true,
                confidence: 'medium',
                reason: 'Body contains out-of-office phrase (single match)',
                pattern: 'auto_reply',
            );
        }

        return null;
    }

    private static function checkBounce(string $subjectLower, string $senderLower, string $bodyLower): ?JunkResult
    {
        // Subject starts with bounce prefix
        foreach (self::BOUNCE_SUBJECT_PREFIXES as $prefix) {
            if (str_starts_with($subjectLower, $prefix)) {
                return new JunkResult(
                    isJunk: true,
                    confidence: 'high',
                    reason: 'Subject indicates bounce/NDR: '.mb_substr($subjectLower, 0, 80),
                    pattern: 'bounce',
                );
            }
        }

        // Sender is a mailer-daemon or postmaster
        foreach (self::BOUNCE_SENDER_PREFIXES as $prefix) {
            if (str_starts_with($senderLower, $prefix)) {
                return new JunkResult(
                    isJunk: true,
                    confidence: 'high',
                    reason: "Sender is a mail system address: {$senderLower}",
                    pattern: 'bounce',
                );
            }
        }

        // Body contains SMTP error codes
        $matches = self::countPhraseMatches($bodyLower, self::BOUNCE_BODY_PHRASES);
        if ($matches >= 2) {
            return new JunkResult(
                isJunk: true,
                confidence: 'high',
                reason: 'Body contains multiple bounce/NDR indicators',
                pattern: 'bounce',
            );
        }

        return null;
    }

    private static function checkSpam(string $senderLower, string $bodyLower): ?JunkResult
    {
        $senderDomain = self::extractDomain($senderLower);
        $isMarketingSender = in_array($senderDomain, self::MARKETING_SENDER_DOMAINS, true);
        $marketingMatches = self::countPhraseMatches($bodyLower, self::MARKETING_BODY_PHRASES);

        // Marketing sender + any marketing body phrase = high
        if ($isMarketingSender && $marketingMatches >= 1) {
            return new JunkResult(
                isJunk: true,
                confidence: 'high',
                reason: "Marketing platform sender ({$senderDomain}) with unsubscribe content",
                pattern: 'spam',
            );
        }

        // Marketing sender alone = medium
        if ($isMarketingSender) {
            return new JunkResult(
                isJunk: true,
                confidence: 'medium',
                reason: "Sender is from marketing platform: {$senderDomain}",
                pattern: 'spam',
            );
        }

        // Multiple marketing phrases without marketing sender = medium
        if ($marketingMatches >= 2) {
            return new JunkResult(
                isJunk: true,
                confidence: 'medium',
                reason: 'Body contains multiple marketing/unsubscribe phrases',
                pattern: 'spam',
            );
        }

        return null;
    }

    private static function checkNotification(string $senderLower, string $bodyLower): ?JunkResult
    {
        // Must be from a no-reply sender
        $isNoReply = false;
        foreach (self::NOTIFICATION_SENDER_PREFIXES as $prefix) {
            if (str_starts_with($senderLower, $prefix)) {
                $isNoReply = true;
                break;
            }
        }

        if (! $isNoReply) {
            return null;
        }

        $matches = self::countPhraseMatches($bodyLower, self::NOTIFICATION_BODY_PHRASES);

        if ($matches >= 2) {
            return new JunkResult(
                isJunk: true,
                confidence: 'high',
                reason: 'No-reply sender with automated notification language',
                pattern: 'automated_notification',
            );
        }

        if ($matches === 1) {
            return new JunkResult(
                isJunk: true,
                confidence: 'medium',
                reason: 'No-reply sender with possible notification content',
                pattern: 'automated_notification',
            );
        }

        return null;
    }

    // ── Helpers ──

    /**
     * Resolve the sender email from a ticket (contact email, reported_by, or first note's email).
     */
    public static function resolveSenderEmail(Ticket $ticket): ?string
    {
        // Try contact email first
        if ($ticket->contact?->email) {
            return $ticket->contact->email;
        }

        // Try reported_by field
        if ($ticket->reported_by && str_contains($ticket->reported_by, '@')) {
            return $ticket->reported_by;
        }

        // Try first note's linked email
        $firstNote = $ticket->notes()->whereNotNull('email_id')->first();
        if ($firstNote?->email) {
            return $firstNote->email->from_address;
        }

        return null;
    }

    private static function isMonitoringSender(string $email): bool
    {
        $domain = self::extractDomain(strtolower($email));
        if (! $domain) {
            return false;
        }

        foreach (self::MONITORING_ALLOWLIST as $allowed) {
            if ($domain === $allowed || str_ends_with($domain, '.'.$allowed)) {
                return true;
            }
        }

        return false;
    }

    private static function hasSecurityContent(string $text): bool
    {
        $textLower = strtolower($text);

        foreach (self::SECURITY_KEYWORDS as $keyword) {
            if (str_contains($textLower, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private static function extractDomain(string $email): string
    {
        if (str_contains($email, '@')) {
            return strtolower(explode('@', $email, 2)[1]);
        }

        return '';
    }

    private static function countPhraseMatches(string $text, array $phrases): int
    {
        $count = 0;
        foreach ($phrases as $phrase) {
            if (str_contains($text, $phrase)) {
                $count++;
            }
        }

        return $count;
    }
}
