<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prepay_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('halo_id');
            $table->dateTime('date')->nullable();
            $table->decimal('hours', 10, 4)->nullable();
            $table->decimal('amount', 10, 2)->nullable();
            $table->string('description')->nullable();
            $table->string('invoice_number')->nullable();
            $table->dateTime('invoice_date')->nullable();
            $table->dateTime('expiry_date')->nullable();
            $table->timestamps();

            $table->unique(['contract_id', 'halo_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prepay_transactions');
    }
};
