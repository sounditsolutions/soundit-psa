<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sip_endpoints', function (Blueprint $table) {
            $table->string('sip_username', 100)->nullable()->after('sip_uri');
            $table->text('sip_password')->nullable()->after('sip_username');
            $table->string('plivo_endpoint_id', 50)->nullable()->after('sip_password');
        });
    }

    public function down(): void
    {
        Schema::table('sip_endpoints', function (Blueprint $table) {
            $table->dropColumn(['sip_username', 'sip_password', 'plivo_endpoint_id']);
        });
    }
};
