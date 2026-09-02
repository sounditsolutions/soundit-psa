<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #1133 — expiry becomes caller-chosen, including permanent.
 *
 * The owner's ruling (2026-09-02): a hard-set 90-day lifetime is "a landmine
 * disguised as constraint". The caller now names the expiry, and one of the
 * answers it may give is "never".
 *
 * NULL is that answer. Deliberately a null and not a far-future sentinel date:
 * a sentinel is a date the reaper would eventually act on, and on the approval
 * card it would read as an ordinary expiry rather than as the permanent hole
 * in a customer's mail filtering that it is. NULL cannot be misread by either.
 *
 * Still `dateTime`, NOT `timestamp` — the original migration's reasoning is
 * unchanged and is why the column type is restated here: on MariaDB with
 * explicit_defaults_for_timestamp off, the first NOT NULL TIMESTAMP column
 * with no explicit default silently acquires ON UPDATE CURRENT_TIMESTAMP, and
 * both the executor and the reaper update this row after insert. Verified on
 * the production engine 2026-09-02: `expires_at` is `datetime`, extra empty.
 * A ->change() re-emits the column definition in full, so leaving the type out
 * would be the way that guarantee gets lost.
 *
 * No data migration: the production row count for this table was 0 when the
 * ruling was made and is re-verified at build time. Existing rows, if any,
 * keep their dates — nothing here makes an already-created rule permanent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mesh_allow_rules', function (Blueprint $table) {
            $table->dateTime('expires_at')->nullable()->change();
        });
    }

    /**
     * Reversing this cannot be lossless: a permanent rule has no date to
     * restore, and inventing one would hand the reaper a customer's live allow
     * rule to delete on a date nobody chose. The rollback therefore refuses
     * while any permanent row exists, rather than quietly manufacturing an
     * expiry for it.
     */
    public function down(): void
    {
        $permanent = \Illuminate\Support\Facades\DB::table('mesh_allow_rules')->whereNull('expires_at')->count();

        if ($permanent > 0) {
            throw new \RuntimeException(
                "Cannot roll back: {$permanent} mesh_allow_rules row(s) are permanent (NULL expires_at). "
                .'Removing those rules upstream, or setting an explicit expiry on them, is a decision for a human.'
            );
        }

        Schema::table('mesh_allow_rules', function (Blueprint $table) {
            $table->dateTime('expires_at')->nullable(false)->change();
        });
    }
};
