<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->unsignedBigInteger('backup_cloud_bytes')->nullable()->after('rmm_online');
            $table->unsignedBigInteger('backup_local_bytes')->nullable()->after('backup_cloud_bytes');
            $table->unsignedBigInteger('backup_revisions_bytes')->nullable()->after('backup_local_bytes');
            $table->timestamp('backup_synced_at')->nullable()->after('backup_revisions_bytes');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn([
                'backup_cloud_bytes',
                'backup_local_bytes',
                'backup_revisions_bytes',
                'backup_synced_at',
            ]);
        });
    }
};
