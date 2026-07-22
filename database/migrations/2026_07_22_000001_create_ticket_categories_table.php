<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * so-0ftg — the ITIL-informed ticket-category taxonomy (Category -> Subcategory
 * -> Item/Symptom, self-referential, depth <= 3) that carries the SOP served
 * inline on ticket detail. Additive: the legacy free-text tickets.category /
 * tickets.subcategory columns are left in place. Node CONTENT is authored by
 * Chet; gus runs this migration in prod.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            // self-referential tree; nullOnDelete so deleting a parent orphans
            // (does not cascade-destroy) its children — a safety default given
            // tickets may point at leaves.
            $table->foreignId('parent_id')->nullable()->constrained('ticket_categories')->nullOnDelete();
            $table->text('description')->nullable();
            $table->longText('sop_text')->nullable();                 // markdown, FULL
            $table->string('sop_status', 20)->default('none');        // SopStatus — SOFT HINT, never gates serving
            $table->string('record_type_hint', 20)->nullable();       // RecordTypeHint incident|request|mixed
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            // who last edited (human via UI, or the AI actor via MCP); nullable
            // so an FK-less seed/import does not break.
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source_runbook_slug', 255)->nullable();   // provenance for migrated wiki runbooks
            $table->timestamps();

            $table->index(['parent_id', 'sort_order']);
            $table->index(['is_active', 'sop_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_categories');
    }
};
