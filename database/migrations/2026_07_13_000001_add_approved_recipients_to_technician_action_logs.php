<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durable pre-send recipient record (psa-w4e0 security revise).
 *
 * The FINAL operator-approved To/CC (and its outside-known-contacts subset) previously
 * existed nowhere durable before the external send: the summary is counts-only by
 * design, TechnicianRun.proposed_meta holds the stage-time proposal, and the Email row
 * is written only after Graph accepts. Persist the resolved audience on the append-only
 * audit row — committed in the gate transaction BEFORE the send — so a failed delivery
 * still leaves the exact attempted recipients on record (exfil forensics).
 *
 * Nullable: only email-sending dispatches carry recipients. Adding a column is DDL
 * (ALTER TABLE) and is NOT blocked by the table's append-only BEFORE UPDATE/DELETE row
 * triggers (mirrors the approver_user_id migration, psa-uohr).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('technician_action_logs', function (Blueprint $table) {
            $table->json('approved_recipients')->nullable()->after('summary');
        });
    }

    public function down(): void
    {
        Schema::table('technician_action_logs', function (Blueprint $table) {
            $table->dropColumn('approved_recipients');
        });
    }
};
