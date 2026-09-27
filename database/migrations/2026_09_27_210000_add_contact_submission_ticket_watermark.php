<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive: the highest ledger id visible when a submission CREATED its ticket. A later
 * submission whose id is at or below it was received before that ticket existed, even
 * within the same second (timestamps here are second-precision), so it is a simultaneous
 * inquiry and gets its own ticket rather than becoming a follow-up note.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_submissions', function (Blueprint $table) {
            $table->unsignedBigInteger('ticket_watermark')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('contact_submissions', function (Blueprint $table) {
            $table->dropColumn('ticket_watermark');
        });
    }
};
