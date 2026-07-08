<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_license', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->foreignId('license_id')->constrained()->cascadeOnDelete();
            $table->timestamp('assigned_at')->useCurrent();
            $table->string('assignment_source', 20)->default('manual');
            $table->timestamps();

            $table->unique(['contract_id', 'license_id']);
            $table->index('license_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_license');
    }
};
