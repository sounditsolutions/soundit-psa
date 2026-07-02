<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('mcp_tokens', 'directive')) {
            Schema::table('mcp_tokens', function (Blueprint $table) {
                $table->text('directive')->nullable()->after('tools');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('mcp_tokens', 'directive')) {
            Schema::table('mcp_tokens', function (Blueprint $table) {
                $table->dropColumn('directive');
            });
        }
    }
};
