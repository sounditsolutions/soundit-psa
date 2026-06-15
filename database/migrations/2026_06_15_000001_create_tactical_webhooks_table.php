<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tactical_webhooks', function (Blueprint $table) {
            $table->id();
            $table->string('event')->index();
            $table->string('agent_id')->nullable()->index();
            $table->json('payload');
            $table->string('status')->default('pending')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('error')->nullable();
            // Idempotency / replay-protection key — Tactical can double-deliver and the
            // static webhook key is replayable. Unique so a replayed delivery collides.
            $table->string('dedup_key')->nullable()->unique();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tactical_webhooks');
    }
};
