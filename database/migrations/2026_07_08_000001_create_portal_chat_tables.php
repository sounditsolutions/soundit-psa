<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Client-portal AI chatbot conversation storage (psa-2ab).
 *
 * Mirrors the staff assistant_conversations/messages tables but is keyed to a
 * portal Person + Client rather than a staff User. Conversations are hard-bound
 * to a client_id so every downstream tool call can be scoped to that one client.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_chat_conversations', function (Blueprint $table) {
            $table->id();
            // The client the whole conversation is scoped to. Deleting the client
            // removes its portal chat history.
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            // The portal contact who started the conversation. Nullable so history
            // survives a contact being removed (deactivation is the common case).
            $table->foreignId('person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->string('title', 255)->nullable();
            $table->unsignedInteger('total_input_tokens')->default(0);
            $table->unsignedInteger('total_output_tokens')->default(0);
            $table->timestamps();

            $table->index(['client_id', 'person_id', 'updated_at']);
        });

        Schema::create('portal_chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')
                ->constrained('portal_chat_conversations')
                ->cascadeOnDelete();
            $table->string('role', 20); // 'user' | 'assistant'
            $table->longText('content');
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->timestamps();

            $table->index('conversation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_chat_messages');
        Schema::dropIfExists('portal_chat_conversations');
    }
};
