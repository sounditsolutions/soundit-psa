<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_hardware_models', function (Blueprint $table) {
            $table->json('carbon_component_ids')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('client_hardware_models', function (Blueprint $table) {
            $table->dropColumn('carbon_component_ids');
        });
    }
};
