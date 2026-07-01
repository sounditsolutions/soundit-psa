<?php

use App\Models\McpToken;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('label', 100)->unique();
            $table->string('token_hash', 64);
            $table->string('token_prefix', 32)->nullable();
            $table->json('tools')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index('token_hash');
            $table->index('revoked_at');
        });

        McpToken::importLegacyBlob();
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_tokens');
    }
};
