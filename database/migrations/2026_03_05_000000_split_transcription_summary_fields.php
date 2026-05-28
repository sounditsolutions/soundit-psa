<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phone_calls', function (Blueprint $table) {
            $table->text('call_summary')->nullable()->after('transcription_summary');
            $table->text('next_steps')->nullable()->after('call_summary');
            $table->longText('cleaned_transcript')->nullable()->after('next_steps');
        });

        // Backfill from existing transcription_summary blobs
        DB::table('phone_calls')
            ->whereNotNull('transcription_summary')
            ->orderBy('id')
            ->chunk(100, function ($calls) {
                foreach ($calls as $call) {
                    $updates = [];

                    if (preg_match('/##\s*Call Summary\s*\n(.*?)(?=\n##|\z)/si', $call->transcription_summary, $m)) {
                        $text = trim($m[1]);
                        if ($text !== '') {
                            $updates['call_summary'] = $text;
                        }
                    }

                    if (preg_match('/##\s*Next Steps\s*\n(.*?)(?=\n##|\z)/si', $call->transcription_summary, $m)) {
                        $text = trim($m[1]);
                        if ($text !== '') {
                            $updates['next_steps'] = $text;
                        }
                    }

                    if (preg_match('/##\s*Transcription\s*\n(.*?)(?=\n##|\z)/si', $call->transcription_summary, $m)) {
                        $text = trim($m[1]);
                        if ($text !== '') {
                            $updates['cleaned_transcript'] = $text;
                        }
                    }

                    if (! empty($updates)) {
                        DB::table('phone_calls')->where('id', $call->id)->update($updates);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('phone_calls', function (Blueprint $table) {
            $table->dropColumn(['call_summary', 'next_steps', 'cleaned_transcript']);
        });
    }
};
