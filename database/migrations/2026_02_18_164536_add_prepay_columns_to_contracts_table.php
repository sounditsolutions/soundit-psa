<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->decimal('prepay_total', 10, 2)->nullable()->after('notes');
            $table->decimal('prepay_used', 10, 2)->nullable()->after('prepay_total');
            $table->decimal('prepay_balance', 10, 2)->nullable()->after('prepay_used');
            $table->boolean('prepay_as_amount')->nullable()->after('prepay_balance');
            $table->timestamp('halo_prepay_synced_at')->nullable()->after('prepay_as_amount');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn([
                'prepay_total',
                'prepay_used',
                'prepay_balance',
                'prepay_as_amount',
                'halo_prepay_synced_at',
            ]);
        });
    }
};
