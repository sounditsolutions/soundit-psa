<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_notes', function (Blueprint $table) {
            $table->dropUnique(['ticket_id', 'halo_note_id']);
        });
    }

    public function down(): void
    {
        Schema::table('ticket_notes', function (Blueprint $table) {
            $table->unique(['ticket_id', 'halo_note_id']);
        });
    }
};
