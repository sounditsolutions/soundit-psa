<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recurring_invoice_profiles', function (Blueprint $table) {
            $table->unsignedInteger('halo_id')->nullable()->unique()->after('id');
            $table->timestamp('halo_synced_at')->nullable()->after('last_run_date');
        });
    }

    public function down(): void
    {
        Schema::table('recurring_invoice_profiles', function (Blueprint $table) {
            $table->dropUnique(['halo_id']);
            $table->dropColumn(['halo_id', 'halo_synced_at']);
        });
    }
};
