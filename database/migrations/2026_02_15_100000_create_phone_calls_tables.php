<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sip_endpoints', function (Blueprint $table) {
            $table->id();
            $table->string('sip_uri')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('label', 100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('phone_calls', function (Blueprint $table) {
            $table->id();
            $table->string('call_uuid')->unique();
            $table->string('direction', 10)->default('inbound');
            $table->string('from_number', 30);
            $table->string('to_number', 30)->nullable();
            $table->string('sip_endpoint')->nullable();
            $table->string('status', 20)->default('ringing');
            $table->foreignId('answered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('halo_client_id')->nullable();
            $table->string('halo_client_name')->nullable();
            $table->unsignedInteger('halo_ticket_id')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration')->nullable();
            $table->text('recording_url')->nullable();
            $table->unsignedInteger('recording_duration')->nullable();
            $table->timestamp('followed_up_at')->nullable();
            $table->foreignId('followed_up_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('halo_client_id');
            $table->index('started_at');
            $table->index('status');
            $table->index(['status', 'followed_up_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phone_calls');
        Schema::dropIfExists('sip_endpoints');
    }
};
