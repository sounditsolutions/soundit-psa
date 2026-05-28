<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('triage_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->string('mode', 20)->default('triage'); // triage, review
            $table->string('status', 20)->default('pending'); // pending, running, completed, failed
            $table->json('stages_completed')->nullable();
            $table->json('stage_results')->nullable();
            $table->json('errors')->nullable();
            $table->string('triggered_by', 20)->default('manual'); // auto, manual, cron
            $table->foreignId('triggered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->json('ai_tokens_used')->nullable();
            $table->timestamps();

            $table->index(['ticket_id', 'status']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('triage_runs');
    }
};
