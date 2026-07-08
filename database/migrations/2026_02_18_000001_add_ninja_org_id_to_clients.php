<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->unsignedInteger('ninja_org_id')->nullable()->after('halo_id');
            $table->unsignedInteger('halo_ninja_org_id')->nullable()->after('ninja_org_id');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['ninja_org_id', 'halo_ninja_org_id']);
        });
    }
};
