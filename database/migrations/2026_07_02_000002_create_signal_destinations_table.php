<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('signal_destinations')) {
            Schema::create('signal_destinations', function (Blueprint $table) {
                $table->id();
                $table->string('label', 100);
                $table->string('type', 20);
                $table->text('address')->nullable();
                $table->foreignId('mcp_token_id')->nullable()->constrained('mcp_tokens')->nullOnDelete();
                $table->text('wake_url')->nullable();
                $table->text('wake_secret')->nullable();
                $table->text('secret')->nullable();
                $table->boolean('enabled')->default(true);
                $table->timestamp('last_delivery_at')->nullable();
                $table->string('last_delivery_status', 50)->nullable();
                $table->string('last_error', 500)->nullable();
                $table->timestamps();

                $table->index('type');
                $table->index('enabled');
            });
        } elseif (! Schema::hasColumn('signal_destinations', 'mcp_token_id')) {
            Schema::table('signal_destinations', function (Blueprint $table) {
                $table->foreignId('mcp_token_id')
                    ->nullable()
                    ->after('address')
                    ->constrained('mcp_tokens')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('signal_destinations');
    }
};
