<?php

use App\Models\McpToken;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mcp_tokens')) {
            Schema::create('mcp_tokens', function (Blueprint $table) {
                $table->id();
                $table->string('label', 100)->unique();
                $table->string('token_hash', 64);
                $table->string('token_prefix', 32)->nullable();
                $table->json('tools')->nullable();
                $table->text('directive')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->timestamps();

                $table->index('token_hash');
                $table->index('revoked_at');
            });
        } else {
            if (! Schema::hasColumn('mcp_tokens', 'token_prefix')) {
                Schema::table('mcp_tokens', function (Blueprint $table) {
                    $table->string('token_prefix', 32)->nullable()->after('token_hash');
                });
            }

            if (! Schema::hasColumn('mcp_tokens', 'directive')) {
                Schema::table('mcp_tokens', function (Blueprint $table) {
                    $table->text('directive')->nullable()->after('tools');
                });
            }

            if (! Schema::hasColumn('mcp_tokens', 'last_used_at')) {
                Schema::table('mcp_tokens', function (Blueprint $table) {
                    $table->timestamp('last_used_at')->nullable()->after('directive');
                });
            }

            if (! Schema::hasColumn('mcp_tokens', 'revoked_at')) {
                Schema::table('mcp_tokens', function (Blueprint $table) {
                    $table->timestamp('revoked_at')->nullable()->after('last_used_at');
                });
            }
        }

        McpToken::importLegacyBlob();
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_tokens');
    }
};
