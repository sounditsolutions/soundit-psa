<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->unsignedInteger('halo_id')->nullable()->after('id');
            $table->unique(['invoice_id', 'halo_id']);
        });
    }

    public function down(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->dropUnique(['invoice_id', 'halo_id']);
            $table->dropColumn('halo_id');
        });
    }
};
