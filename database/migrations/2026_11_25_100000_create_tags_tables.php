<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Custom tags: one global vocabulary (`tags`) and one pivot per taggable entity
 * (person_tag, client_tag, asset_tag) with real foreign keys on both sides.
 *
 * Case-insensitive uniqueness is held by `name_key` (the trimmed, space-collapsed,
 * lower-cased name, set by App\Models\Tag) under a plain unique index. On sqlite
 * that index compares bytes. On MariaDB it compares under the column collation
 * (utf8mb4_unicode_ci by default), so there it is also accent-insensitive:
 * "café" and "cafe" collide on MariaDB but not on sqlite.
 *
 * name_key is 64 characters; App\Models\Tag refuses a name whose key is longer.
 *
 * Purely additive: no existing table is altered and no row is written.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64);
            $table->string('name_key', 64)->unique();
            $table->string('color', 16)->default('neutral');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('person_tag', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained('tags')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['person_id', 'tag_id']);
        });

        Schema::create('client_tag', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained('tags')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['client_id', 'tag_id']);
        });

        Schema::create('asset_tag', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained('tags')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['asset_id', 'tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_tag');
        Schema::dropIfExists('client_tag');
        Schema::dropIfExists('person_tag');
        Schema::dropIfExists('tags');
    }
};
