<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the AI-authored marker to ticket notes (spec §4.6, cf.
 * resolution_ai_drafted). A Technician-authored client note carries who_type =
 * Agent AND ai_authored = true so the UI/portal can render it as AI-authored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_notes', function (Blueprint $table) {
            $table->boolean('ai_authored')->default(false)->after('who_type');
        });
    }

    public function down(): void
    {
        Schema::table('ticket_notes', function (Blueprint $table) {
            $table->dropColumn('ai_authored');
        });
    }
};
