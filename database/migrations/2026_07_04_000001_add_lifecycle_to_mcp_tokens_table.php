<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Token lifecycle: draft -> active -> paused -> revoked. State is derived from
 * timestamps (no enum). Only an active token authenticates.
 *
 * Backfill: every existing token was born active under the old model, so set
 * activated_at = created_at. Without this, the born-safe auth change would stop
 * every live token from authenticating the moment this deploys.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('mcp_tokens', 'activated_at')) {
            Schema::table('mcp_tokens', function (Blueprint $table) {
                $table->timestamp('activated_at')->nullable()->after('require_explicit_client_scope');
            });
        }

        if (! Schema::hasColumn('mcp_tokens', 'paused_at')) {
            Schema::table('mcp_tokens', function (Blueprint $table) {
                $table->timestamp('paused_at')->nullable()->after('activated_at');
            });
        }

        DB::table('mcp_tokens')
            ->whereNull('activated_at')
            ->update(['activated_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('mcp_tokens', 'paused_at')) {
            Schema::table('mcp_tokens', function (Blueprint $table) {
                $table->dropColumn('paused_at');
            });
        }

        if (Schema::hasColumn('mcp_tokens', 'activated_at')) {
            Schema::table('mcp_tokens', function (Blueprint $table) {
                $table->dropColumn('activated_at');
            });
        }
    }
};
