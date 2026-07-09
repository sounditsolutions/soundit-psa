<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recurring_invoice_profile_lines', function (Blueprint $table) {
            // Links a line whose quantity_type = 'custom' to its definition.
            // restrictOnDelete protects billing integrity: a custom type that is
            // still referenced by a profile line cannot be hard-deleted out from
            // under it (which would silently resolve the line to qty 0).
            $table->foreignId('custom_quantity_type_id')->nullable()->after('license_type_id')
                ->constrained('custom_quantity_types')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('recurring_invoice_profile_lines', function (Blueprint $table) {
            $table->dropForeign(['custom_quantity_type_id']);
            $table->dropColumn('custom_quantity_type_id');
        });
    }
};
