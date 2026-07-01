<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operator_inbox', function (Blueprint $table) {
            $table->id();
            $table->string('conversation_id');
            $table->foreignId('sender_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('text');
            $table->timestamp('ts');
            $table->boolean('direct_mention')->default(false);
            $table->boolean('authorized_steer')->default(false);
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'delivered_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operator_inbox');
    }
};
