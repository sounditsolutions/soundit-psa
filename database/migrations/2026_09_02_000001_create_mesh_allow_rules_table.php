<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #1018 — the PSA-side record of every Mesh allow rule this system created,
 * and the ONLY thing that will ever expire one.
 *
 * The load-bearing measurement (production Partner Hub, 2026-09-01): Mesh's
 * own `date_expiry` is DISPLAY ONLY. A rule whose expiry had passed was still
 * `active: true`, its `date_modified` untouched, its detail GET still 200 at
 * +9m14s. Nothing upstream reaps it. So an allow rule created with a
 * "90-day" lifetime and no PSA row is a PERMANENT hole in a customer's mail
 * filtering that nobody is tracking — which is the whole reason this table
 * exists rather than the verb writing an audit line and walking away.
 * MeshAllowRuleReaper reads these rows and issues the DELETE.
 *
 * `state` is the reaper's work queue, not decoration:
 *   active      — live upstream, expires_at in the future.
 *   unresolved  — created (the 201 proved scope) but the rule id could not be
 *                 recovered by re-read. Reapable only after the id resolves;
 *                 the reaper retries the match. Surfaced as a fault, never
 *                 silently dropped (#1018 criterion 8).
 *   reaped      — DELETE issued AND a detail GET returned 404. A 200 on the
 *                 DELETE alone does NOT earn this state (#1018 criterion 3).
 *   reap_failed — the delete or its post-condition did not prove absence.
 *                 Retried; never treated as gone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mesh_allow_rules', function (Blueprint $table) {
            $table->id();

            // The PSA client whose Mesh tenant the rule was written against.
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();

            // The originating ticket. nullOnDelete, NOT cascade: deleting a
            // ticket must never delete the row that remembers a live upstream
            // allow rule, or the rule outlives its only reaper.
            $table->foreignId('ticket_id')->nullable()->constrained()->nullOnDelete();

            // The approval that authorised it, for the same reason.
            $table->foreignId('technician_run_id')->nullable()->constrained('technician_runs')->nullOnDelete();

            // The Mesh tenant uuid the POST was scoped to — copied from the
            // 201's `added_for`, i.e. what the SERVER said it applied to, not
            // what we asked for.
            $table->string('mesh_customer_id', 64);

            $table->string('sender', 255);

            // The PSA-generated label written into the Mesh `comment` field.
            // It is the re-read match key, so it is stored verbatim.
            $table->string('comment', 255);

            // Nullable because the 201 body carries NO id (measured); the id
            // is recovered by re-reading the tenant's rule list and matching
            // sender + comment. Null means state = unresolved.
            $table->string('mesh_rule_id', 64)->nullable();

            $table->timestamp('expires_at');

            $table->string('state', 20)->default('active');

            // Who asked and who approved. actor label is the MCP client/agent
            // string; approver is the human who released the staged run.
            $table->string('created_by_actor', 255)->nullable();
            $table->foreignId('approver_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Whose identity the VENDOR audit trail records for this rule
            // (Mesh's `created_by`, which resolves to the API key owner's
            // mailbox, not a service account). Stored so the PSA record and
            // the vendor record can be reconciled later, and so the approval
            // text's attribution claim (#1018 criterion 6) is checkable after
            // the fact.
            $table->string('upstream_created_by', 255)->nullable();

            $table->timestamp('reaped_at')->nullable();
            $table->text('last_error')->nullable();

            $table->timestamps();

            // The reaper's only query: rows in a workable state whose expiry
            // has passed.
            $table->index(['state', 'expires_at']);
            $table->index('mesh_rule_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mesh_allow_rules');
    }
};
