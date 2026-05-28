<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->string('zorus_endpoint_id', 36)->nullable()->unique()->after('controld_device_id');
            $table->string('zorus_group_name', 100)->nullable()->after('controld_synced_at');
            $table->boolean('zorus_filtering_enabled')->nullable()->after('zorus_group_name');
            $table->boolean('zorus_cybersight_enabled')->nullable()->after('zorus_filtering_enabled');
            $table->string('zorus_agent_version', 30)->nullable()->after('zorus_cybersight_enabled');
            $table->string('zorus_agent_state', 30)->nullable()->after('zorus_agent_version');
            $table->timestamp('zorus_last_seen_at')->nullable()->after('zorus_agent_state');
            $table->timestamp('zorus_synced_at')->nullable()->after('zorus_last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn([
                'zorus_endpoint_id',
                'zorus_group_name',
                'zorus_filtering_enabled',
                'zorus_cybersight_enabled',
                'zorus_agent_version',
                'zorus_agent_state',
                'zorus_last_seen_at',
                'zorus_synced_at',
            ]);
        });
    }
};
