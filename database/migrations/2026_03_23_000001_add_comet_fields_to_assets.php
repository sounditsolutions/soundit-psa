<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->string('comet_username')->nullable()->after('backup_synced_at');
            $table->string('comet_device_id')->nullable()->unique()->after('comet_username');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn(['comet_username', 'comet_device_id']);
        });
    }
};
