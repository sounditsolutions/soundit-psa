<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('mcp_tokens', 'ai_actor')) {
            Schema::table('mcp_tokens', function (Blueprint $table) {
                $table->boolean('ai_actor')->default(false)->after('directive');
            });
        }

        if (! Schema::hasColumn('mcp_tokens', 'require_explicit_client_scope')) {
            Schema::table('mcp_tokens', function (Blueprint $table) {
                $table->boolean('require_explicit_client_scope')->default(false)->after('ai_actor');
            });
        }

        DB::table('mcp_tokens')
            ->whereRaw('LOWER(label) = ?', ['chet'])
            ->update([
                'ai_actor' => true,
                'require_explicit_client_scope' => true,
            ]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('mcp_tokens', 'require_explicit_client_scope')) {
            Schema::table('mcp_tokens', function (Blueprint $table) {
                $table->dropColumn('require_explicit_client_scope');
            });
        }

        if (Schema::hasColumn('mcp_tokens', 'ai_actor')) {
            Schema::table('mcp_tokens', function (Blueprint $table) {
                $table->dropColumn('ai_actor');
            });
        }
    }
};
