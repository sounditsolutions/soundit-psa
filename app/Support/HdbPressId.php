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

    public const STATUS_FOUND = 'found';

    public const STATUS_ABSENT = 'absent';

    public const STATUS_CONFLICT = 'conflict';

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
     * Returns null when the body carries two DIFFERENT press ids — one note that
     * disagrees with itself is a refusal for the same reason a ticket that
     * disagrees with itself is.
     */
    public static function fromBody(?string $body): ?string
    {
        $found = self::allInBody($body);

        return count($found) === 1 ? $found[0] : null;
    }

    /**
     * Resolve one press id for a whole ticket from its note bodies.
     *
     * Contract, as specified on the card and accepted by Jeeves 2026-09-04:
     *  - anchor on the `pressID=` parameter of a helpdeskbuttons.com URL;
     *  - take the note with the LOWEST id when several match (caller supplies the
     *    bodies already ordered by note id ascending);
     *  - treat two DIFFERENT press ids on one ticket as a REFUSAL, not a pick;
     *  - absence is normal — it is not an error and must not be retried.
     *
     * @param  iterable<string|null>  $bodies  note bodies, ordered by note id ascending
     * @return array{status: string, press_id: string|null, candidates: list<string>}
     */
    public static function resolve(iterable $bodies): array
    {
        $candidates = [];

        foreach ($bodies as $body) {
            foreach (self::allInBody($body) as $pressId) {
                if (! in_array($pressId, $candidates, true)) {
                    $candidates[] = $pressId;
                }
            }
        }

        if ($candidates === []) {
            return ['status' => self::STATUS_ABSENT, 'press_id' => null, 'candidates' => []];
        }

        if (count($candidates) > 1) {
            // Deliberately no pick. Two presses on one ticket means either the
            // shim merged two tickets or a human pasted someone else's link;
            // guessing wrong fetches another endpoint's diagnostics onto it.
            return ['status' => self::STATUS_CONFLICT, 'press_id' => null, 'candidates' => $candidates];
        }

        return ['status' => self::STATUS_FOUND, 'press_id' => $candidates[0], 'candidates' => $candidates];
    }
}
