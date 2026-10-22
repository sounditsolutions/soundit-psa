<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agreement_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('contract_type');
            $table->string('kind')->default('agreement');
            $table->text('body');
            $table->boolean('requires_countersign')->default(true);
            $table->unsignedTinyInteger('signing_steps')->default(1);
            $table->boolean('apply_billing_on_sign')->default(false);
            $table->string('on_complete')->default('none');
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agreement_templates');
    }
};
