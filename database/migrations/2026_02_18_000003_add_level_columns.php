<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->string('level_id', 64)->nullable()->unique()->after('ninja_id');
            $table->string('level_url', 500)->nullable()->after('ninja_url');
            $table->timestamp('level_synced_at')->nullable()->after('ninja_synced_at');
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->string('level_group_id', 64)->nullable()->after('ninja_org_id');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn(['level_id', 'level_url', 'level_synced_at']);
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('level_group_id');
        });
    }
};
