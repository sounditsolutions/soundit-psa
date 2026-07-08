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
        Schema::table('recurring_invoice_profiles', function (Blueprint $table) {
            $table->boolean('skip_zero_invoices')->nullable()->after('payment_terms_days');
        });
    }

    public function down(): void
    {
        Schema::table('recurring_invoice_profiles', function (Blueprint $table) {
            $table->dropColumn('skip_zero_invoices');
        });
    }
};
