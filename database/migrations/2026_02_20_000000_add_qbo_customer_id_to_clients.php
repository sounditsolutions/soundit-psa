<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('qbo_customer_id', 50)->nullable()->unique()->after('halo_synced_at');
            $table->string('qbo_display_name')->nullable()->after('qbo_customer_id');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['qbo_customer_id', 'qbo_display_name']);
        });
    }
};
