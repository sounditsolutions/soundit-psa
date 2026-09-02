<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #1134 — record WHEN an operator removed a rule on purpose, as distinct from
 * when the reaper retired it at expiry.
 *
 * `reaped_at` already exists and is deliberately NOT reused. The two events
 * answer different questions and only one of them has a human behind it: a
 * reaped row is the PSA honouring a lifetime it set itself, while a removed
 * row is an approved `mesh_remove_allow_rule` — a named approver, a ticket and
 * an audit line. Collapsing them would make "why is this sender no longer
 * allowed?" unanswerable from the table, which is precisely the question a
 * technician asks when mail starts bouncing again mid-remediation.
 *
 * The removal STATE lives in `state` (MeshAllowRule::STATE_REMOVED); this
 * column is the instant. `state` is what keeps the row out of the reaper's
 * queue — scopeReapable() lists the states it works, so a new state is
 * excluded by construction rather than by a predicate somebody has to
 * remember to add.
 *
 * dateTime, not timestamp, for the reason the #1133 expiry migration records:
 * on MariaDB a TIMESTAMP column can acquire ON UPDATE CURRENT_TIMESTAMP, and
 * this row is updated after the value is set. CI is sqlite-only and would
 * never show it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mesh_allow_rules', function (Blueprint $table) {
            $table->dateTime('removed_at')->nullable()->after('reaped_at');
        });
    }

    /**
     * Reversing this drops the record of when a removal happened. It deletes
     * no rule and restores none: the removals themselves are in `state` and in
     * the technician action log.
     */
    public function down(): void
    {
        Schema::table('mesh_allow_rules', function (Blueprint $table) {
            $table->dropColumn('removed_at');
        });
    }
};
