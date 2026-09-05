<?php

namespace App\Support;

/**
 * Extracts the HelpDesk Buttons "press id" from ticket note text.
 *
 * HDB does not push the diagnostic report into the ticket — it pushes a private
 * note carrying two links, and the pressID query parameter on those links is the
 * single key for every later report fetch:
 *
 *   https://beta.helpdeskbuttons.com/pressView.php?pressID=<UUID>
 *   https://beta.helpdeskbuttons.com/connect?pressID=<UUID>
 *
 * MEASURED ON PROD 2026-09-04 (counts only, 50 most recent helpdesk_button
 * tickets, 675 notes): 51 notes contain a UUID, but only 48 tickets carry an HDB
 * link note. THREE of the 51 UUID hits are NOT press ids — an account-enabled
 * state note, a technician note mentioning the remote link in prose, and a
 * Tactical `list_devices` session uuid. So a "first UUID in the ticket's notes"
 * parser mis-keys roughly 6% of button tickets and would fetch a report for a
 * uuid that is not a press. That is why this anchors on the `pressID=` query
 * parameter of a helpdeskbuttons.com URL and never on a bare UUID.
 */
class HdbPressId
{
    /**
     * Anchored on the URL, not the UUID.
     *
     * Host must be helpdeskbuttons.com or a subdomain of it: the alternation is
     * followed by an explicit port-or-slash so `helpdeskbuttons.com.evil.test`
     * cannot match. `(?:amp;)?` tolerates HTML-escaped `&amp;` in note bodies.
     */
    private const PRESS_ID_PATTERN = '~https?://(?:[a-z0-9-]+\.)*helpdeskbuttons\.com(?::\d+)?/[^\s"\'<>]*[?&](?:amp;)?pressID=([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})~i';

    /**
     * Every distinct press id in one note body, lowercased, in order of appearance.
     *
     * @return list<string>
     */
    public static function allInBody(?string $body): array
    {
        if ($body === null || $body === '') {
            return [];
        }

        if (! preg_match_all(self::PRESS_ID_PATTERN, $body, $matches)) {
            return [];
        }

        return array_values(array_unique(array_map('strtolower', $matches[1])));
    }

    /**
     * The single press id in one note body, or null.
     *
     * Returns null when the body carries two DIFFERENT press ids. That is the
     * only ambiguity left under the note model, and it is a genuine one: a note
     * that disagrees with itself names no single endpoint. Absence and
     * self-disagreement are both simply "this note is not a press" — neither is
     * an error and neither is retried.
     *
     * There is deliberately no ticket-wide resolve(): two presses on one ticket
     * is a normal state (a merge brings the notes with it), not a conflict to
     * arbitrate. The key lives on the note.
     */
    public static function fromBody(?string $body): ?string
    {
        $found = self::allInBody($body);

        return count($found) === 1 ? $found[0] : null;
    }
}
