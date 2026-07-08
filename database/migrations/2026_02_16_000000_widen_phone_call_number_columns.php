<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phone_calls', function (Blueprint $table) {
            $table->string('from_number', 100)->change();
            $table->string('to_number', 100)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('phone_calls', function (Blueprint $table) {
            $table->string('from_number', 30)->change();
            $table->string('to_number', 30)->nullable()->change();
        });
    }
};
