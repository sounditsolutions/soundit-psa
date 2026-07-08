<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->string('controld_device_id', 20)->nullable()->unique()->after('level_id');
            $table->string('controld_profile_name', 100)->nullable()->after('level_url');
            $table->tinyInteger('controld_status')->nullable()->after('controld_profile_name');
            $table->tinyInteger('controld_agent_status')->nullable()->after('controld_status');
            $table->string('controld_agent_version', 20)->nullable()->after('controld_agent_status');
            $table->timestamp('controld_last_seen_at')->nullable()->after('controld_agent_version');
            $table->timestamp('controld_synced_at')->nullable()->after('level_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn([
                'controld_device_id',
                'controld_profile_name',
                'controld_status',
                'controld_agent_status',
                'controld_agent_version',
                'controld_last_seen_at',
                'controld_synced_at',
            ]);
        });
    }
};
