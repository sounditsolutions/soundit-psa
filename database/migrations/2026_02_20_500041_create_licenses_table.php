<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('licenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('license_type_id')->constrained('license_types')->restrictOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->string('vendor_ref', 255)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamp('synced_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['license_type_id', 'client_id', 'vendor_ref']);
            $table->index('client_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('licenses');
    }
};
