<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds catalog fields so a SKU can be exposed in the client portal shop
     * (product catalog + configurator). `portal_orderable` gates whether the
     * SKU appears to portal users; `portal_description` is an optional
     * client-friendly blurb shown in the catalog (distinct from the internal
     * `description`).
     */
    public function up(): void
    {
        Schema::table('skus', function (Blueprint $table) {
            $table->boolean('portal_orderable')->default(false)->after('is_active');
            $table->text('portal_description')->nullable()->after('description');
            $table->index('portal_orderable');
        });
    }

    public function down(): void
    {
        Schema::table('skus', function (Blueprint $table) {
            $table->dropIndex(['portal_orderable']);
            $table->dropColumn(['portal_orderable', 'portal_description']);
        });
    }
};
