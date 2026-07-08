<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wiki_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_page_id')->constrained('wiki_pages')->cascadeOnDelete();
            $table->foreignId('to_page_id')->nullable()->constrained('wiki_pages')->nullOnDelete(); // null = dead link
            $table->string('target_slug', 255);
            $table->string('anchor_text', 255)->nullable();
            $table->timestamps();

            $table->unique(['from_page_id', 'target_slug']);
            $table->index('to_page_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wiki_links');
    }
};
