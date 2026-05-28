<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('person_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->string('email', 255);
            $table->boolean('is_primary')->default(false);
            $table->string('label', 50)->nullable();
            $table->string('source', 20)->default('manual');
            $table->timestamps();

            $table->unique(['person_id', 'email']);
            $table->index('email');
        });

        // Seed from existing people.email
        DB::table('people')
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->orderBy('id')
            ->each(function ($person) {
                $email = mb_strtolower(trim($person->email));

                DB::table('person_emails')->insertOrIgnore([
                    'person_id' => $person->id,
                    'email' => $email,
                    'is_primary' => true,
                    'source' => 'migration',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('person_emails');
    }
};
