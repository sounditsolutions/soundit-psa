<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Freeze the hdb:backfill-press-ids window at CUTOVER (GitHub #1359).
 *
 * `ticket_notes.hdb_press_id` is the authority the report-fetch gate reads, and
 * hdb:backfill-press-ids is its other writer. That command may only key notes
 * the PSA itself captured, but authorship cannot prove capture on its own:
 * `t2t_system_user_id` names an ordinary staff login, and in the deployments
 * whose legacy notes the command exists to key it is a real person's account —
 * in a single-technician MSP, the very account that technician works under. So
 * the command is also capped at a high-water mark of ticket_notes, and a note
 * above the mark is out of scope permanently.
 *
 * WHERE that mark is taken is the bound. The command used to take it itself, on
 * first invocation — which made the boundary whatever the operator's scheduling
 * chose. It is a manual artisan command with no deploy hook, so every report
 * link pasted between deploy and that first run sat inside the window and was
 * keyed, and a keyed paste is an authority: the gate would then allow a fetch
 * of that press scoped to the pasting technician's own client. That is the
 * cross-client vector #1359 exists to refuse, reached without any PSA capture.
 *
 * Taking the mark here makes it the cutover itself. Every note prod already
 * held is below it, so nothing the backfill exists to key moves; nothing
 * written from this release onwards is ever inside it, whoever authored it and
 * however often the command runs.
 *
 * This does NOT cover a link the capture identity's account pasted BEFORE the
 * release — that note is in the legacy population and carries the accepted
 * author_id. That residue is finite, already written, and inspectable with
 * `hdb:backfill-press-ids --dry-run` before anything is written; it is stated in
 * HdbReportFetchAuthorizer's contract rather than claimed away.
 *
 * Writes one settings row. No note row and no ticket row is touched, so there
 * is no updated_at churn (GitHub #1317).
 */
return new class extends Migration
{
    private const SETTING_KEY = 'hdb_press_id_backfill_note_ceiling';

    public function up(): void
    {
        // Never re-take the mark. A second mark taken later is a wider window,
        // which is the whole defect this migration exists to close.
        if (DB::table('settings')->where('key', self::SETTING_KEY)->exists()) {
            return;
        }

        // Raw query builder, so soft-deleted notes are counted: a trashed note
        // still holds an id, and a mark taken through the default scope could
        // be overtaken by rows that were already there.
        $ceiling = (int) (DB::table('ticket_notes')->max('id') ?? 0);

        DB::table('settings')->insert([
            'key' => self::SETTING_KEY,
            'value' => (string) $ceiling,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Deliberately a no-op. Deleting the mark would let a re-migrate take a
        // NEW one — a later, wider window over notes written in the meantime,
        // which is exactly what this migration exists to prevent. The row is a
        // record of when the release landed, not state this migration owns.
    }
};
