<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #1133 — record WHETHER the create response proved the rule's scope.
 *
 * `added_for` on the 201 is the only scope evidence this API ever gives
 * (measured 2026-09-01: the stored row is normalised to
 * organization_level:true / customer_id:null, so no later read-back can tell a
 * tenant rule from a partner-wide one). That evidence exists for exactly one
 * instant — the moment the 201 is read — so it is recorded here or it is lost.
 *
 * Without it, a row that landed `unresolved` cannot say WHICH thing was
 * missing: the scope proof, or only the upstream rule id.
 * MeshAllowRuleReaper::settlePermanent() needs that distinction, because a
 * permanent rule has no expiry to settle it and the executor's duplicate brake
 * refuses every later allow rule for its sender while it sits unsettled.
 * Treating the two alike either promotes a rule whose scope was never proved,
 * or wedges a sender forever for a rule Mesh confirmed and the PSA can name.
 *
 * NOT NULL with a false default: unknown must read as not-proved, so every
 * pre-existing row — and any writer that forgets to set it — fails closed.
 * Deliberately a boolean and not a timestamp: the MariaDB
 * ON UPDATE CURRENT_TIMESTAMP trap the create migration documents applies to
 * TIMESTAMP columns, and both the executor and the reaper update this row
 * after insert.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mesh_allow_rules', function (Blueprint $table) {
            $table->boolean('scope_proved')->default(false);
        });
    }

    /**
     * Reversing this only drops a record of what the server attested; it
     * cannot make an unproved rule proved, and it deletes no allow rule.
     */
    public function down(): void
    {
        Schema::table('mesh_allow_rules', function (Blueprint $table) {
            $table->dropColumn('scope_proved');
        });
    }
};
