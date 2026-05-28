<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->boolean('auto_renew')->default(false)->after('term_length_months');
        });

        // Fix: Halo's billingperiod=2 means "annual term", not "annual invoicing".
        // All Halo-synced contracts actually invoice monthly via their recurring profiles.
        // Move the annual indicator to term_length_months and set billing_period to monthly.
        // These are data-backfills for production; no-op on SQLite test databases.
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("
                UPDATE contracts
                SET term_length_months = 12,
                    auto_renew = 1,
                    billing_period = 'monthly'
                WHERE billing_period = 'annually'
                  AND halo_id IS NOT NULL
            ");

            // Also fix profiles that inherited the incorrect annually value from the backfill.
            DB::statement("
                UPDATE recurring_invoice_profiles p
                JOIN contracts c ON p.contract_id = c.id
                SET p.billing_period = 'monthly'
                WHERE p.billing_period = 'annually'
                  AND c.halo_id IS NOT NULL
            ");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            // Restore annually on contracts that were fixed
            DB::statement("
                UPDATE contracts
                SET billing_period = 'annually',
                    term_length_months = NULL,
                    auto_renew = 0
                WHERE term_length_months = 12
                  AND auto_renew = 1
                  AND halo_id IS NOT NULL
            ");

            // Restore annually on affected profiles
            DB::statement("
                UPDATE recurring_invoice_profiles p
                JOIN contracts c ON p.contract_id = c.id
                SET p.billing_period = 'annually'
                WHERE c.term_length_months = 12
                  AND c.auto_renew = 1
                  AND c.halo_id IS NOT NULL
            ");
        }

        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn('auto_renew');
        });
    }
};
