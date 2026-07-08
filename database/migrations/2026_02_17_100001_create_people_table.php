<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('people', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('halo_id')->unique();
            $table->foreignId('client_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('email')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('phone_display', 30)->nullable();
            $table->string('mobile', 20)->nullable();
            $table->string('mobile_display', 30)->nullable();
            $table->string('job_title')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamp('halo_synced_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('phone');
            $table->index('mobile');
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('people');
    }
};
