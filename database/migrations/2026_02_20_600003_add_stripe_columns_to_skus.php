<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('skus', function (Blueprint $table) {
            $table->string('stripe_product_id', 255)->unique()->nullable()->after('qbo_sync_error');
            $table->string('stripe_price_id', 255)->nullable()->after('stripe_product_id');
            $table->timestamp('stripe_synced_at')->nullable()->after('stripe_price_id');
        });
    }

    public function down(): void
    {
        Schema::table('skus', function (Blueprint $table) {
            $table->dropColumn(['stripe_product_id', 'stripe_price_id', 'stripe_synced_at']);
        });
    }
};
