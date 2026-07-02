<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signal_config_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->json('changes');
            $table->timestamp('created_at')->nullable();

            $table->index(['subject_type', 'subject_id']);
            $table->index('created_at');
        });

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared('DROP TRIGGER IF EXISTS signal_config_log_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS signal_config_log_no_delete');
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER signal_config_log_no_update
                BEFORE UPDATE ON signal_config_log
                FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'signal_config_log is append-only';
            SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER signal_config_log_no_delete
                BEFORE DELETE ON signal_config_log
                FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'signal_config_log is append-only';
            SQL);
        }
    }

    public function down(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared('DROP TRIGGER IF EXISTS signal_config_log_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS signal_config_log_no_delete');
        }

        Schema::dropIfExists('signal_config_log');
    }
};
