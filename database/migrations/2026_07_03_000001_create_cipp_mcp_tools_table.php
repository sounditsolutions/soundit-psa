<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cipp_mcp_tools', function (Blueprint $table) {
            $table->id();
            $table->string('local_name')->unique();
            $table->string('upstream_name')->unique();
            $table->string('category')->nullable();
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
        Schema::dropIfExists('cipp_mcp_tools');
    }
};
