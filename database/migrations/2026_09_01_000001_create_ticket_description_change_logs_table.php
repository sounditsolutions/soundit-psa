<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #992 — the durable record of every tickets.description rewrite.
 *
 * A ticket description is CLIENT-VISIBLE and, until now, freely overwritable
 * with no trace from the web UI (the MCP path wrote a technician_action_logs
 * row; the web path wrote nothing). Jeeves's audit-seam ruling
 * (2026-09-01, issue #992) puts the record at the MODEL level so every
 * surface — web, MCP, email pipeline, anything added later — is captured
 * without opting in, rather than closing the instance one controller at a time.
 *
 * Before AND after text are stored in full: the whole reason the log exists is
 * that an edit destroys the prior value, so a diff that only names the fields
 * changed would not answer "what did it say before". Columns mirror
 * tickets.description / tickets.description_html (both longText) exactly so
 * the log can never truncate what the ticket itself could hold.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_description_change_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->longText('previous_description')->nullable();
            $table->longText('new_description')->nullable();
            // The pre-rendered HTML the replaced row was carrying (an
            // email-ingested description, inline images and all). The edit
            // clears or overwrites that column — see TicketObserver::updating()
            // — so this copy is the only surviving one; without it the clear is
            // an unrecorded destruction of exactly the rendering the client had
            // been seeing (Jeeves, PR #993 review note, 2026-09-01). Null when
            // the replaced row carried no HTML, which doubles as the "was the
            // prior rendering email HTML" flag.
            $table->longText('previous_description_html')->nullable();
            $table->string('source', 20); // staff | system
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['ticket_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_description_change_logs');
    }
};
