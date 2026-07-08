<?php

namespace App\Services\Email;

use App\Models\Email;

/**
 * Detects forwarded emails and extracts the original sender.
 *
 * When a technician forwards a customer's direct email into the helpdesk
 * mailbox (so it threads onto an existing ticket via a [T-123] subject token),
 * the forward's envelope sender is the technician, not the customer. This
 * parser recovers the original sender from the forwarded header block so the
 * ticket note can be attributed correctly.
 *
 * Best-effort, English-locale only (FW:/Fwd:/Forwarded: prefixes; Outlook and
 * Gmail header blocks). Anything it cannot parse yields null/false and callers
 * fall back to attributing the note to the forwarder.
 */
class ForwardedEmailParser
{
    /**
     * True when the email looks like a forward: a forward subject prefix AND a
     * recognizable forwarded header block in the body. The subject-prefix guard
     * is what keeps normal replies (whose quoted history also contains a
     * "From:/Sent:/Subject:" block) from being treated as forwards.
     */
    public static function isForwarded(Email $email): bool
    {
        $subject = $email->subject ?? '';
        if (! preg_match('/(^|\s)(fwd?|forwarded)\s*:/i', $subject)) {
            return false;
        }

        return self::hasForwardBlock(self::text($email));
    }

    /**
     * Extract the original sender from the topmost forwarded "From:" line.
     *
     * @return array{name: ?string, email: string}|null
     */
    public static function parseOriginalSender(Email $email): ?array
    {
        $text = self::text($email);

        // If a Gmail-style banner is present, search after it so a From: line
        // above the forwarded block can't be picked up by mistake.
        if (preg_match('/-{2,}\s*forwarded message\s*-{2,}/i', $text, $bm, PREG_OFFSET_CAPTURE)) {
            $text = substr($text, $bm[0][1]);
        }

        if (! preg_match('/^\s*from\s*:\s*(.+)$/im', $text, $m)) {
            return null;
        }

        $line = trim($m[1]);

        if (! preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $line, $em)) {
            return null;
        }

        $address = strtolower($em[0]);

        // Name is whatever precedes the address. Remove an angle-bracketed or
        // bare address, then de-quote; null it out if nothing meaningful remains.
        $name = preg_replace('/<[^>]*>/', '', $line);
        $name = trim(str_ireplace($address, '', $name));
        $name = trim($name, " \t\"'");
        if ($name === '' || strcasecmp($name, $address) === 0) {
            $name = null;
        }

        return ['name' => $name, 'email' => $address];
    }

    private static function hasForwardBlock(string $text): bool
    {
        // Gmail-style banner.
        if (preg_match('/-{2,}\s*forwarded message\s*-{2,}/i', $text)) {
            return true;
        }

        // Outlook-style header block: From: + (Sent:|Date:) + Subject:.
        return (bool) (preg_match('/^\s*from\s*:/im', $text)
            && preg_match('/^\s*(sent|date)\s*:/im', $text)
            && preg_match('/^\s*subject\s*:/im', $text));
    }

    private static function text(Email $email): string
    {
        $text = $email->body_text;
        if ($text === null || trim($text) === '') {
            $text = html_entity_decode(strip_tags((string) $email->body_html), ENT_QUOTES | ENT_HTML5);
        }

        return (string) $text;
    }
}
