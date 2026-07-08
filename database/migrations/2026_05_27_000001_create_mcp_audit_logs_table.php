<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('server_name', 50);              // "staff" / "portal" / future
            $table->string('method', 50);                   // "tools/call" / "tools/list" / "initialize"
            $table->string('tool_name', 100)->nullable();   // populated for tools/call
            $table->json('arguments')->nullable();
            $table->string('status', 20);                   // "success" / "error"
            $table->text('error_message')->nullable();
            $table->unsignedInteger('duration_ms');
            $table->string('actor_label', 100)->nullable(); // bot identity + Teams sender object_id if forwarded
            $table->ipAddress('source_ip')->nullable();
            $table->timestamps();

            $table->index('server_name');
            $table->index('tool_name');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_audit_logs');
    }
};
