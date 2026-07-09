<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recurring_invoice_profile_lines', function (Blueprint $table) {
            // Graduated tier pricing. Null/empty = flat pricing via unit_price.
            // Each element: {"up_to": int|null, "unit_price": number}. Ordered
            // ascending; the final tier is unbounded (covers all remaining units).
            $table->json('pricing_tiers')->nullable()->after('unit_price');
        });
    }

    public function down(): void
    {
        Schema::table('recurring_invoice_profile_lines', function (Blueprint $table) {
            $table->dropColumn('pricing_tiers');
        });
    }
};
