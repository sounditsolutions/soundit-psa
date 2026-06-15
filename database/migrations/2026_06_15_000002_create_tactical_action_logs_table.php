<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * tactical_action_logs — the immutable, append-only audit trail for every
 * endpoint-affecting Tactical action (spec §5.2, §11 / amendments M6, m1, m2).
 *
 * Immutability is enforced in TWO layers:
 *   - the TacticalActionLog model (boot updating/deleting guards) — everywhere,
 *     incl. SQLite;
 *   - MariaDB/MySQL BEFORE UPDATE / BEFORE DELETE triggers that SIGNAL — block
 *     even raw query-builder writes. Driver-guarded (skipped on SQLite).
 *
 * Scope of the immutability claim (per M6): this blocks app-tier UPDATE/DELETE
 * including the raw query builder; it does NOT block TRUNCATE/DROP or a DBA.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tactical_action_logs', function (Blueprint $table) {
            $table->id();
            // Actor: null for the AI/system path (actor_label = 'ai-triage' etc.).
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_label');                 // resolved actor identity (email / 'ai-triage')
            $table->string('action_key')->index();          // e.g. tactical.run_script, tactical.reboot
            $table->string('agent_id')->nullable()->index(); // Tactical agent id
            $table->foreignId('asset_id')->nullable()->constrained('assets')->nullOnDelete();
            $table->foreignId('ticket_id')->nullable()->constrained('tickets')->nullOnDelete(); // m1: per-incident ITIL history
            $table->string('target_label');                 // human target (hostname)
            $table->json('params');                          // redacted before write (T5)
            // m2: distinct statuses — rejected = invalid params, blocked = missing confirm.
            $table->string('result_status')->index();        // ok|offline|error|denied|rejected|blocked
            $table->integer('retcode')->nullable();
            $table->text('output')->nullable();              // redacted + truncated before write (T5)
            $table->string('message')->nullable();
            $table->uuid('correlation_id')->index();
            // Append-only: created_at ONLY (no updated_at).
            $table->timestamp('created_at')->nullable();
        });

        // DB-layer immutability — MariaDB/MySQL only (M6: NOT `=== 'mariadb'`).
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared('DROP TRIGGER IF EXISTS tactical_action_logs_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS tactical_action_logs_no_delete');
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER tactical_action_logs_no_update
                BEFORE UPDATE ON tactical_action_logs
                FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'tactical_action_logs is append-only';
            SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER tactical_action_logs_no_delete
                BEFORE DELETE ON tactical_action_logs
                FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'tactical_action_logs is append-only';
            SQL);
        }
    }

    public function down(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared('DROP TRIGGER IF EXISTS tactical_action_logs_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS tactical_action_logs_no_delete');
        }

        Schema::dropIfExists('tactical_action_logs');
    }
};
