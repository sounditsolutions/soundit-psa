<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A dated promo price charged only on a portal prepaid-time purchase.
 * Additive and nullable, no defaults, no data change: with all three columns
 * NULL nothing anywhere behaves differently. See Sku::portalPrepayUnitPrice().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('skus', function (Blueprint $table) {
            $table->decimal('portal_promo_price', 10, 2)->nullable();
            $table->dateTime('portal_promo_starts_at')->nullable();
            $table->dateTime('portal_promo_ends_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('skus', function (Blueprint $table) {
            $table->dropColumn(['portal_promo_price', 'portal_promo_starts_at', 'portal_promo_ends_at']);
        });
    }
};
