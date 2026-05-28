<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_notes', function (Blueprint $table) {
            $table->foreignId('contract_id')->nullable()->after('ticket_id')
                ->constrained('contracts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ticket_notes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('contract_id');
        });
    }
};
