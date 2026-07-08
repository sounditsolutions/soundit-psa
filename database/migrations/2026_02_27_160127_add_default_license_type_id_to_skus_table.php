<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('skus', function (Blueprint $table) {
            $table->foreignId('default_license_type_id')->nullable()->after('default_quantity_type')
                ->constrained('license_types')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('skus', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_license_type_id');
        });
    }
};
