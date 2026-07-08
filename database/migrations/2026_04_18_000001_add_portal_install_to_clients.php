<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('portal_install_token')->nullable()->unique()->after('tactical_site_id');
            $table->string('portal_primary_rmm')->nullable()->after('portal_install_token');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropUnique(['portal_install_token']);
            $table->dropColumn(['portal_install_token', 'portal_primary_rmm']);
        });
    }
};
