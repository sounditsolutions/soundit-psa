<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * assets.merged_into_asset_id — tombstone pointer for the asset merge
 * (survivor ← duplicate, #584). A merged-away duplicate keeps its soft-deleted
 * row with this pointing at the survivor, so the asset page can say
 * "Merged into #X" instead of "deleted" and restore can refuse to recreate
 * the duplicate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->foreignId('merged_into_asset_id')->nullable()
                ->constrained('assets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('merged_into_asset_id');
        });
    }
};
