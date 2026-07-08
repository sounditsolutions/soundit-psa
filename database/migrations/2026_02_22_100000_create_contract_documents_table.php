<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->restrictOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('original_filename');
            $table->string('disk_path');
            $table->string('mime_type', 100);
            $table->unsignedInteger('file_size');
            $table->longText('extracted_text')->nullable();
            $table->longText('ai_summary')->nullable();
            $table->string('summary_status', 20)->default('pending');
            $table->unsignedInteger('summary_tokens_used')->nullable();
            $table->timestamp('summarized_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('contract_id');
            $table->index(['contract_id', 'summary_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_documents');
    }
};
