<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('prepay_transactions', function (Blueprint $table) {
            $table->foreignId('phone_call_id')->nullable()->after('ticket_note_id')
                ->constrained('phone_calls')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('prepay_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('phone_call_id');
        });
    }
};
