<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('license_types', function (Blueprint $table) {
            $table->unsignedInteger('cost_divisor')->nullable()->after('default_unit_cost');
        });

        // Also increase decimal precision on default_unit_cost for edge cases
        Schema::table('license_types', function (Blueprint $table) {
            $table->decimal('default_unit_cost', 12, 4)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('license_types', function (Blueprint $table) {
            $table->dropColumn('cost_divisor');
            $table->decimal('default_unit_cost', 10, 2)->nullable()->change();
        });
    }
};
