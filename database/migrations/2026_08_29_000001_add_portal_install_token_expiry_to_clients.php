<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->timestamp('portal_install_token_expires_at')->nullable()->after('portal_install_token');
        });

        // #864: existing links were minted with no lifetime at all. A NULL
        // backfill would grandfather every forwarded copy of those URLs
        // forever, so they get a 90-day grace window instead: nothing breaks
        // on deploy, nothing lives indefinitely. NULL remains meaningful as a
        // deliberate per-row "no expiry" exception, set by hand on request.
        DB::table('clients')
            ->whereNotNull('portal_install_token')
            ->update(['portal_install_token_expires_at' => now()->addDays(90)]);
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('portal_install_token_expires_at');
        });
    }
};
