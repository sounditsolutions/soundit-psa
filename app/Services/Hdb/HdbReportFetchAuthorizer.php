<?php

namespace App\Services\Hdb;

use App\Models\Ticket;
use App\Models\TicketNote;

/**
 * Decides whether a HelpDesk Buttons diagnostic report may be fetched.
 *
 * GitHub #1359: neither an arbitrary pasted press link nor the ticket-level
 * cache may authorize a cross-client report fetch. Both halves of that are
 * enforced here, and both are enforced by the SAME rule:
 *
 *   A fetch is authorized only when the press key was captured by the PSA
 *   itself onto a LIVE note that belongs to the ticket being viewed, and the
 *   fetch is then scoped to that ticket's client.
 *
 * Two consequences worth stating outright, because each is a refusal the code
 * has to make rather than a property it inherits:
 *
 * 1. **A pasted key is a locator, never a credential.** A press id that arrived
 *    as text a user typed proves nothing about who may read the report behind
 *    it. It is authorized only if the PSA had already keyed a note on this
 *    ticket with exactly that id — i.e. the paste can never widen access beyond
 *    what the capture path already established.
 *
 * 2. **`tickets.hdb_press_id` is NOT an authority and is never read here.**
 *    Slice 1a redefined it as a never-cleared CACHE of the newest press on the
 *    ticket, written from the note write. Because the conflict refusal was
 *    deleted with it, that column is last-note-wins: a later note can silently
 *    repoint a ticket's cache at another endpoint's diagnostics. Reading it to
 *    authorize would hand that repoint straight to the fetch. #1358 is the same
 *    hole from the other side — the cache is never maintained across a merge.
 *    The authority is the NOTE row; the cache is for display and nothing else.
 *
 * Soft-deleted notes do not authorize (#1360). The default SoftDeletes scope is
 * load-bearing, not incidental: a note a technician removed has withdrawn the
 * link it carried, and the ticket cache may still point at it.
 *
 * SCOPE. This class answers "is this press id bound to this ticket, and whose
 * data is it?" It is NOT a substitute for the ticket policy — whether the
 * ACTING USER may see the ticket at all remains the caller's authorization, and
 * this class deliberately does not consult the authenticated user, so that a
 * caller cannot mistake an allow for a permission check it never performed.
 *
 * Parsing is also not this class's job: a pasted LINK is turned into a press id
 * by {@see \App\Support\HdbPressId::fromBody()} — the same parser the capture
 * path uses, anchored on a helpdeskbuttons.com URL rather than on a bare UUID —
 * and only the id reaches `authorize()`.
 *
 * No fetching happens here and no credential is touched. The report client and
 * its screenshot handling are the next commit in this slice; this one is the
 * gate they will be required to pass first.
 */
class HdbReportFetchAuthorizer
{
    /**
     * Canonical press-id shape. Capture lowercases before it writes
     * (HdbPressId::allInBody), so comparison is against a lowercased value.
     */
    private const PRESS_ID_PATTERN = '~^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$~';

    /**
     * Authorize a fetch of $pressId in the context of the ticket being viewed.
     *
     * Fails closed on every unknown: a value that cannot be understood is a
     * refusal, never a pass.
     */
    public function authorize(?int $ticketId, ?string $pressId): HdbReportFetchAuthorization
    {
        $pressId = $this->normalize($pressId);

        if ($pressId === null) {
            return HdbReportFetchAuthorization::refuse(HdbReportFetchRefusal::MalformedPressId);
        }

        if ($ticketId === null) {
            return HdbReportFetchAuthorization::refuse(HdbReportFetchRefusal::TicketMissing);
        }

        $ticket = Ticket::query()->whereKey($ticketId)->first();

        if ($ticket === null) {
            return HdbReportFetchAuthorization::refuse(HdbReportFetchRefusal::TicketMissing);
        }

        if ($ticket->client_id === null) {
            return HdbReportFetchAuthorization::refuse(HdbReportFetchRefusal::ClientMissing);
        }

        // The authority. Scoped to this ticket, live notes only, and keyed on
        // the note's own column — never on $ticket->hdb_press_id.
        //
        // orderBy('id') rather than latest(): one press id may legitimately
        // appear on two notes of the same ticket (the vendor re-posts, or a
        // merge brings a duplicate), and both name the SAME endpoint, so the
        // choice is arbitrary in effect but must be deterministic in the audit
        // row. First capture wins.
        $note = TicketNote::query()
            ->where('ticket_id', $ticket->id)
            ->where('hdb_press_id', $pressId)
            ->orderBy('id')
            ->first();

        if ($note === null) {
            return HdbReportFetchAuthorization::refuse(HdbReportFetchRefusal::NoKeyedNote);
        }

        return HdbReportFetchAuthorization::allow(
            $pressId,
            (int) $note->id,
            (int) $ticket->id,
            (int) $ticket->client_id,
        );
    }

    /**
     * Authorize the fetch offered against one note — the "report" control the
     * ticket view renders on a keyed note — in the context of the ticket being
     * viewed.
     *
     * The viewed ticket is a PARAMETER and is never derived from the offered
     * note. A note id is an opaque integer in the request, so resolving the
     * ticket from the note would make the ticket/press binding tautological:
     * any live keyed note id would self-authorize and hand back its OWN
     * client's scope, which is exactly the cross-client fetch #1359 exists to
     * refuse. The note is therefore looked up scoped to the viewed ticket, and
     * a note that is not on it refuses NoKeyedNote — the same case, with the
     * same label, as a note id that exists nowhere.
     *
     * Routed through authorize() once past that check: the ticket still has to
     * carry a client and the press id is still resolved to the ticket's first
     * capture, so there is one decision point and one set of refusals rather
     * than two that can drift.
     */
    public function authorizeNote(?int $ticketId, ?int $noteId): HdbReportFetchAuthorization
    {
        // Answered before the note is touched, so a missing viewing context
        // cannot become an existence oracle for note ids.
        if ($ticketId === null) {
            return HdbReportFetchAuthorization::refuse(HdbReportFetchRefusal::TicketMissing);
        }

        if ($noteId === null) {
            return HdbReportFetchAuthorization::refuse(HdbReportFetchRefusal::NoKeyedNote);
        }

        $note = TicketNote::query()
            ->whereKey($noteId)
            ->where('ticket_id', $ticketId)
            ->first();

        if ($note === null || $note->hdb_press_id === null) {
            return HdbReportFetchAuthorization::refuse(HdbReportFetchRefusal::NoKeyedNote);
        }

        return $this->authorize($ticketId, $note->hdb_press_id);
    }

    /** Trimmed and lowercased, or null when the value is not a press id. */
    private function normalize(?string $pressId): ?string
    {
        if ($pressId === null) {
            return null;
        }

        $candidate = strtolower(trim($pressId));

        return preg_match(self::PRESS_ID_PATTERN, $candidate) === 1 ? $candidate : null;
    }
}
