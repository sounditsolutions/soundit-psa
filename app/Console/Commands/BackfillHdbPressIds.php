<?php

namespace App\Console\Commands;

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
 * ONLY THE PSA'S OWN NOTES ARE KEYED (GitHub #1359). The note key is the
 * authority the report-fetch gate reads — column presence is what it treats as
 * proof of capture — so this command must not mint one for a note a human
 * wrote. A technician pasting another client's report link, or an end user
 * quoting one in a portal reply, would otherwise become authority for that
 * press on their own ticket the next time this re-runnable command is run, and
 * the fetch would be scoped to their client: the exact cross-client vector
 * #1359 exists to refuse. The scan is therefore restricted to notes authored by
 * the configured T2T system user — the identity every inbound CW-compat note is
 * written as (T2TController hands it to addNoteFromCw) — and the command
 * REFUSES to run when that identity is not configured; see captureAuthorId().
 * The 105-note population measured above is the notes CARRYING a link; what
 * this keys is the PSA-authored subset of it.
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

        // Live notes only. A press id on a soft-deleted note is scoped to that
        // note and must not reach the ticket around it (GitHub #1313) — under
        // the note model that path dissolves rather than needs a guard, and
        // prod's base rate for it is 0 (no soft-deleted note carries a link).
        //
        // PSA-authored notes only. What this scan will NOT key matters as much
        // as what it will: a human's note is never keyed, whatever its body
        // says, because the key is an authority and a paste is not a capture.
        $query = TicketNote::query()
            ->whereNull('hdb_press_id')
            ->where('author_id', $captureAuthorId)
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
}
