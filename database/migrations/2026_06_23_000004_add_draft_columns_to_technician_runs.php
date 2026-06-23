<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The held draft a run carries for the cockpit (Plan 1B). proposed_content is the
 * exact text awaiting approval; proposed_meta carries the suggested recipient +
 * the classifier's reasons; confidence + tokens_used feed the digest + budget.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('technician_runs', function (Blueprint $table) {
            $table->longText('proposed_content')->nullable()->after('state');
            $table->json('proposed_meta')->nullable()->after('proposed_content');
            $table->decimal('confidence', 4, 3)->nullable()->after('proposed_meta');
            $table->unsignedInteger('tokens_used')->default(0)->after('confidence');
        });
    }

    public function down(): void
    {
        Schema::table('technician_runs', function (Blueprint $table) {
            $table->dropColumn(['proposed_content', 'proposed_meta', 'confidence', 'tokens_used']);
        });
    }
};
