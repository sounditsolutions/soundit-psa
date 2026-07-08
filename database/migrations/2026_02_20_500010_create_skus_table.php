<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('skus', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('sku_code', 50)->unique();
            $table->string('category', 50)->nullable();
            $table->decimal('unit_price', 10, 2)->default(0);
            $table->decimal('unit_cost', 10, 2)->default(0);
            $table->boolean('is_taxable')->default(true);
            $table->boolean('is_active')->default(true);
            $table->string('qbo_item_id', 50)->unique()->nullable();
            $table->string('qbo_sync_hash', 64)->nullable();
            $table->timestamp('qbo_synced_at')->nullable();
            $table->string('qbo_sync_error', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('is_active');
            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skus');
    }
};
