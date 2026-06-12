<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wiki_facts', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 10); // WikiScope (denormalized for retrieval queries)
            $table->foreignId('client_id')->nullable()->constrained('clients')->cascadeOnDelete();
            $table->foreignId('page_id')->constrained('wiki_pages')->cascadeOnDelete();
            $table->string('section_anchor', 100);
            $table->string('subject_key', 255);
            $table->text('statement');
            $table->string('status', 15)->default('unverified'); // WikiFactStatus
            $table->boolean('pinned')->default(false);
            $table->string('volatility', 10)->default('durable'); // WikiFactVolatility
            $table->string('source_type', 10); // WikiFactSource
            $table->json('source_refs');
            $table->decimal('confidence', 3, 2)->nullable();
            $table->timestamp('last_affirmed_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('disputed_with_fact_id')->nullable()->constrained('wiki_facts')->nullOnDelete();
            $table->foreignId('superseded_by_fact_id')->nullable()->constrained('wiki_facts')->nullOnDelete();
            $table->json('dismissed_evidence')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'status']);
            $table->index(['page_id', 'section_anchor']);
            $table->index(['client_id', 'subject_key']);
            $table->index('subject_key');
        });

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            Schema::table('wiki_facts', function (Blueprint $table) {
                $table->fullText('statement', 'wiki_facts_fulltext');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wiki_facts');
    }
};
