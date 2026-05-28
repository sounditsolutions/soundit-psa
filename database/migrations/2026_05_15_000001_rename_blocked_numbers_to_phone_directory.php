<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('blocked_numbers', 'phone_directory');

        // Drop the existing FK (its name still references blocked_numbers); we'll re-add it after the column rename.
        DB::statement('ALTER TABLE phone_directory DROP FOREIGN KEY blocked_numbers_blocked_by_user_id_foreign');

        // Rename blocked_by_user_id -> added_by_user_id, add list_type + label.
        DB::statement('ALTER TABLE phone_directory CHANGE blocked_by_user_id added_by_user_id BIGINT UNSIGNED NULL');

        Schema::table('phone_directory', function (Blueprint $table) {
            $table->string('list_type', 20)->default('blocked')->after('phone_number');
            $table->string('label', 255)->nullable()->after('list_type');

            $table->foreign('added_by_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('phone_directory', function (Blueprint $table) {
            $table->dropForeign(['added_by_user_id']);
            $table->dropColumn(['list_type', 'label']);
        });

        DB::statement('ALTER TABLE phone_directory CHANGE added_by_user_id blocked_by_user_id BIGINT UNSIGNED NULL');

        Schema::table('phone_directory', function (Blueprint $table) {
            $table->foreign('blocked_by_user_id', 'blocked_numbers_blocked_by_user_id_foreign')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });

        Schema::rename('phone_directory', 'blocked_numbers');
    }
};
