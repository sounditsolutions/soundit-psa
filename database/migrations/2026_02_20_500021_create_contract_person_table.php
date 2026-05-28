<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_person', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->timestamp('assigned_at')->useCurrent();
            $table->string('assignment_source', 20)->default('manual');
            $table->foreignId('rule_id')->nullable()
                ->constrained('contract_assignment_rules')->nullOnDelete();
            $table->timestamps();

            $table->unique(['contract_id', 'person_id']);
            $table->index('person_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_person');
    }
};
