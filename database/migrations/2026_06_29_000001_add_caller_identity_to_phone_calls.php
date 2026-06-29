<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phone_calls', function (Blueprint $table) {
            $table->string('caller_identified_name')->nullable()->after('person_confirmed');
            $table->string('caller_identified_company')->nullable()->after('caller_identified_name');
            $table->decimal('caller_identity_confidence', 3, 2)->nullable()->after('caller_identified_company');
        });
    }

    public function down(): void
    {
        Schema::table('phone_calls', function (Blueprint $table) {
            $table->dropColumn([
                'caller_identified_name',
                'caller_identified_company',
                'caller_identity_confidence',
            ]);
        });
    }
};
