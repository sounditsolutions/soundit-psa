<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * so-0ftg Part 4 — the durable record of every tickets.category_id change.
 * Each row snapshots who moved a ticket to which taxonomy node, from which
 * node, and what the legacy free-text classification read said at that
 * moment. Phase 1 mines the rows where a human or agent moved a ticket AWAY
 * from the node the coarse triage mapping picked ("agent overrides") to
 * refine the mapping table.
 *
 * Node references are deliberately UNCONSTRAINED ids plus path-string
 * snapshots: a log must survive node renames and deletions that would
 * otherwise rewrite or cascade away the very history Phase 1 needs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_category_change_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('previous_category_id')->nullable();
            $table->unsignedBigInteger('new_category_id')->nullable();
            $table->string('previous_path', 500)->nullable();
            $table->string('new_path', 500)->nullable();
            // The legacy free-text pair on the ticket at change time — the
            // input the triage mapping resolved from (Phase-1 join key).
            $table->string('legacy_category', 100)->nullable();
            $table->string('legacy_subcategory', 100)->nullable();
            $table->string('source', 20); // triage | staff | system
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['ticket_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_category_change_logs');
    }
};
