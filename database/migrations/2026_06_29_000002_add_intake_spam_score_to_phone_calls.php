<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phone_calls', function (Blueprint $table) {
            // AI-intake spam suspicion (0.00–1.00). Null = not assessed / not suspected.
            // A spam call is ticketless, so the suspicion lives here on the call (its
            // followed_up_at is the resolution state) rather than on a TechnicianRun,
            // whose ticket_id is NOT nullable.
            $table->decimal('intake_spam_score', 3, 2)->nullable()->after('caller_identity_confidence');
        });
    }

    public function down(): void
    {
        Schema::table('phone_calls', function (Blueprint $table) {
            $table->dropColumn('intake_spam_score');
        });
    }
};
