<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->unsignedBigInteger('carbon_customer_id')->nullable();
            $table->boolean('carbon_hardware_ordering_enabled')->default(false);
        });
        Schema::create('client_hardware_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('mfg_part_no');
            $table->string('label');
            $table->decimal('sell_price', 10, 2);
            $table->decimal('carbon_price', 10, 2)->nullable();
            $table->string('availability')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->unique(['client_id', 'mfg_part_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_hardware_models');
        Schema::table('clients', fn (Blueprint $table) => $table->dropColumn(['carbon_customer_id', 'carbon_hardware_ordering_enabled']));
    }
};
