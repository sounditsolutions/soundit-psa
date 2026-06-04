<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Safety gate: abort if any profile lines still use qbo_item_ref without a sku_id
        $orphans = DB::table('recurring_invoice_profile_lines')
            ->whereNull('sku_id')
            ->whereNotNull('qbo_item_ref')
            ->where('qbo_item_ref', '!=', '')
            ->count();

        if ($orphans > 0) {
            throw new \RuntimeException(
                "Cannot drop qbo_item_ref: {$orphans} profile lines still reference it without a sku_id. "
                .'Run halo:sync-recurring-profiles to link them to SKUs first.'
            );
        }

        Schema::table('recurring_invoice_profile_lines', function (Blueprint $table) {
            $table->dropColumn('qbo_item_ref');
        });
    }

    public function down(): void
    {
        Schema::table('recurring_invoice_profile_lines', function (Blueprint $table) {
            $table->string('qbo_item_ref', 50)->nullable()->after('fixed_quantity');
        });
    }
};
