<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durable approver attribution (psa-uohr) — a client-facing-send prerequisite.
 *
 * The approver identity previously lived only inside the 600s-TTL signed grant and
 * was dropped at audit time. Persist it on the append-only audit row so an executed,
 * human-approved AI action carries a forensic record of WHO approved it.
 *
 * Nullable: AUTO actions have no human approver; only a verified-grant execution sets
 * it. Adding a column is DDL (ALTER TABLE) and is NOT blocked by the table's
 * append-only BEFORE UPDATE/DELETE row triggers. `->after('actor_id')` is safe —
 * actor_id is created in the earlier base migration (2026_06_23_000001), so
 * migrate:fresh stays green (column order is cosmetic in MariaDB regardless).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('technician_action_logs', function (Blueprint $table) {
            $table->foreignId('approver_user_id')->nullable()->after('actor_id')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('technician_action_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approver_user_id');
        });
    }
};
