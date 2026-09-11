<?php

namespace App\Services\Hdb;

/**
 * The closed vocabulary of reasons a HelpDesk Buttons report fetch is refused.
 *
 * Deliberately a fixed enum rather than a message string. The refusal is
 * rendered to a technician and written to the audit trail, and neither surface
 * may carry vendor-controlled or user-controlled bytes: a press id arrives in a
 * note body written by HDB, and in the pasted-link path it arrives from the
 * user. A symbol cannot be a write primitive on an admin's DOM and cannot leak
 * the contents of another client's record.
 *
 * The same closure is what makes CROSS-CLIENT refusals safe: a press id keyed
 * on another client's ticket and a press id that exists nowhere return the SAME
 * case (NoKeyedNote) with the same label. The refusal must not become an oracle
 * for "this press exists, but not for you".
 */
enum HdbReportFetchRefusal: string
{
    /** The value offered is not a press id at all (not a UUID). */
    case MalformedPressId = 'malformed_press_id';

    /** No live ticket with that id — deleted, or never existed. */
    case TicketMissing = 'ticket_missing';

    /** The ticket carries no client, so a fetch cannot be scoped to one. */
    case ClientMissing = 'client_missing';

    /**
     * No LIVE note on this ticket was keyed with this press id by the PSA.
     *
     * This is the single refusal that #1359 exists for, and it covers four
     * distinct situations on purpose: a pasted link nobody captured, a press
     * keyed on another ticket, a press keyed on another CLIENT's ticket, and a
     * press whose keyed note has since been soft-deleted (#1360).
     */
    case NoKeyedNote = 'no_keyed_note';

    /** Short human-readable text. Fixed strings; nothing is interpolated. */
    public function label(): string
    {
        return match ($this) {
            self::MalformedPressId => 'That is not a HelpDesk Buttons press id.',
            self::TicketMissing => 'That ticket no longer exists.',
            self::ClientMissing => 'That ticket is not assigned to a client.',
            self::NoKeyedNote => 'No report on this ticket is linked to that press id.',
        };
    }
}
