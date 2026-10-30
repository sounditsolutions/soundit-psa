<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit of every "Sync now" attempt on an asset's Sources panel,
 * refusals included (design-r3 / design.md Q-3 A). One row per attempt; rows are
 * never updated or deleted (AssetSourceSync refuses both).
 *
 * The row carries only the closed reason vocabulary, never a vendor payload or
 * vendor error text. asset_id and user_id are indexed but carry no foreign key,
 * so the history survives the asset or user it names and no existing delete
 * path gains a new constraint to satisfy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_source_syncs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('asset_id');
            $table->string('source', 32);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('outcome', 16);
            $table->string('reason', 32);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('retry_after_s')->nullable();
            $table->timestamp('started_at');
            $table->unsignedInteger('duration_ms');
            $table->index(['asset_id', 'source', 'started_at']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_source_syncs');
    }
};
