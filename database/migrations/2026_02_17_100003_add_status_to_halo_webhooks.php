<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('halo_webhooks', function (Blueprint $table) {
            $table->string('status', 20)->default('pending')->after('payload')->index();
            $table->unsignedTinyInteger('attempts')->default(0)->after('status');
            $table->text('error')->nullable()->after('attempts');
        });
    }

    public function down(): void
    {
        Schema::table('halo_webhooks', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn(['status', 'attempts', 'error']);
        });
    }
};
