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
        Schema::table('ticket_notes', function (Blueprint $table) {
            $table->softDeletes();
            $table->timestamp('edited_at')->nullable()->after('noted_at');
            $table->foreignId('edited_by')->nullable()->after('edited_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ticket_notes', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropForeign(['edited_by']);
            $table->dropColumn(['edited_at', 'edited_by']);
        });
    }
};
