<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('halo_id')->unique()->nullable();
            $table->unsignedBigInteger('ninja_id')->unique()->nullable();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('asset_type', 100)->nullable();
            $table->string('serial_number')->nullable();
            $table->string('hostname')->nullable();
            $table->string('os')->nullable();
            $table->string('cpu')->nullable();
            $table->decimal('ram_gb', 8, 2)->nullable();
            $table->string('disk_summary', 500)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('last_user')->nullable();
            $table->string('ninja_url', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('halo_synced_at')->nullable();
            $table->timestamp('ninja_synced_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('client_id');
            $table->index('serial_number');
            $table->index('hostname');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
