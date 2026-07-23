<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-client UniFi mapping (psa-1ynqc), mirroring huntress_organization_id /
 * controld_org_id.
 *
 * GRAIN: a PSA client maps to a UniFi SITE (`siteId`), which is the unit ISP metrics
 * and device counts are reported against. `unifi_host_id` records the owning console
 * so a site stays attributable when the same account administers several consoles.
 *
 * Both are strings, not integers: UniFi ids are opaque hex/composite values, e.g.
 * siteId "661de833b6b2463f0c20b319" and hostId
 * "900A6F0030...0063EC9853:123456789" (note the embedded colon).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('unifi_site_id')->unique()->nullable()->after('huntress_organization_id');
            $table->string('unifi_host_id')->nullable()->after('unifi_site_id');
        });
    }

    public function down(): void
    {
        // SQLite rebuilds the table on a column drop and re-validates every surviving
        // index, so the unique index on unifi_site_id must go FIRST and in its own
        // statement — dropping both together fails with "1 error in index
        // clients_unifi_site_id_unique after drop column". Caught by the real-runner
        // rollback test in Tests\Feature\Taxonomy\TicketCategoryTest.
        Schema::table('clients', function (Blueprint $table) {
            $table->dropUnique('clients_unifi_site_id_unique');
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['unifi_site_id', 'unifi_host_id']);
        });
    }
};
