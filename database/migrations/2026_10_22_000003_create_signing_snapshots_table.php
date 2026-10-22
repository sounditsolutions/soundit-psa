<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signing_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('signable_document_id')->constrained()->restrictOnDelete();
            $table->string('role');
            $table->json('lines');
            $table->char('lines_sha256', 64);
            $table->char('pdf_sha256', 64);
            $table->string('signer_name');
            $table->string('signer_email');
            $table->string('typed_name');
            $table->string('ip', 45);
            $table->string('user_agent', 512);
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamp('signed_at');
            $table->unique(['signable_document_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signing_snapshots');
    }
};
