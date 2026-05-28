<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recurring_invoice_profiles', function (Blueprint $table) {
            $table->string('auto_push_mode', 20)->nullable()->after('skip_zero_invoices');
        });
    }

    public function down(): void
    {
        Schema::table('recurring_invoice_profiles', function (Blueprint $table) {
            $table->dropColumn('auto_push_mode');
        });
    }
};
