<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_intake_identities', function (Blueprint $table) {
            $table->foreignId('prospect_client_id')->nullable()->constrained('clients')->restrictOnDelete();
            $table->foreignId('person_id')->nullable()->constrained('people')->restrictOnDelete();
        });
        Schema::table('contact_submissions', function (Blueprint $table) {
            $table->string('exception_reason', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('contact_submissions', fn (Blueprint $table) => $table->dropColumn('exception_reason'));
        Schema::table('contact_intake_identities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('person_id');
            $table->dropConstrainedForeignId('prospect_client_id');
        });
    }
};
