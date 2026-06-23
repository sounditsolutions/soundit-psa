<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * technician_runs — the per-ticket run-state machine (spec §4.4). A unique
 * idempotency key (ticket_id + action_type + content_hash) prevents a
 * double-send under poll re-import / job retry. awaiting_approval lives HERE,
 * not on the TicketStatus enum.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('technician_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->string('action_type');      // e.g. send_ack
            $table->string('content_hash', 64); // sha256 of the action payload
            $table->string('state', 20)->default('gathering');
            $table->timestamps();

            // Idempotency key — the heart of "safe to re-run" (spec §4.4/§14).
            $table->unique(['ticket_id', 'action_type', 'content_hash'], 'technician_runs_idempotency');
            $table->index('state');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('technician_runs');
    }
};
