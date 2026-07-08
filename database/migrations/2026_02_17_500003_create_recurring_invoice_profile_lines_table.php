<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_invoice_profile_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('recurring_invoice_profiles')->cascadeOnDelete();
            $table->string('description');
            $table->decimal('unit_price', 10, 2);
            $table->string('quantity_type', 30);
            $table->decimal('fixed_quantity', 10, 2)->default(1);
            $table->string('qbo_item_ref', 50)->nullable();
            $table->boolean('is_taxable')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('profile_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_invoice_profile_lines');
    }
};
