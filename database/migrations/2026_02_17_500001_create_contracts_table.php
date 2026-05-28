<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('halo_id')->unique()->nullable();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('type', 30);
            $table->string('status', 20)->default('active');
            $table->string('billing_source', 20)->default('psa');
            $table->string('billing_period', 20)->default('monthly');
            $table->unsignedTinyInteger('billing_day')->default(1);
            $table->unsignedSmallInteger('payment_terms_days')->default(30);
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('client_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contracts');
    }
};
