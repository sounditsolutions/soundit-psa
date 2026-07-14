<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * psa-xz0z: a TechnicianRun claimed for execution (awaiting_approval → executing) has no
 * timestamp, so a run stranded in 'executing' by a process death (OOM, request timeout, or
 * PHP-FPM restarting mid-request during a DEPLOY) can never be told apart from one legitimately
 * executing right now. This column records WHEN the claim was taken, so the stale-claim reaper
 * (technician:reap-stale-claims) can measure "stuck past a sane TTL" instead of guessing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('technician_runs', function (Blueprint $table) {
            $table->timestamp('claimed_at')->nullable()->after('state');
        });
    }

    public function down(): void
    {
        Schema::table('technician_runs', function (Blueprint $table) {
            $table->dropColumn('claimed_at');
        });
    }
};
