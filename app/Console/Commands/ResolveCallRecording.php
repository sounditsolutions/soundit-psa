<?php

namespace App\Console\Commands;

use App\Jobs\DownloadRecording;
use App\Models\PhoneCall;
use App\Services\PhoneCallService;
use App\Support\PlivoConfig;
use App\Support\TranscriptionConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ResolveCallRecording extends Command
{
    protected $signature = 'calls:resolve-recording {call : The phone call ID}';

    protected $description = 'Resolve a missing recording from Plivo API for a single call';

    public function handle(PhoneCallService $phoneCallService): int
    {
        $callId = (int) $this->argument('call');
        $call = PhoneCall::find($callId);

        if (! $call) {
            return self::FAILURE;
        }

        // Already has a recording — nothing to do
        if ($call->recording_url) {
            return self::SUCCESS;
        }

        if (! PlivoConfig::get('auth_id') || ! PlivoConfig::get('auth_token')) {
            return self::FAILURE;
        }

        $phoneCallService->resolveRecordingFromPlivo($call);
        $call->refresh();

        if (! $call->recording_url) {
            Log::warning('[PhoneCall] No recording found on Plivo API after call end', [
                'call_id' => $call->id,
            ]);

            return self::FAILURE;
        }

        // Auto-transcribe if enabled
        if (TranscriptionConfig::autoTranscribeEnabled()
            && TranscriptionConfig::isConfigured()
            && ! $call->isTranscribed()
            && ! $call->isTranscribing()
            && ($call->recording_duration ?? 0) >= TranscriptionConfig::minDurationSeconds()
        ) {
            $call->update(['transcription_status' => \App\Enums\TranscriptionStatus::Pending]);
            DownloadRecording::dispatch($call->id);
        }

        return self::SUCCESS;
    }
}
