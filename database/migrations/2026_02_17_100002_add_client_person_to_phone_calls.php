<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phone_calls', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->after('halo_client_name')
                ->constrained()->nullOnDelete();
            $table->foreignId('person_id')->nullable()->after('client_id')
                ->constrained('people')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('phone_calls', function (Blueprint $table) {
            $table->dropConstrainedForeignId('person_id');
            $table->dropConstrainedForeignId('client_id');
        });
    }
};
