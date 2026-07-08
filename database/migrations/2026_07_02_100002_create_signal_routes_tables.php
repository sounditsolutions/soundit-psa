<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signal_routes', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->json('event_filter');
            $table->boolean('enabled')->default(false);
            $table->unsignedInteger('cooldown_seconds')->default(300);
            $table->timestamps();

            $table->index('enabled');
        });

        Schema::create('signal_route_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('route_id')->constrained('signal_routes')->cascadeOnDelete();
            $table->unsignedInteger('step_order');
            $table->foreignId('destination_id')->constrained('signal_destinations');
            $table->unsignedInteger('wait_for_ack_seconds')->nullable();
            $table->unsignedInteger('resolve_within_seconds')->nullable();
            $table->boolean('non_suppressible')->default(false);

            $table->index(['route_id', 'step_order']);
            $table->index('destination_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signal_route_steps');
        Schema::dropIfExists('signal_routes');
    }
};
