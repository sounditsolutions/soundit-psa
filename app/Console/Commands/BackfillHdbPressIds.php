<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Support\HdbPressId;
use App\Support\T2TConfig;
use Illuminate\Console\Command;

/**
 * Backfill hdb_press_id for the HDB notes already in prod, and the ticket-level
 * cache derived from them.
 *
 * Aimed at NOTES, not tickets, for two measured reasons (prod, 2026-09-05,
 * counts only):
 *  - 105 live notes carry an HDB pressID link across 95 distinct tickets, but
 *    only 45 of those tickets are source=helpdesk_button. A ticket-scoped pass
 *    filtered on that source saw barely half the population: a merge brings the
 *    press notes with it and the source does not follow.
 *  - Keying the note removes the refusals entirely. The ticket-scoped pass
 *    refused 7 of 45 tickets for "conflicting" press ids; under the note model
 *    those are simply tickets holding two presses, and both are kept.
 *
 * WHAT THIS COMMAND MAY KEY IS BOUNDED TWICE (GitHub #1359). The note key is
 * the authority the report-fetch gate reads — column presence is what it treats
 * as proof of capture — so a note a human pasted a report link into must never
 * acquire one: it would become authority for that press on that human's own
 * ticket, and the fetch would be scoped to their client, the exact cross-client
 * vector #1359 exists to refuse.
 *
 *  - AUTHORSHIP. Only notes authored by the configured T2T system user are
 *    scanned — the identity every inbound CW-compat note is written as
 *    (T2TController hands it to addNoteFromCw) — and the command REFUSES to run
 *    when that identity is not configured rather than guessing one; see
 *    captureAuthorId().
 *  - A WINDOW FROZEN AT CUTOVER. Authorship alone is not proof of capture and
 *    cannot be: t2t_system_user_id is a select over ordinary staff logins, and
 *    in the deployments whose legacy notes this command exists to key it names
 *    a real person's account — in a single-technician MSP, the very account
 *    that technician works under. So the scan is ALSO capped at a mark this
 *    command does not set: the release migration
 *    (2026_09_11_000001_freeze_hdb_press_id_backfill_window) records the
 *    highest note id in existence AT MIGRATE TIME, and this command reads that
 *    mark or refuses to run; see recordedNoteCeiling(). A note written from the
 *    release onwards is out of scope permanently, whoever authored it, so a
 *    link pasted after cutover cannot be promoted to authority by any run —
 *    first, dry, or later.
 *
 * WHERE the mark is taken is the whole bound. Taken here, on first invocation,
 * it would be whatever the operator's own scheduling chose: this is a manual
 * artisan command with no deploy hook, so every link pasted in the gap between
 * deploy and first run would still be inside the window and keyed.
 *
 * WHAT THAT STILL DOES NOT COVER, stated because the fetch gate's contract
 * depends on it: a report link the capture identity's account pasted BEFORE the
 * release is inside the window and carries the accepted author_id, so it can be
 * keyed. That population is finite, already written, and named by the operator's
 * own choice of capture identity — --dry-run reports its size before anything is
 * written — but it is bounded by authorship alone, not by proof of capture.
 *
 * The 105-note population measured above is the notes CARRYING a link; what
 * this keys is the subset of it inside both bounds.
 *
 * Every write is a keyed BASE-query update: no $fillable path, no model events,
 * and no updated_at churn on either table (GitHub #1317 — the objection that
 * made the ticket-scoped backfill wait for a human word). The two are the same
 * commit now rather than two.
 *
 * ->toBase() is what actually suppresses the timestamp. Eloquent's own
 * Builder::update() calls addUpdatedAtColumn() (vendor Builder.php:1266), so the
 * bare Model::whereKey()->update() would bump updated_at on all ~105 rows and
 * reproduce the churn this is meant to retire.
 */
class BackfillHdbPressIds extends Command
{
    /**
     * Where this command's window was frozen: the highest ticket_notes id in
     * existence when the release migration ran. Written there, never here.
     * See recordedNoteCeiling().
     */
    private const NOTE_CEILING_SETTING = 'hdb_press_id_backfill_note_ceiling';

    protected $signature = 'hdb:backfill-press-ids
                            {--limit= : Cap the number of notes to process}
                            {--dry-run : Report what would be written without saving}';

    protected $description = 'Parse the HelpDesk Buttons press id out of existing ticket notes and key the notes with it';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $captureAuthorId = $this->captureAuthorId();

        if ($captureAuthorId === null) {
            $this->error('No T2T system user is configured, so no authorship proves a note was written by the PSA rather than pasted by a technician. Set it in Settings > Integrations > Tier2Tickets and re-run.');

            return self::FAILURE;
        }

        $noteCeiling = $this->recordedNoteCeiling();

        if ($noteCeiling === null) {
            $this->error('No backfill window is recorded, so this command cannot tell a note that predates the release from one written since. The release migration records the mark at cutover: run `php artisan migrate` and re-run.');

            return self::FAILURE;
        }

        // Live notes only. A press id on a soft-deleted note is scoped to that
        // note and must not reach the ticket around it (GitHub #1313) — under
        // the note model that path dissolves rather than needs a guard, and
        // prod's base rate for it is 0 (no soft-deleted note carries a link).
        //
        // What this scan will NOT key matters as much as what it will: nothing
        // authored by anyone but the capture identity, and nothing written
        // after the release froze the window — because the key is an authority
        // and a paste is not a capture, whoever's login it was pasted under.
        $query = TicketNote::query()
            ->whereNull('hdb_press_id')
            ->where('author_id', $captureAuthorId)
            ->where('id', '<=', $noteCeiling)
            ->where('body', 'like', '%pressID=%')
            ->orderBy('id');

        if ($limit = $this->option('limit')) {
            $query->limit((int) $limit);
        }

        $notes = $query->get(['id', 'ticket_id', 'body']);

        if ($notes->isEmpty()) {
            $this->info('No PSA-authored notes are missing a press id.');

            return self::SUCCESS;
        }

        $set = 0;
        $skipped = 0;
        // Ticket ids touched by this run. The cached VALUE is deliberately not
        // derived here: this scan sees only notes that were still unkeyed, so it
        // cannot know whether the ticket already holds a newer, capture-keyed
        // press. The value is resolved below from all of the ticket's keyed notes.
        $ticketCache = [];

        foreach ($notes as $note) {
            $pressId = HdbPressId::fromBody($note->body);

            if ($pressId === null) {
                // The LIKE is a coarse prefilter; the parser is the contract. A
                // body mentioning pressID= outside a helpdeskbuttons.com URL, or
                // naming two different presses, is not a press note.
                $skipped++;

                continue;
            }

            $set++;
            $ticketCache[$note->ticket_id] = $pressId;

            if (! $dryRun) {
                TicketNote::whereKey($note->id)->toBase()->update(['hdb_press_id' => $pressId]);
            }
        }

        if (! $dryRun) {
            // Re-derive each touched ticket's cache from ALL of its live keyed
            // notes, newest id wins. A ticket can already carry a NEWER press
            // stamped by captureHdbPressId(), and that note is invisible to the
            // whereNull scan above — writing this run's value unconditionally
            // would demote the cache to the older legacy press, and the
            // now-keyed note would never be revisited by a re-run.
            foreach (array_keys($ticketCache) as $ticketId) {
                $newest = TicketNote::query()
                    ->where('ticket_id', $ticketId)
                    ->whereNotNull('hdb_press_id')
                    ->orderByDesc('id')
                    ->value('hdb_press_id');

                if ($newest !== null) {
                    Ticket::whereKey($ticketId)->toBase()->update(['hdb_press_id' => $newest]);
                }
            }
        }

        $this->info(sprintf(
            '%s %d note(s) of %d PSA-authored note(s) scanned, across %d ticket(s); %d carried no parseable press id.',
            $dryRun ? 'Would key' : 'Keyed',
            $set,
            $notes->count(),
            count($ticketCache),
            $skipped,
        ));

        return self::SUCCESS;
    }

    /**
     * The one authorship this command may key: the configured T2T system user,
     * the author every inbound CW-compat note is written as (T2TController
     * passes T2TConfig::systemUserId() to addNoteFromCw).
     *
     * Read from the setting DIRECTLY rather than through
     * T2TConfig::systemUserId(), because that helper falls back to the FIRST
     * user — a real technician's account in any deployment that never
     * configured one. Keying an authority against a guessed identity is the
     * same defect as keying it against none, so an unset setting (or one the
     * integrations form cleared, which stores '') yields null and the command
     * refuses to run rather than guessing.
     */
    private function captureAuthorId(): ?int
    {
        $configured = T2TConfig::get('system_user_id');

        return ($configured === null || $configured === '') ? null : (int) $configured;
    }

    /**
     * The highest note id this command may ever touch: the high-water mark of
     * ticket_notes at the moment the release carrying this command was
     * migrated. Recorded THERE
     * (2026_09_11_000001_freeze_hdb_press_id_backfill_window), read-only here,
     * and null when it was never recorded.
     *
     * Authorship is not proof of capture (GitHub #1359). t2t_system_user_id is
     * chosen from the ordinary user list, the capture path's own fallback is
     * the FIRST user, and the legacy prod notes were written under that
     * fallback — so the one value of the setting that lets this command key
     * them is a real person's login, under which a hand-pasted link carries
     * exactly the author_id captureAuthorId() accepts.
     *
     * The window is what bounds that, and WHERE the mark is taken decides how
     * much it bounds. Taken on first invocation it would be a boundary the
     * operator's scheduling chooses: this is a manual artisan command with no
     * deploy hook, so every link pasted between deploy and that first run — a
     * gap of any length — would sit inside the window and be keyed. Taken at
     * migrate time it is the cutover itself: prod's legacy notes are all below
     * it, and no note written from the release onwards can ever be, however
     * often this command runs and whoever authored it. --limit batching is
     * unaffected: every run shares the one recorded window.
     *
     * A --dry-run reads the same recorded mark and establishes nothing, so a
     * dry run can no longer widen the window by the notes written before it.
     *
     * Null (never recorded, or cleared) refuses the run rather than minting a
     * mark. Minting one here is precisely the defect above, and a command that
     * silently re-freezes its own window is worse than one that will not start.
     */
    private function recordedNoteCeiling(): ?int
    {
        $recorded = Setting::getValue(self::NOTE_CEILING_SETTING);

        return ($recorded === null || $recorded === '') ? null : (int) $recorded;
    }
}
