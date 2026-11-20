<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #8459: records that a call's is_billable came from an explicit decision by a person or
 * agent (PhoneCallService::setBillable, the MCP link verbs, set_call_billable), so triage
 * reconciliation leaves it alone. Additive, defaulted false; existing rows are not backfilled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phone_calls', function (Blueprint $table) {
            $table->boolean('is_billable_explicit')->default(false)->after('is_billable');
        });
    }

    public function down(): void
    {
        Schema::table('phone_calls', function (Blueprint $table) {
            $table->dropColumn('is_billable_explicit');
        });
    }
};
