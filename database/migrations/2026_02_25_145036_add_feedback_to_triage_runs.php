<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('triage_runs', function (Blueprint $table) {
            $table->boolean('feedback_correct')->nullable()->after('ai_tokens_used');
            $table->text('feedback_note')->nullable()->after('feedback_correct');
            $table->foreignId('feedback_submitted_by')->nullable()->after('feedback_note')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('feedback_submitted_at')->nullable()->after('feedback_submitted_by');
        });
    }

    public function down(): void
    {
        Schema::table('triage_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('feedback_submitted_by');
            $table->dropColumn(['feedback_correct', 'feedback_note', 'feedback_submitted_at']);
        });
    }
};
