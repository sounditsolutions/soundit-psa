<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->foreignId('sku_id')->nullable()->after('invoice_id')
                ->constrained('skus')->nullOnDelete();
            $table->boolean('is_taxable')->default(true)->after('quantity_source');
            $table->string('qbo_item_ref', 50)->nullable()->after('is_taxable');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sku_id');
            $table->dropColumn(['is_taxable', 'qbo_item_ref']);
        });
    }
};
