<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('license_types')
            ->whereIn('vendor', ['ninja_backup', 'ninja_rmm'])
            ->update(['vendor' => 'ninjaone']);
    }

    public function down(): void
    {
        DB::table('license_types')
            ->where('vendor', 'ninjaone')
            ->whereIn('vendor_sku_id', ['cloud_backup', 'cloud_backup_server', 'cloud_backup_workstation', 'cloud_usage_gb'])
            ->update(['vendor' => 'ninja_backup']);

        DB::table('license_types')
            ->where('vendor', 'ninjaone')
            ->whereIn('vendor_sku_id', ['rmm_devices', 'rmm_server', 'rmm_workstation'])
            ->update(['vendor' => 'ninja_rmm']);
    }
};
