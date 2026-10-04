<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LITSRMM stage 3: link a LITSRMM device to a PSA asset. Mirrors the shape of
 * 2026_09_23_090000_add_autoelevate_fields_to_assets.php (vendor id + synced_at).
 * Additive only: two nullable columns and one index, no backfill.
 *
 * PLAIN (non-unique) index on litsrmm_device_id, for the same reason as
 * autoelevate_computer_id: assets are soft-deleted and a trashed asset keeps its
 * column values, so a unique index would let a deleted asset's stale device id
 * block the live asset from ever being linked. One live asset per device is
 * enforced by LitsrmmAssetSyncService instead.
 *
 * A string, not ->uuid(): the vendor documents the id as opaque. The column is
 * also never compared with '' (a native uuid column on MariaDB cannot hold one,
 * which is the trap recorded in AutoElevateAssetSyncService::sync()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->string('litsrmm_device_id', 64)->nullable();
            $table->timestamp('litsrmm_synced_at')->nullable();
            $table->index('litsrmm_device_id');
        });
    }

    public function down(): void
    {
        // Index first, in its own statement: SQLite rebuilds the table on a
        // column drop and re-validates surviving indexes.
        Schema::table('assets', function (Blueprint $table) {
            $table->dropIndex('assets_litsrmm_device_id_index');
        });

        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn(['litsrmm_device_id', 'litsrmm_synced_at']);
        });
    }
};
