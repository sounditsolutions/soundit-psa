<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recurring_invoice_profiles', function (Blueprint $table) {
            $table->string('billing_period', 20)->default('monthly')->after('is_active');
            $table->unsignedTinyInteger('billing_day')->default(1)->after('billing_period');
            $table->unsignedSmallInteger('payment_terms_days')->default(30)->after('billing_day');
        });

        // Backfill from parent contract (NULL-safe) — MariaDB/MySQL only (JOIN UPDATE syntax)
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("
                UPDATE recurring_invoice_profiles p
                JOIN contracts c ON p.contract_id = c.id
                SET p.billing_period = COALESCE(c.billing_period, 'monthly'),
                    p.billing_day = COALESCE(c.billing_day, 1),
                    p.payment_terms_days = COALESCE(c.payment_terms_days, 30)
            ");
        }
    }

    public function down(): void
    {
        Schema::table('recurring_invoice_profiles', function (Blueprint $table) {
            $table->dropColumn(['billing_period', 'billing_day', 'payment_terms_days']);
        });
    }
};
