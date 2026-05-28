<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blocked_numbers', function (Blueprint $table) {
            $table->id();
            $table->string('phone_number', 20)->unique();   // normalized E.164
            $table->text('reason')->nullable();
            $table->foreignId('blocked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('phone_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blocked_numbers');
    }
};
