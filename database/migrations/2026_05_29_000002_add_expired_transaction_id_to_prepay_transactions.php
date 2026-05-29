<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prepay_transactions', function (Blueprint $table) {
            // Links an Expiration debit to the credit "lot" it forfeits. Enables the
            // idempotent converge in PrepayExpirationService (key by lot id). Cascade:
            // a forfeiture is meaningless without its lot, so deleting the credit
            // deletes its expiration row (also avoids orphaned negative rows).
            $table->foreignId('expired_transaction_id')
                ->nullable()
                ->after('phone_call_id')
                ->constrained('prepay_transactions')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('prepay_transactions', function (Blueprint $table) {
            $table->dropForeign(['expired_transaction_id']);
            $table->dropColumn('expired_transaction_id');
        });
    }
};
