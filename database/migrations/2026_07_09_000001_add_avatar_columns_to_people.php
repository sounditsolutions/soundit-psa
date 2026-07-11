<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->string('avatar_path')->nullable()->after('cipp_enriched_at');
            $table->timestamp('avatar_synced_at')->nullable()->after('avatar_path');
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropColumn(['avatar_path', 'avatar_synced_at']);
        });
    }
};
