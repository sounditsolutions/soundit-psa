<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wiki_runs', function (Blueprint $table) {
            $table->id();
            $table->string('run_type', 20); // WikiRunType
            $table->string('subject_type', 50)->nullable(); // e.g. 'ticket', 'client'
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('source_content_hash', 64)->nullable(); // spec §4.1/§5.3 idempotency key
            $table->string('status', 15)->default('pending'); // WikiRunStatus
            $table->json('stages_completed')->nullable();
            $table->json('stage_results')->nullable();
            $table->json('errors')->nullable();
            $table->json('ai_tokens_used')->nullable();
            $table->string('triggered_by', 20)->nullable(); // auto|manual|cron
            $table->timestamps();

            $table->unique(['subject_type', 'subject_id', 'source_content_hash'], 'wiki_runs_idempotency_unique');
            $table->index(['run_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wiki_runs');
    }
};
