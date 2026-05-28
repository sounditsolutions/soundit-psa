<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_person', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->string('assignment_source', 10)->default('auto');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['asset_id', 'person_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_person');
    }
};
