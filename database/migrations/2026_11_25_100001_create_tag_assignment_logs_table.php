<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit of tag link changes, written only by App\Services\TagService.
 *
 * tag_id and client_id are snapshots without foreign keys, so a row outlives the
 * tag it names and the client it was recorded under. On MariaDB/MySQL, triggers
 * refuse UPDATE and DELETE, the same as signal_config_log; sqlite skips them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tag_assignment_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tag_id');
            $table->string('tag_name', 64);
            $table->string('entity_type', 16);
            $table->unsignedBigInteger('entity_id');
            $table->unsignedBigInteger('client_id')->nullable();
            $table->string('action', 16);
            $table->string('source', 16);
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['entity_type', 'entity_id']);
            $table->index('tag_id');
            $table->index('client_id');
            $table->index('created_at');
        });

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared('DROP TRIGGER IF EXISTS tag_assignment_logs_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS tag_assignment_logs_no_delete');
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER tag_assignment_logs_no_update
                BEFORE UPDATE ON tag_assignment_logs
                FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'tag_assignment_logs is append-only';
            SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER tag_assignment_logs_no_delete
                BEFORE DELETE ON tag_assignment_logs
                FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'tag_assignment_logs is append-only';
            SQL);
        }
    }

    public function down(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared('DROP TRIGGER IF EXISTS tag_assignment_logs_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS tag_assignment_logs_no_delete');
        }

        Schema::dropIfExists('tag_assignment_logs');
    }
};
