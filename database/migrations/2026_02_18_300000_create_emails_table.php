<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emails', function (Blueprint $table) {
            $table->id();
            $table->string('graph_id')->unique();
            $table->string('internet_message_id')->nullable()->index();
            $table->string('conversation_id')->nullable()->index();
            $table->string('in_reply_to')->nullable();
            $table->string('direction', 10)->default('inbound');
            $table->string('from_address')->index();
            $table->string('from_name')->nullable();
            $table->json('to_recipients')->nullable();
            $table->json('cc_recipients')->nullable();
            $table->string('subject');
            $table->string('body_preview', 500)->nullable();
            $table->longText('body_html')->nullable();
            $table->boolean('has_attachments')->default(false);
            $table->string('importance', 10)->default('normal');
            $table->timestamp('received_at')->index();
            $table->boolean('is_read')->default(false)->index();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->foreignId('ticket_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emails');
    }
};
