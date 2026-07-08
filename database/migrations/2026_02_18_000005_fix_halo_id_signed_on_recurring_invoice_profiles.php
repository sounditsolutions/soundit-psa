<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recurring_invoice_profiles', function (Blueprint $table) {
            // Halo recurring invoice IDs are negative (e.g., -151, -150).
            // Change from unsignedInteger to integer to allow negative values.
            // Unique index already exists — only change column type.
            $table->integer('halo_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('recurring_invoice_profiles', function (Blueprint $table) {
            $table->unsignedInteger('halo_id')->nullable()->change();
        });
    }
};
