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
        Schema::table('phone_calls', function (Blueprint $table) {
            $table->foreignId('ticket_id')->nullable()->constrained()->nullOnDelete()->after('halo_ticket_id');
            $table->text('notes')->nullable()->after('ticket_id');
        });
    }

    public function down(): void
    {
        Schema::table('phone_calls', function (Blueprint $table) {
            $table->dropForeign(['ticket_id']);
            $table->dropColumn(['ticket_id', 'notes']);
        });
    }
};
