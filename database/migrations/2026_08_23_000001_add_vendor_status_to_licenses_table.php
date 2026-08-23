<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The vendor-reported subscription status, recorded on EVERY sync observation —
     * the reported status on the inconclusive hold-out path ('Suspended', 'Pending'),
     * the Active/Trial value on the create/update path.
     *
     * Deliberately a NEW column, not a new `status` value: `status` is the PSA's own
     * cleanup flag (`scopeActive()` is `where('status','active')`) and a row can be
     * PSA-active and vendor-suspended at the same time. Those are orthogonal facts;
     * collapsing them into one column destroys the restore path the inconclusive
     * hold-out guard exists to protect.
     *
     * NULL means "the vendor sync for this row does not report a status" — CIPP and
     * Microsoft rows today — and MUST bill normally. See
     * {@see \App\Models\License::vendorBillable()} for the fail-open rationale.
     */
    public function up(): void
    {
        Schema::table('licenses', function (Blueprint $table) {
            $table->string('vendor_status', 30)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('licenses', function (Blueprint $table) {
            $table->dropColumn('vendor_status');
        });
    }
};
