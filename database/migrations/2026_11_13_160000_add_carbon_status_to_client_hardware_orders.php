<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_hardware_orders', function (Blueprint $table) {
            $table->string('carbon_status', 32)->nullable();
            $table->timestamp('carbon_status_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('client_hardware_orders', function (Blueprint $table) {
            $table->dropColumn(['carbon_status', 'carbon_status_checked_at']);
        });
    }
};
