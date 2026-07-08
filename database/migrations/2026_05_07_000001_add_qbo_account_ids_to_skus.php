<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('skus', function (Blueprint $table) {
            $table->string('qbo_income_account_id')->nullable()->after('qbo_item_id');
            $table->string('qbo_expense_account_id')->nullable()->after('qbo_income_account_id');
        });
    }

    public function down(): void
    {
        Schema::table('skus', function (Blueprint $table) {
            $table->dropColumn(['qbo_income_account_id', 'qbo_expense_account_id']);
        });
    }
};
