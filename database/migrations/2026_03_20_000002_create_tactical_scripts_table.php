<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tactical_scripts', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('tactical_script_id')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('shell', 20); // powershell, cmd, python, shell
            $table->string('category')->nullable();
            $table->unsignedInteger('default_timeout')->default(90);
            $table->json('supported_platforms')->nullable();
            $table->boolean('hidden')->default(false);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tactical_scripts');
    }
};
