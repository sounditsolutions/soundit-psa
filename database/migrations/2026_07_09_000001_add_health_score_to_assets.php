<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            // Cached AI health score (0-100) + explanation so it isn't recomputed
            // on every render. Populated by AssetHealthService (deterministic score)
            // and the AI narrative (assets:refresh-health / on-view background job).
            $table->unsignedTinyInteger('health_score')->nullable()->after('is_active');
            $table->string('health_grade', 10)->nullable()->after('health_score');
            $table->text('health_summary')->nullable()->after('health_grade');
            $table->boolean('health_summary_is_ai')->default(false)->after('health_summary');
            $table->json('health_breakdown')->nullable()->after('health_summary_is_ai');
            $table->timestamp('health_computed_at')->nullable()->after('health_breakdown');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn([
                'health_score',
                'health_grade',
                'health_summary',
                'health_summary_is_ai',
                'health_breakdown',
                'health_computed_at',
            ]);
        });
    }
};
