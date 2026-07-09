<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pandadoc_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('pandadoc_id', 64)->unique();
            $table->string('name');
            $table->string('status', 20)->default('draft');
            $table->string('template_id', 64)->nullable();
            $table->string('template_name')->nullable();
            $table->string('recipient_email')->nullable();
            $table->string('recipient_name')->nullable();
            $table->string('signed_disk_path')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('contract_id');
            $table->index(['contract_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pandadoc_documents');
    }
};
