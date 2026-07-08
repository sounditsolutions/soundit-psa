<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Halo action IDs are per-ticket sequential, not globally unique.
 * The old unique index on halo_note_id alone caused notes to be
 * stolen between tickets during sync. Fix: composite unique on
 * (ticket_id, halo_note_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_notes', function (Blueprint $table) {
            $table->dropUnique('ticket_notes_halo_note_id_unique');
            $table->unique(['ticket_id', 'halo_note_id']);
        });
    }

    public function down(): void
    {
        Schema::table('ticket_notes', function (Blueprint $table) {
            $table->dropUnique(['ticket_id', 'halo_note_id']);
            $table->unique('halo_note_id');
        });
    }
};
