<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('screenconnect_webhooks', function (Blueprint $table) {
            $table->id();
            $table->string('event_type')->index();
            $table->string('session_id', 36)->nullable()->index();
            $table->json('payload');
            $table->string('status')->default('pending')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('screenconnect_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->string('session_id', 36)->index();
            $table->string('event_type', 50)->index();
            $table->timestamp('event_time')->nullable();
            $table->string('host', 100)->nullable();
            $table->text('data')->nullable();
            $table->string('participant', 100)->nullable();
            $table->string('network_address', 45)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('screenconnect_events');
        Schema::dropIfExists('screenconnect_webhooks');
    }
};
