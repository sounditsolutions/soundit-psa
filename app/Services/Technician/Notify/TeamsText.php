<?php

namespace App\Services\Technician\Notify;

/**
 * Defangs UNTRUSTED dynamic fields before they are interpolated into a Teams
 * MessageCard (psa-uvuy hardening).
 *
 * The Teams card sink (TeamsNotifier::post) renders MARKDOWN, and operator-facing
 * alert/digest bodies legitimately use markdown formatting. But those bodies embed
 * attacker-controlled client data — the ticket SUBJECT and the client/contact NAME.
 * Left raw, a subject like `[click](http://evil)`, `<b>`, or `*spoof*` would inject
 * a live link, raw HTML, or emphasis into the operator's chat. The email path is
 * already HtmlSanitizer-sanitized; the Teams sink is the only raw one.
 *
 * This removes the link/emphasis/code/HTML control characters outright (they carry
 * no legitimate meaning in a ticket subject or a client name) — the parser can no
 * longer see a `](`, `<…>`, `*…*`, `_…_`, or `` `…` `` construct, and the words stay
 * legible to the operator. It is applied AT each interpolation point for the
 * untrusted fields, NOT across the whole body (blanket-escaping would break the
 * operators' own intended markdown formatting).
 */
class TeamsText
{
    /**
     * Markdown / HTML control characters an untrusted field must not smuggle into
     * the Teams card: angle brackets (HTML), link brackets/parens, and the
     * emphasis/code markers. Anything that could forge a link, tag, or emphasis.
     */
    private const CONTROL = ['<', '>', '[', ']', '(', ')', '*', '_', '`'];

    /**
     * Neutralise an untrusted string for safe interpolation into a Teams card.
     * Markdown/HTML control characters are dropped (replaced with a space) and
     * runs of whitespace are collapsed so the result reads cleanly. Plain text is
     * returned unchanged.
     */
    public static function escape(?string $value): string
    {
        $value = str_replace(self::CONTROL, ' ', (string) $value);

        // Collapse the whitespace the stripping may have introduced, and trim.
        return trim((string) preg_replace('/\s{2,}/', ' ', $value));
    }
}
