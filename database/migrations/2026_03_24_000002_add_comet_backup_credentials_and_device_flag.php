<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('comet_backup_user')->nullable()->after('comet_group_id');
            $table->text('comet_backup_password')->nullable()->after('comet_backup_user');
        });

        Schema::table('assets', function (Blueprint $table) {
            $table->boolean('comet_backup_enabled')->default(false)->after('comet_device_id');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['comet_backup_user', 'comet_backup_password']);
        });

        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn('comet_backup_enabled');
        });
    }
};
