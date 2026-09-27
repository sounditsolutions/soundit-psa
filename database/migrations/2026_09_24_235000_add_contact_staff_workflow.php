<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_submissions', function (Blueprint $table) {
            $table->foreignId('resolved_client_id')->nullable()->constrained('clients')->restrictOnDelete();
            $table->foreignId('resolved_person_id')->nullable()->constrained('people')->restrictOnDelete();
            $table->boolean('approve_new_prospect')->default(false);
            $table->json('related_ticket_ids')->nullable();
        });
        Schema::create('contact_intake_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_submission_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('action', 32);
            $table->text('reason');
            $table->timestamp('created_at');
        });
        Schema::create('contact_intake_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_submission_id')->constrained()->restrictOnDelete();
            $table->string('event', 32);
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['contact_submission_id', 'event']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_intake_notifications');
        Schema::dropIfExists('contact_intake_audits');
        Schema::table('contact_submissions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('resolved_client_id');
            $table->dropConstrainedForeignId('resolved_person_id');
            $table->dropColumn(['approve_new_prospect', 'related_ticket_ids']);
        });
    }
};
