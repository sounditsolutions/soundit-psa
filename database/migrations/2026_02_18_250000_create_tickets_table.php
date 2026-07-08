<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('halo_id')->unique()->nullable();
            $table->timestamp('halo_synced_at')->nullable();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('people')->nullOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('parent_ticket_id')->nullable()->constrained('tickets')->restrictOnDelete();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject');
            $table->text('description')->nullable();
            $table->text('resolution')->nullable();
            $table->string('source', 30)->default('manual');
            $table->string('type', 30);
            $table->string('status', 30);
            $table->string('priority', 10);
            $table->tinyInteger('priority_order')->default(3);
            $table->string('category', 100)->nullable();
            $table->string('subcategory', 100)->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('sla_breach_recorded_at')->nullable();
            $table->unsignedInteger('total_pending_minutes')->default(0);
            $table->timestamp('pending_since')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['assignee_id', 'status', 'priority_order']);
            $table->index('client_id');
            $table->index('status');
            $table->index('type');
            $table->index('opened_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
