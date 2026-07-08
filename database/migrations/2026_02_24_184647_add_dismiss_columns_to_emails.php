<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            $table->timestamp('dismissed_at')->nullable()->after('is_read');
            $table->foreignId('dismissed_by')->nullable()->constrained('users')->nullOnDelete()->after('dismissed_at');
        });
    }

    public function down(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            $table->dropForeign(['dismissed_by']);
            $table->dropColumn(['dismissed_at', 'dismissed_by']);
        });
    }
};
