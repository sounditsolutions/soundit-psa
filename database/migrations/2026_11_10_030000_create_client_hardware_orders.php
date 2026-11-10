<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_hardware_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('person_id')->constrained('people')->restrictOnDelete();
            $table->uuid('idempotency_key')->unique();
            $table->string('status');
            $table->string('confirmation_code', 64)->nullable();
            $table->json('response_keys')->nullable();
            $table->foreignId('ticket_id')->nullable()->constrained()->nullOnDelete();
            $table->json('lines');
            $table->decimal('estimated_total', 10, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_hardware_orders');
    }
};
