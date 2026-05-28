<?php

namespace App\Services\Mesh;

use App\Models\Email;
use Illuminate\Support\Facades\Log;

/**
 * Detects and parses Mesh email delivery request notifications.
 *
 * When an end user clicks "request delivery" in their Mesh quarantine digest,
 * Mesh sends an email to the PSA inbox from noreply@emailsecurity.app with
 * a structured body. This parser extracts the actionable fields.
 *
 * Expected email format (plain text after Html2Text conversion):
 *
 *   [Thumb](https://www.meshsecurity.io)
 *
 *   Hi Administrator,
 *   A user has requested the delivery of the following email:
 *
 *   Sender: stephen.roach@chase.com
 *
 *   Recipient: tracey@dogwoodmanagementllc.com
 *
 *   Subject: RE: [EXTERNAL]RE: Dayton Place
 *
 *   Date: 2026-03-03T19:09:51.527Z
 *
 *   Queue ID: a49d3a83-de49-4a27-aef2-50f94a1acd6e
 *
 *   URL: https://hub-us.emailsecurity.app/app/partner/live_email_tracker?queue_id=...
 *
 *   Category: Banned
 *
 *   To deliver the email please login to the portal...
 *
 * If Mesh changes this format, update the regexes below and this comment block.
 */
class MeshEmailParser
{
    /**
     * Check if an email is a Mesh delivery request notification.
     */
    public static function isMeshDeliveryRequest(Email $email): bool
    {
        $from = strtolower($email->from_address ?? '');
        $subject = strtolower($email->subject ?? '');

        return str_ends_with($from, '@emailsecurity.app')
            && str_starts_with($subject, 'email delivery request:');
    }

    /**
     * Parse structured fields from a Mesh delivery request email body.
     *
     * Returns null if the email is not a delivery request.
     * Returns an array with extracted fields (some may be null if parsing fails).
     * All string values are HTML-escaped for safe use in ticket descriptions.
     *
     * @return array{recipient: ?string, sender: ?string, original_subject: ?string, queue_id: ?string, portal_url: ?string, category: ?string, date: ?string}|null
     */
    public static function parse(Email $email): ?array
    {
        if (! self::isMeshDeliveryRequest($email)) {
            return null;
        }

        $body = $email->body_text ?? '';

        $fields = [
            'recipient' => self::extractField($body, 'Recipient'),
            'sender' => self::extractField($body, 'Sender'),
            'original_subject' => self::extractField($body, 'Subject'),
            'queue_id' => self::extractField($body, 'Queue ID'),
            'portal_url' => self::extractPortalUrl($body),
            'category' => self::extractField($body, 'Category'),
            'date' => self::extractField($body, 'Date'),
        ];

        // Fallback: extract recipient from email subject if body parsing failed
        if (! $fields['recipient']) {
            $fields['recipient'] = self::extractRecipientFromSubject($email->subject);
        }

        // Log a warning if we detected the email but couldn't extract key fields
        if (! $fields['recipient'] && ! $fields['queue_id']) {
            Log::warning('[MeshEmailParser] Failed to extract fields from delivery request email', [
                'email_id' => $email->id,
                'subject' => $email->subject,
            ]);
        }

        // HTML-escape all string values for safe use in ticket descriptions
        return array_map(
            fn ($v) => $v !== null ? e($v) : null,
            $fields,
        );
    }

    /**
     * Extract a labeled field value from the email body.
     * Matches patterns like "Sender: value@example.com" on its own line.
     */
    private static function extractField(string $body, string $label): ?string
    {
        // Match "Label: value" where value extends to end of line
        if (preg_match('/^' . preg_quote($label, '/') . ':\s*(.+)$/mi', $body, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    /**
     * Extract the Mesh portal URL, validating it's on the emailsecurity.app domain.
     */
    private static function extractPortalUrl(string $body): ?string
    {
        if (preg_match('/^URL:\s*(https:\/\/[^\s]+emailsecurity\.app[^\s]*)$/mi', $body, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    /**
     * Extract recipient email from subject line: "Email Delivery Request: user@domain.com"
     */
    private static function extractRecipientFromSubject(?string $subject): ?string
    {
        if (! $subject) {
            return null;
        }

        if (preg_match('/Email Delivery Request:\s*(\S+@\S+)/i', $subject, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    /**
     * Build a formatted ticket description from parsed Mesh fields.
     *
     * @param  array  $parsed  Output from self::parse() (already HTML-escaped)
     */
    public static function buildDescription(array $parsed): string
    {
        $lines = ['**Mesh Email Delivery Request**'];

        if ($parsed['recipient']) {
            $lines[] = '- Requested by: ' . $parsed['recipient'];
        }
        if ($parsed['sender']) {
            $lines[] = '- Quarantined email from: ' . $parsed['sender'];
        }
        if ($parsed['original_subject']) {
            $lines[] = '- Original subject: ' . $parsed['original_subject'];
        }
        if ($parsed['category']) {
            $lines[] = '- Category: ' . $parsed['category'];
        }
        if ($parsed['queue_id']) {
            $lines[] = '- Queue ID: ' . $parsed['queue_id'];
        }
        if ($parsed['date']) {
            $lines[] = '- Date: ' . $parsed['date'];
        }
        if ($parsed['portal_url']) {
            $lines[] = '- [View in Mesh Portal](' . $parsed['portal_url'] . ')';
        }

        return implode("\n", $lines);
    }
}
