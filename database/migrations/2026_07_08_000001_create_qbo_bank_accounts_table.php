<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qbo_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('qbo_account_id', 50)->unique();
            $table->string('name');
            $table->string('account_sub_type', 100)->nullable();
            $table->string('classification', 50)->nullable();
            $table->decimal('current_balance', 15, 2)->default(0);
            $table->string('currency', 10)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamp('qbo_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qbo_bank_accounts');
    }
};
