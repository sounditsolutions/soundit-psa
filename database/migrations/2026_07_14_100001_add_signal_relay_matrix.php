<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alerts-Hub relay matrix (psa-0j6i) — the two schema additions the catalog × per-token
 * relay matrix needs. Both ship dormant and backward-compatible.
 *
 * 1. signal_routes.managed_token_label — the discriminator that separates operator-authored
 *    routes (NULL, every route today incl. the legacy seed — never touched by the matrix)
 *    from matrix-owned per-token relay routes (non-null = the token the route relays to).
 *    Reusing the existing route model means one delivery path, inheriting suppression, the
 *    rate cap, cooldown, revoke-cascade and poll_signals-consumability for free.
 *
 * 2. signal_event_type_settings — the D4 global per-type master toggle overlay. Keyed by
 *    type_key; absence = enabled (so nothing changes until an operator disables a type).
 *    Kept OUT of SignalEventTypes.php on purpose: that catalog array is asserted
 *    byte-for-byte by a test and must stay static.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signal_routes', function (Blueprint $table) {
            // Nullable UNIQUE: enforces the one matrix-owned relay route per MCP token
            // invariant at the DB level (a concurrent first-toggle can't create a duplicate
            // managed route -> no duplicate MCP inbox deliveries / ambiguous nudge reads).
            // Both MariaDB and SQLite allow MANY NULLs in a unique index, so every
            // operator-authored route (managed_token_label IS NULL) is unaffected.
            $table->string('managed_token_label')->nullable()->after('label');
            $table->unique('managed_token_label');
        });

        Schema::create('signal_event_type_settings', function (Blueprint $table) {
            $table->id();
            $table->string('type_key')->unique();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signal_event_type_settings');

        Schema::table('signal_routes', function (Blueprint $table) {
            $table->dropUnique(['managed_token_label']);
            $table->dropColumn('managed_token_label');
        });
    }
};
