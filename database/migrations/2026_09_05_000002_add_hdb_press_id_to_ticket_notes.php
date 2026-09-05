<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_notes', function (Blueprint $table) {
            // The press id belongs to the NOTE that carries the link, not to the
            // ticket around it. One HDB press produces one note; a ticket can
            // legitimately hold several presses (a merge brings the notes with it,
            // and the ticket's own source does not follow them). Keying the note
            // deletes the "two press ids on one ticket" conflict class outright
            // instead of asking a win-rule to guess which endpoint is the real one.
            //
            // MEASURED ON PROD 2026-09-05 (counts only): 105 live notes carry an
            // HDB pressID link, across 95 distinct tickets — and 50 of those 95 are
            // NOT source=helpdesk_button, which is why the ticket-scoped backfill
            // saw a 57-ticket window inside a 95-ticket problem.
            //
            // Nullable because the overwhelming majority of notes are not presses.
            $table->char('hdb_press_id', 36)->nullable()->index()->after('body_html');
        });
    }

    public function down(): void
    {
        // Index dropped in its own statement, before the column — same SQLite
        // rollback failure the tickets migration documents ("1 error in index
        // ... after drop column").
        Schema::table('ticket_notes', function (Blueprint $table) {
            $table->dropIndex(['hdb_press_id']);
        });

        Schema::table('ticket_notes', function (Blueprint $table) {
            $table->dropColumn('hdb_press_id');
        });
    }
};
