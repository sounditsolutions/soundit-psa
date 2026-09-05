<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            // HelpDesk Buttons press id (UUID) parsed out of the vendor's private
            // note. Nullable because absence is a NORMAL state, not an error: 2 of
            // the 50 most recent helpdesk_button tickets carry no such note at all.
            // Deliberately NOT source_ref, which is a shared per-source reference
            // (AlertService writes the alert id into it) — a dedicated column keeps
            // a future shim write from silently clobbering the press id.
            $table->char('hdb_press_id', 36)->nullable()->index()->after('source_ref');
        });
    }

    public function down(): void
    {
        // The index is dropped in its own statement, before the column. SQLite
        // keeps the index definition alive across an ALTER ... DROP COLUMN and
        // then fails the whole rollback with "1 error in index
        // tickets_hdb_press_id_index after drop column". The older source_ref
        // migration gets away with a bare dropColumn only because it predates
        // the taxonomy rollback guard's cutoff and is never rolled back there.
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex(['hdb_press_id']);
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn('hdb_press_id');
        });
    }
};
