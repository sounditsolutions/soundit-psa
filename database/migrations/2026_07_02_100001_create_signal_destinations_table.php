<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signal_destinations', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->string('type', 20);
            $table->text('address')->nullable();
            $table->string('mcp_token_label')->nullable();
            $table->text('wake_url')->nullable();
            $table->text('wake_secret')->nullable();
            $table->text('secret')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_delivery_at')->nullable();
            $table->string('last_delivery_status')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();

            $table->index(['type', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signal_destinations');
    }
};
