<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->string('attachable_type', 50)->nullable();
            $table->unsignedBigInteger('attachable_id')->nullable();
            $table->string('filename');
            $table->string('original_filename');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->string('storage_path');
            $table->boolean('is_inline')->default(false);
            $table->string('content_id')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['attachable_type', 'attachable_id']);
            $table->index('content_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
