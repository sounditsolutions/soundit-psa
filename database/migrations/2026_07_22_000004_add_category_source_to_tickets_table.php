<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * so-0ftg Part 4 (psa-trjwf re-review) — who last wrote tickets.category_id,
 * stored ON the tickets row so the triage mapping's human-precedence decision
 * reads the value and its owner in ONE row-locked read. The change-log table
 * cannot serve that role: its INSERT (TicketObserver::updated) trails the
 * category_id UPDATE it describes, so a concurrent transaction can see the new
 * value while the log still names the previous writer. Values mirror
 * TicketCategoryChangeSource ('triage'|'staff'|'system'); null = the column
 * has never been stamped (pre-feature data — reads as human-owned).
 *
 * The backfill copies each ticket's latest change-log source so a database
 * that ran the pre-revision branch keeps its ownership semantics: a node the
 * log says triage wrote last stays triage-remappable after the upgrade.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            // No index: never queried by value, only read off an already
            // fetched row. (An explicit index would also break the SQLite
            // DROP COLUMN in down() — see 2026_07_22_000002.)
            $table->string('category_source', 16)->nullable()->after('category_id');
        });

        // Correlated scalar subquery — portable across MariaDB and SQLite.
        // Ordering matches TicketCategoryChangeLog's "latest row" semantics
        // (created_at, then id for same-second ties).
        DB::statement(<<<'SQL'
            UPDATE tickets SET category_source = (
                SELECT l.source FROM ticket_category_change_logs l
                WHERE l.ticket_id = tickets.id
                ORDER BY l.created_at DESC, l.id DESC
                LIMIT 1
            )
            WHERE EXISTS (
                SELECT 1 FROM ticket_category_change_logs l WHERE l.ticket_id = tickets.id
            )
        SQL);
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn('category_source');
        });
    }
};
