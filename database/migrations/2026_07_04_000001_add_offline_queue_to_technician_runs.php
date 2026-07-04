<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Offline-script queue (bd psa-xr84). An approved staged Tactical action whose
 * device is offline parks in state `queued_offline` (no new table — the queue IS
 * the technician_runs ledger) and auto-runs on the device's next check-in.
 *
 * queued_agent_id     — the Tactical agent the action waits for (targeted sweep).
 * queued_dedup_key     — sha256(agent, script, args): one queued row per identical
 *                        action; a duplicate approval coalesces onto it.
 * queued_at/expires_at — enqueue time and the safety window (default 7d) after
 *                        which it never auto-runs and is re-surfaced for re-confirm.
 * coalesce_count       — how many duplicate approvals folded onto this queued row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('technician_runs', function (Blueprint $table) {
            $table->string('queued_agent_id')->nullable()->after('tokens_used');
            $table->string('queued_dedup_key')->nullable()->after('queued_agent_id');
            $table->timestamp('queued_at')->nullable()->after('queued_dedup_key');
            $table->timestamp('expires_at')->nullable()->after('queued_at');
            $table->unsignedInteger('coalesce_count')->default(0)->after('expires_at');

            $table->index(['state', 'queued_agent_id'], 'technician_runs_queue_agent');
            $table->index(['state', 'expires_at'], 'technician_runs_queue_expiry');
            $table->index('queued_dedup_key', 'technician_runs_queue_dedup');
        });
    }

    public function down(): void
    {
        Schema::table('technician_runs', function (Blueprint $table) {
            $table->dropIndex('technician_runs_queue_agent');
            $table->dropIndex('technician_runs_queue_expiry');
            $table->dropIndex('technician_runs_queue_dedup');
            $table->dropColumn(['queued_agent_id', 'queued_dedup_key', 'queued_at', 'expires_at', 'coalesce_count']);
        });
    }
};
