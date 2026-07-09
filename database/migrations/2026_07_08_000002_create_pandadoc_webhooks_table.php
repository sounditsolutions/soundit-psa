<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pandadoc_webhooks', function (Blueprint $table) {
            $table->id();
            $table->string('event_type', 64);
            $table->string('document_id', 64)->nullable();
            $table->string('document_status', 40)->nullable();
            $table->json('payload');
            $table->string('status', 20)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('document_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pandadoc_webhooks');
    }
};
