<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams_personas', function (Blueprint $table) {
            $table->dropIndex(['mcp_token_label']);
            $table->unique('mcp_token_label');
        });
    }

    public function down(): void
    {
        Schema::table('teams_personas', function (Blueprint $table) {
            $table->dropUnique(['mcp_token_label']);
            $table->index('mcp_token_label');
        });
    }
};
