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
            $table->foreignId('ticket_note_id')->nullable()->after('invoice_id')
                ->constrained('ticket_notes')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('prepay_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ticket_note_id');
        });
    }
};
