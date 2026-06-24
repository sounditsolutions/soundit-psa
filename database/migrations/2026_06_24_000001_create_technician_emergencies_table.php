<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('technician_emergencies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ticket_id')->index();      // representative ticket
            $table->unsignedBigInteger('client_id')->nullable()->index();
            $table->string('signature', 64)->index();              // storm-group key
            $table->unsignedTinyInteger('severity')->default(1);
            $table->json('reasons')->nullable();
            $table->string('detected_by', 16)->default('rules');   // rules|ai|both
            $table->string('state', 16)->default('open')->index();
            $table->unsignedInteger('escalation_step')->default(0);
            $table->unsignedBigInteger('current_target_user_id')->nullable();
            $table->json('ticket_ids')->nullable();                // storm members
            $table->timestamp('alerted_at')->nullable();
            $table->timestamp('last_pinged_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->unsignedBigInteger('acknowledged_by')->nullable();
            $table->timestamp('max_hold_sent_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['client_id', 'signature', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('technician_emergencies');
    }
};
