<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Volume-pricing rate card for backup-storage SKUs. Each row is one
        // tier: the measured storage (GB) selects the first tier whose
        // `up_to_gb` covers it, and the whole quantity is billed at that
        // tier's `unit_price` per GB. A row with a null `up_to_gb` is the
        // unbounded catch-all (top) tier.
        Schema::create('backup_storage_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sku_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('up_to_gb')->nullable(); // inclusive upper bound; null = unbounded
            $table->decimal('unit_price', 10, 2)->default(0);   // price per GB when total falls in this tier
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['sku_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_storage_tiers');
    }
};
