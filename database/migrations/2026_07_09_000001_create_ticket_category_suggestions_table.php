<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI-suggested ticket categories awaiting staff approval (psa-xop / GitHub #80).
 *
 * The triage `set_ticket_category` tool records a pending row here instead of
 * writing the category straight onto the ticket. Staff approve (apply) or reject
 * from the approval queue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_category_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->string('category', 100);
            $table->string('subcategory', 100)->nullable();
            $table->string('status', 20)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index(['ticket_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_category_suggestions');
    }
};
