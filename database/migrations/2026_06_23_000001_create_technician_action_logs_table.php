<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * technician_action_logs — the immutable, append-only audit trail for every
 * side-effecting AI-Technician action (spec §4.3/§4.6). Mirrors
 * tactical_action_logs: model guards cover SQLite; MariaDB/MySQL triggers block
 * even raw query-builder writes (driver-gated).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('technician_action_logs', function (Blueprint $table) {
            $table->id();
            // Actor: the reused AI-actor user; actor_label is always 'ai-technician'.
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_label');                 // always 'ai-technician'
            $table->string('action_type')->index();         // e.g. send_ack, send_reply
            $table->string('tier');                         // auto|approve|block (resolved server-side)
            $table->string('result_status')->index();       // executed|awaiting_approval|blocked|held
            $table->foreignId('ticket_id')->nullable()->constrained('tickets')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            // run_id is a plain (nullable) FK-less column to avoid an ordering
            // dependency on the technician_runs migration; constrained later if needed.
            $table->unsignedBigInteger('run_id')->nullable()->index();
            $table->string('content_hash', 64)->index();    // sha256 of the action payload
            $table->text('summary');                        // human-readable one-liner
            $table->string('correlation_id')->index();
            // Append-only: created_at ONLY (no updated_at).
            $table->timestamp('created_at')->nullable();
        });

        // DB-layer immutability — MariaDB/MySQL only (skipped on SQLite tests).
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared('DROP TRIGGER IF EXISTS technician_action_logs_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS technician_action_logs_no_delete');
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER technician_action_logs_no_update
                BEFORE UPDATE ON technician_action_logs
                FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'technician_action_logs is append-only';
            SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER technician_action_logs_no_delete
                BEFORE DELETE ON technician_action_logs
                FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'technician_action_logs is append-only';
            SQL);
        }
    }

    public function down(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared('DROP TRIGGER IF EXISTS technician_action_logs_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS technician_action_logs_no_delete');
        }

        Schema::dropIfExists('technician_action_logs');
    }
};
