<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qbo_webhooks', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type', 50);
            $table->string('entity_id', 50);
            $table->string('operation', 50);
            $table->string('realm_id', 50);
            $table->json('payload');
            $table->string('status', 20)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['entity_id', 'operation']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qbo_webhooks');
    }
};
