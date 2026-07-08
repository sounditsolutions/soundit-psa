<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->boolean('servosity_backup_enabled')->default(false)->after('comet_backup_enabled');
            $table->unsignedBigInteger('servosity_dr_backup_id')->nullable()->after('servosity_backup_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn(['servosity_backup_enabled', 'servosity_dr_backup_id']);
        });
    }
};
