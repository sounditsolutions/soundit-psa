<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ITIL change management fields. Populated on tickets whose type is `change`;
     * null on all other ticket types.
     */
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->string('change_type', 20)->nullable()->after('subcategory');
            $table->string('risk_level', 20)->nullable()->after('change_type');
            $table->string('cab_approval', 20)->nullable()->after('risk_level');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn(['change_type', 'risk_level', 'cab_approval']);
        });
    }
};
