<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('license_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('vendor', 50);
            $table->string('vendor_sku_id', 255)->nullable();
            $table->foreignId('sku_id')->nullable()->constrained('skus')->nullOnDelete();
            $table->decimal('default_unit_cost', 10, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['vendor', 'vendor_sku_id']);
            $table->index('sku_id');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('license_types');
    }
};
