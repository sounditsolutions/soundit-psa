<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assistant_conversations', function (Blueprint $table) {
            // Keys a conversation to an EXTERNAL system's id (e.g. a Teams conversation)
            // so the transcript can be looked up / created RACE-SAFELY (the unique index
            // makes a concurrent insert fail, which createOrFirst turns into a re-select).
            // NULL for in-app conversations (the human AI assistant) — both MariaDB and
            // SQLite allow multiple NULLs in a unique index, so they never collide.
            // Appended at the end of the table (no column-order dependency).
            $table->string('external_key', 255)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('assistant_conversations', function (Blueprint $table) {
            $table->dropUnique(['external_key']);
            $table->dropColumn('external_key');
        });
    }
};
