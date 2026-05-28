<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ninja_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->nullable()->constrained('assets')->nullOnDelete();
            $table->unsignedInteger('ninja_device_id')->index();
            $table->string('ninja_alert_uid')->unique();
            $table->string('severity', 20)->nullable();
            $table->string('condition_name')->nullable();
            $table->text('message')->nullable();
            $table->string('status', 20)->default('active');
            $table->foreignId('ticket_id')->nullable()->constrained('tickets')->nullOnDelete();
            $table->timestamp('fired_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['asset_id', 'status']);
            $table->index(['ticket_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ninja_alerts');
    }
};
