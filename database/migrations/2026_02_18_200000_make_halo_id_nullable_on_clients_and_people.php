<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->unsignedBigInteger('halo_id')->nullable()->change();
        });

        Schema::table('people', function (Blueprint $table) {
            $table->unsignedBigInteger('halo_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->unsignedBigInteger('halo_id')->nullable(false)->change();
        });

        Schema::table('people', function (Blueprint $table) {
            $table->unsignedBigInteger('halo_id')->nullable(false)->change();
        });
    }
};
