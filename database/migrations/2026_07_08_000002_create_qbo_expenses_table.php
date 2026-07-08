<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qbo_expenses', function (Blueprint $table) {
            $table->id();
            $table->string('qbo_purchase_id', 50)->unique();
            $table->date('txn_date')->nullable();
            $table->string('payment_type', 50)->nullable();
            $table->string('account_name')->nullable();
            $table->string('payee_name')->nullable();
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->string('currency', 10)->nullable();
            $table->string('doc_number', 100)->nullable();
            $table->text('memo')->nullable();
            $table->timestamp('qbo_synced_at')->nullable();
            $table->timestamps();

            $table->index('txn_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qbo_expenses');
    }
};
