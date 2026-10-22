<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signable_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('agreement_template_id')->constrained()->restrictOnDelete();
            $table->string('kind');
            $table->unsignedInteger('version');
            $table->unsignedInteger('lines_version');
            $table->string('status')->default('draft');
            $table->string('pdf_path');
            $table->char('pdf_sha256', 64);
            $table->json('rendered_lines');
            $table->char('rendered_lines_sha256', 64);
            $table->boolean('requires_countersign');
            $table->string('recipient_name');
            $table->string('recipient_email');
            $table->char('token_hash', 64)->nullable()->unique();
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['contract_id', 'kind', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signable_documents');
    }
};
