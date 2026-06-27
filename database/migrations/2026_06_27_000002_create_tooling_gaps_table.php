<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * tooling_gaps — durable backlog of "tooling gaps" the AI agent encountered.
 *
 * Each row separates an ABSTRACT, sanitized `capability_gap` (forwardable;
 * safe to share upstream) from instance-private `evidence` (the specific ticket,
 * correction text — never forwarded). v1 stores both but forwards nothing;
 * the multi-MSP forwarding seam is schema-only in this increment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tooling_gaps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->text('capability_gap');           // ABSTRACT, sanitized, forwardable
            $table->text('evidence')->nullable();     // instance-specific, private/local
            $table->string('classification', 20);     // tool_missing | tool_unused
            $table->string('source', 20);             // correction | agent
            $table->string('status', 20)->default('open'); // open | triaged | resolved | wontfix
            $table->text('agent_note')->nullable();   // optional free note (esp. agent-sourced)
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tooling_gaps');
    }
};
