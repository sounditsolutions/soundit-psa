<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wiki_page_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained('wiki_pages')->cascadeOnDelete();
            $table->longText('body_md'); // snapshot of the page body AFTER this write
            $table->json('meta')->nullable();
            $table->string('author_type', 10); // WikiAuthorType
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('change_summary', 255);
            $table->json('source_refs')->nullable();
            $table->timestamps(); // revision rows are written once; the model disables UPDATED_AT
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wiki_page_revisions');
    }
};
