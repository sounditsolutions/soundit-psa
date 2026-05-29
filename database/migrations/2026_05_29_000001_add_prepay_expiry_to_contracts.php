<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            // Expiration policy: prepaid hours expire this many months after the
            // credit's date. Null = no expiration (default — zero behaviour change).
            $table->unsignedInteger('prepay_expiry_months')->nullable()->after('prepay_alert_notified_at');

            // Denormalized total of forfeited (expired) hours, for display. Kept
            // distinct from prepay_used (work consumption) so the ledger split is
            // legible. Recomputed by PrepayService::recalculateBalance().
            $table->decimal('prepay_expired', 10, 2)->nullable()->after('prepay_used');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn(['prepay_expiry_months', 'prepay_expired']);
        });
    }
};
