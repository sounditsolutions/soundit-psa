<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add `tool_name` to tooling_gaps.
 *
 * Introduced with the `tool_broken` classification: when an agent reports that an
 * EXISTING tool misbehaved, this column names which tool (e.g. "ninja_get_devices").
 * Abstract and forwardable — a tool name carries no instance-private data — so it
 * sits alongside `capability_gap` (the abstract symptom), not `evidence`.
 * Nullable: tool_missing / tool_unused reports leave it null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tooling_gaps', function (Blueprint $table) {
            $table->string('tool_name', 100)->nullable()->after('capability_gap');
        });
    }

    public function down(): void
    {
        Schema::table('tooling_gaps', function (Blueprint $table) {
            $table->dropColumn('tool_name');
        });
    }
};
