<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('source');
            $table->foreignId('source_profile_line_id')->nullable()->constrained('recurring_invoice_profile_lines')->nullOnDelete();
            $table->foreignId('sku_id')->nullable()->constrained();
            $table->string('description');
            $table->string('quantity_type');
            $table->decimal('fixed_quantity', 10, 2)->nullable();
            $table->decimal('unit_price', 10, 2);
            $table->json('pricing_tiers')->nullable();
            $table->boolean('is_taxable');
            $table->integer('sort_order');
            $table->string('option_group')->nullable();
            $table->string('term')->nullable();
            $table->timestamps();
            $table->unique(['contract_id', 'version', 'source_profile_line_id'], 'contract_lines_version_source_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_lines');
    }
};
