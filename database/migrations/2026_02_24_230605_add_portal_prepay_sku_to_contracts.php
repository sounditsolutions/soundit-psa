<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->foreignId('portal_prepay_sku_id')
                ->nullable()
                ->after('halo_prepay_synced_at')
                ->constrained('skus')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropForeign(['portal_prepay_sku_id']);
            $table->dropColumn('portal_prepay_sku_id');
        });
    }
};
