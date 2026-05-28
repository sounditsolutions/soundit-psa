<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phone_calls', function (Blueprint $table) {
            // Core transcription
            $table->longText('transcription')->nullable()->after('notes');
            $table->longText('transcription_summary')->nullable()->after('transcription');
            $table->string('transcription_status', 20)->nullable()->after('transcription_summary');
            $table->string('transcription_error', 500)->nullable()->after('transcription_status');
            $table->timestamp('transcribed_at')->nullable()->after('transcription_error');

            // Structured AI analysis fields
            $table->unsignedTinyInteger('sentiment_score')->nullable()->after('transcribed_at');
            $table->string('charge_classification', 20)->nullable()->after('sentiment_score');
            $table->text('coaching_notes')->nullable()->after('charge_classification');
        });
    }

    public function down(): void
    {
        Schema::table('phone_calls', function (Blueprint $table) {
            $table->dropColumn([
                'transcription',
                'transcription_summary',
                'transcription_status',
                'transcription_error',
                'transcribed_at',
                'sentiment_score',
                'charge_classification',
                'coaching_notes',
            ]);
        });
    }
};
