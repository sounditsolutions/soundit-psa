<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Link ticket notes to emails (bidirectional: email.ticket_id + note.email_id)
        if (! Schema::hasColumn('ticket_notes', 'email_id')) {
            Schema::table('ticket_notes', function (Blueprint $table) {
                $table->foreignId('email_id')->nullable()->after('author_id')
                    ->constrained('emails')->nullOnDelete();
            });
        }

        // Make graph_id nullable for outbound emails.
        // sendMail returns 202 with no body — outbound records have graph_id = NULL.
        // The existing UNIQUE constraint is preserved — multiple NULLs are allowed
        // in unique columns on both MariaDB and SQLite.
        Schema::table('emails', function (Blueprint $table) {
            $table->string('graph_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('ticket_notes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('email_id');
        });

        // WARNING: This will fail if any outbound email rows exist (graph_id = NULL).
        // Delete outbound emails first: DELETE FROM emails WHERE direction = 'outbound';
        Schema::table('emails', function (Blueprint $table) {
            $table->string('graph_id')->nullable(false)->change();
        });
    }
};
