<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recurring_invoice_profile_lines', function (Blueprint $table) {
            $table->unsignedInteger('halo_id')->nullable()->after('id');
            $table->unique(['profile_id', 'halo_id']);
        });
    }

    public function down(): void
    {
        Schema::table('recurring_invoice_profile_lines', function (Blueprint $table) {
            $table->dropUnique(['profile_id', 'halo_id']);
            $table->dropColumn('halo_id');
        });
    }
};
