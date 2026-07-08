<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->string('m365_device_id', 36)->nullable()->unique()->after('zorus_synced_at');
            $table->string('m365_compliance_state', 50)->nullable()->after('m365_device_id');
            $table->boolean('m365_is_compliant')->nullable()->after('m365_compliance_state');
            $table->string('m365_enrollment_type', 50)->nullable()->after('m365_is_compliant');
            $table->string('m365_os_version', 100)->nullable()->after('m365_enrollment_type');
            $table->timestamp('m365_last_sync_at')->nullable()->after('m365_os_version');
            $table->string('m365_device_owner_type', 20)->nullable()->after('m365_last_sync_at');
            $table->string('m365_defender_status', 50)->nullable()->after('m365_device_owner_type');
            $table->string('m365_defender_version', 100)->nullable()->after('m365_defender_status');
            $table->timestamp('m365_last_scan_at')->nullable()->after('m365_defender_version');
            $table->timestamp('m365_synced_at')->nullable()->after('m365_last_scan_at');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn([
                'm365_device_id', 'm365_compliance_state', 'm365_is_compliant',
                'm365_enrollment_type', 'm365_os_version', 'm365_last_sync_at',
                'm365_device_owner_type', 'm365_defender_status', 'm365_defender_version',
                'm365_last_scan_at', 'm365_synced_at',
            ]);
        });
    }
};
