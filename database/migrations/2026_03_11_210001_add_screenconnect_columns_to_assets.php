<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->string('screenconnect_session_id', 36)->nullable()->unique()->after('m365_synced_at');
            $table->boolean('screenconnect_online')->nullable()->after('screenconnect_session_id');
            $table->string('screenconnect_client_version', 30)->nullable()->after('screenconnect_online');
            $table->timestamp('screenconnect_last_seen_at')->nullable()->after('screenconnect_client_version');
            $table->timestamp('screenconnect_synced_at')->nullable()->after('screenconnect_last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn([
                'screenconnect_session_id',
                'screenconnect_online',
                'screenconnect_client_version',
                'screenconnect_last_seen_at',
                'screenconnect_synced_at',
            ]);
        });
    }
};
