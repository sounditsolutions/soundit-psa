<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wiki_pages', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 10); // WikiScope
            $table->foreignId('client_id')->nullable()->constrained('clients')->cascadeOnDelete();
            $table->string('slug', 255); // path-style, e.g. runbooks/user-onboarding
            $table->string('title', 255);
            $table->string('kind', 20); // WikiPageKind
            $table->foreignId('parent_page_id')->nullable()->constrained('wiki_pages')->nullOnDelete();
            $table->longText('body_md');
            $table->json('meta')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->string('created_by_type', 10); // WikiAuthorType
            $table->timestamps();

            // NULL client_id rows (global scope) are NOT deduped by this index under
            // MySQL/MariaDB NULL semantics — WikiPageService enforces global uniqueness.
            $table->unique(['scope', 'client_id', 'slug']);
            $table->index(['client_id', 'kind']);
        });

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            Schema::table('wiki_pages', function (Blueprint $table) {
                $table->fullText(['title', 'body_md'], 'wiki_pages_fulltext');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wiki_pages');
    }
};
