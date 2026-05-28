<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_notes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('halo_note_id')->unique()->nullable();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('author_name')->nullable();
            $table->text('body');
            $table->string('note_type', 20)->default('note');
            $table->boolean('is_private')->default(false);
            $table->string('status_from', 30)->nullable();
            $table->string('status_to', 30)->nullable();
            $table->unsignedInteger('time_minutes')->nullable();
            $table->timestamp('noted_at')->nullable();
            $table->timestamps();

            $table->index('ticket_id');
            $table->index('noted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_notes');
    }
};
