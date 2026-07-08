<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recurring_invoice_profile_lines', function (Blueprint $table) {
            $table->unsignedInteger('prepaid_time_override')->nullable()->after('unit_cost_override');
        });
    }

    public function down(): void
    {
        Schema::table('recurring_invoice_profile_lines', function (Blueprint $table) {
            $table->dropColumn('prepaid_time_override');
        });
    }
};
