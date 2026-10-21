<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Carbon Connect MCP tool catalogue (card PEYvh4NL), shaped like
 * cipp_mcp_tools: one row per upstream tool, its local `carbon_*` name, its
 * schema and annotations, and the read_only / sensitive classification.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carbon_mcp_tools', function (Blueprint $table) {
            $table->id();
            $table->string('local_name')->unique();
            $table->string('upstream_name')->unique();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->json('input_schema')->nullable();
            $table->json('annotations')->nullable();
            $table->boolean('read_only')->default(false);
            $table->boolean('sensitive')->default(true);
            $table->boolean('active')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index(['active', 'sensitive', 'read_only']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carbon_mcp_tools');
    }
};
