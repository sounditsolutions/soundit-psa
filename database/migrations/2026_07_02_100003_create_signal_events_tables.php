<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signal_events', function (Blueprint $table) {
            $table->id();
            $table->string('type_key')->index();
            $table->string('entity_type')->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('summary', 500);
            $table->json('context');
            $table->foreignId('origin_event_id')->nullable()->constrained('signal_events')->nullOnDelete();
            $table->timestamp('occurred_at')->index();

            $table->index(['type_key', 'occurred_at']);
            $table->index(['entity_type', 'entity_id']);
        });

        Schema::create('signal_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('signal_events')->cascadeOnDelete();
            $table->foreignId('route_id')->constrained('signal_routes')->cascadeOnDelete();
            $table->unsignedInteger('step_order');
            $table->foreignId('destination_id')->constrained('signal_destinations');
            $table->string('status', 20)->index();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('acked_at')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamps();

            $table->index(['destination_id', 'status']);
        });

        Schema::create('signal_inbox', function (Blueprint $table) {
            $table->id();
            $table->foreignId('destination_id')->constrained('signal_destinations');
            $table->foreignId('event_id')->constrained('signal_events')->cascadeOnDelete();
            $table->foreignId('delivery_id')->constrained('signal_deliveries')->cascadeOnDelete();
            $table->json('payload');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('acked_at')->nullable();

            $table->index('destination_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signal_inbox');
        Schema::dropIfExists('signal_deliveries');
        Schema::dropIfExists('signal_events');
    }
};
