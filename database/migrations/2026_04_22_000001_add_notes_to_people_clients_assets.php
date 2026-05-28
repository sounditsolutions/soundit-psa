<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->text('notes')->nullable()->after('job_title');
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->text('notes')->nullable()->after('name');
        });

        Schema::table('assets', function (Blueprint $table) {
            $table->text('notes')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropColumn('notes');
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('notes');
        });

        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn('notes');
        });
    }
};
