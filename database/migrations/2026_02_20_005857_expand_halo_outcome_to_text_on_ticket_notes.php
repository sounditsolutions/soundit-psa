<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_notes', function (Blueprint $table) {
            $table->text('halo_outcome')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('ticket_notes', function (Blueprint $table) {
            $table->string('halo_outcome', 50)->nullable()->change();
        });
    }
};
