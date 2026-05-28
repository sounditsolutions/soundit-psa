<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->string('cipp_user_id', 36)->nullable()->unique()->after('halo_id');
            $table->timestamp('cipp_synced_at')->nullable()->after('halo_synced_at');
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->string('cipp_sync_group_id', 36)->nullable()->after('cipp_tenant_domain');
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropColumn(['cipp_user_id', 'cipp_synced_at']);
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('cipp_sync_group_id');
        });
    }
};
