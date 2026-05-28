<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recurring_invoice_profile_lines', function (Blueprint $table) {
            $table->foreignId('usage_license_type_id')->nullable()->after('license_type_id')
                ->constrained('license_types')->nullOnDelete();
            $table->foreignId('base_license_type_id')->nullable()->after('usage_license_type_id')
                ->constrained('license_types')->nullOnDelete();
            $table->unsignedInteger('included_per_base_unit')->nullable()->after('base_license_type_id');
            $table->unsignedInteger('overage_divisor')->nullable()->default(1)->after('included_per_base_unit');
        });
    }

    public function down(): void
    {
        Schema::table('recurring_invoice_profile_lines', function (Blueprint $table) {
            $table->dropForeign(['usage_license_type_id']);
            $table->dropForeign(['base_license_type_id']);
            $table->dropColumn([
                'usage_license_type_id',
                'base_license_type_id',
                'included_per_base_unit',
                'overage_divisor',
            ]);
        });
    }
};
