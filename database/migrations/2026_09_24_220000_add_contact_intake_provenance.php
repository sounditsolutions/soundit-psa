<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_notes', function (Blueprint $table) {
            $table->boolean('contact_intake_origin')->default(false);
            $table->timestamp('contact_intake_verified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ticket_notes', function (Blueprint $table) {
            $table->dropColumn(['contact_intake_origin', 'contact_intake_verified_at']);
        });
    }
};
