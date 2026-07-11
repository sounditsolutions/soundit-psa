<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_briefings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // The operator-local date this briefing covers. Paired with user_id in
            // a unique index — this is the idempotency key ("won't re-send today").
            $table->date('briefing_date');
            // When the email actually went out. Null means the row was claimed but
            // the send failed (kept so we don't re-attempt / double-send same day).
            $table->timestamp('sent_at')->nullable();
            // Snapshot of what the briefing contained — for auditing / reporting.
            $table->unsignedInteger('open_ticket_count')->default(0);
            $table->unsignedInteger('alert_count')->default(0);
            $table->unsignedInteger('voicemail_count')->default(0);
            $table->unsignedInteger('sla_risk_count')->default(0);
            $table->boolean('ai_suggestions_included')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'briefing_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_briefings');
    }
};
