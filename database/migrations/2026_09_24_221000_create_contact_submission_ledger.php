<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_intake_identities', function (Blueprint $table) {
            $table->string('identity_hash', 64)->primary();
        });
        Schema::create('contact_submissions', function (Blueprint $table) {
            $table->id();
            $table->string('integration_id', 64);
            $table->uuid('submission_id');
            $table->uuid('receipt')->unique();
            $table->string('payload_hash', 64);
            $table->string('identity_hash', 64)->index();
            $table->mediumText('payload');
            $table->string('state', 24)->default('pending')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('conflicts')->default(0);
            $table->foreignId('ticket_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('ticket_note_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->unique(['integration_id', 'submission_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_submissions');
        Schema::dropIfExists('contact_intake_identities');
    }
};
