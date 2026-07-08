<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('stripe_invoice_id', 255)->unique()->nullable()->after('qbo_sync_error');
            $table->string('stripe_invoice_url', 500)->nullable()->after('stripe_invoice_id');
            $table->timestamp('stripe_synced_at')->nullable()->after('stripe_invoice_url');
            $table->text('stripe_sync_error')->nullable()->after('stripe_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['stripe_invoice_id', 'stripe_invoice_url', 'stripe_synced_at', 'stripe_sync_error']);
        });
    }
};
