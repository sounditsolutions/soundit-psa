<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Every existing user defaults to 'admin' so nothing changes on
            // deploy — enforcement is added in later phases of the auth epic.
            $table->string('role')->default('admin')->after('is_contractor');
        });

        // Seed the contractor role from the existing time-pool flag so the two
        // signals start consistent. Literal values are used (not the enum) to
        // keep this migration self-contained.
        DB::table('users')
            ->where('is_contractor', true)
            ->update(['role' => 'contractor']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
