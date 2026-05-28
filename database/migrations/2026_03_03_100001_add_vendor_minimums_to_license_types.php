<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('license_types', function (Blueprint $table) {
            $table->unsignedInteger('minimum_quantity')->nullable()->after('cost_divisor');
            $table->decimal('minimum_cost', 12, 2)->nullable()->after('minimum_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('license_types', function (Blueprint $table) {
            $table->dropColumn(['minimum_quantity', 'minimum_cost']);
        });
    }
};
