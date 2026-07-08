<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P4 amendment B — the ONLY P4 schema change. Persist the failing/total check
 * counts so the asset card can render an at-a-glance health line (failing
 * checks · open alerts · pending patches) with ZERO live calls, and so
 * EndpointInsight has a snapshot base for the checks signal. Additive +
 * nullable (MariaDB-compatible): populated by the daily list-sync when the
 * agent-list payload carries a checks summary, and always by
 * syncDeviceDetail()/refresh-now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tactical_assets', function (Blueprint $table) {
            $table->unsignedInteger('checks_failing')->nullable()->after('has_patches_pending');
            $table->unsignedInteger('checks_total')->nullable()->after('checks_failing');
        });
    }

    public function down(): void
    {
        Schema::table('tactical_assets', function (Blueprint $table) {
            $table->dropColumn(['checks_failing', 'checks_total']);
        });
    }
};
