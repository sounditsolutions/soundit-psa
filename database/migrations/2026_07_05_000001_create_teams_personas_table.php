<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teams_personas', function (Blueprint $table) {
            $table->id();
            $table->string('persona_key')->unique();
            $table->string('display_name');
            $table->text('role_blurb')->nullable();
            $table->string('avatar_ref')->nullable();
            $table->string('bot_app_id')->unique();
            $table->text('bot_client_secret')->nullable();
            $table->string('tenant_id')->nullable();
            $table->string('mcp_token_label')->nullable()->index();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('conversation_refs')->nullable();
            $table->boolean('enabled')->default(false)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teams_personas');
    }
};
