<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recurring_invoice_profile_lines', function (Blueprint $table) {
            $table->decimal('unit_cost_override', 10, 2)->nullable()->after('unit_price');
        });
    }

    public function down(): void
    {
        Schema::table('recurring_invoice_profile_lines', function (Blueprint $table) {
            $table->dropColumn('unit_cost_override');
        });
    }
};
