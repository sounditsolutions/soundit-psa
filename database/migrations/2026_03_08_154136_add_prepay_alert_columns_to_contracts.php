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
            $table->decimal('prepay_alert_threshold', 10, 2)->nullable()->after('portal_prepay_sku_id');
            $table->unsignedInteger('prepay_auto_topup_qty')->nullable()->after('prepay_alert_threshold');
            $table->boolean('prepay_auto_topup_enabled')->default(false)->after('prepay_auto_topup_qty');
            $table->timestamp('prepay_alert_notified_at')->nullable()->after('prepay_auto_topup_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn(['prepay_alert_threshold', 'prepay_auto_topup_qty', 'prepay_auto_topup_enabled', 'prepay_alert_notified_at']);
        });
    }
};
