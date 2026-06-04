<?php

namespace App\Console\Commands;

use App\Enums\CallStatus;
use App\Models\PhoneCall;
use App\Services\PhoneCallService;
use App\Support\PlivoConfig;
use App\Support\TranscriptionConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

class ResolveCallRecordings extends Command
{
    protected $signature = 'calls:resolve-recordings';

    protected $description = 'Resolve missing recordings from Plivo API and download to local storage';

    public function handle(PhoneCallService $phoneCallService): int
    {
        if (! PlivoConfig::get('auth_id') || ! PlivoConfig::get('auth_token')) {
            $this->warn('Plivo not configured — skipping.');

            return self::SUCCESS;
        }

        $this->resolveFromPlivo($phoneCallService);
        $this->downloadMissing($phoneCallService);

        return self::SUCCESS;
    }

    /**
     * Find completed calls with no recording URL and resolve from Plivo API.
     */
    private function resolveFromPlivo(PhoneCallService $phoneCallService): void
    {
        $calls = PhoneCall::whereNull('recording_url')
            ->where('status', CallStatus::Completed)
            ->where('duration', '>', 0)
            ->whereNotNull('call_uuid')
            ->where('started_at', '>=', now()->subDays(30))
            ->orderBy('id')
            ->get();

        if ($calls->isEmpty()) {
            $this->info('No calls with missing recordings.');

            return;
        }

        $this->info("Found {$calls->count()} call(s) with missing recording URLs.");

        $resolved = 0;
        foreach ($calls as $call) {
            $phoneCallService->resolveRecordingFromPlivo($call);
            $call->refresh();

            if ($call->recording_url) {
                $resolved++;
                $this->line("  #{$call->id}: resolved ({$call->recording_duration}s)");
                $this->autoTranscribe($call);
            } else {
                $this->line("  #{$call->id}: no recording found on Plivo");
            }
        }

        $this->info("{$resolved}/{$calls->count()} recording(s) resolved from Plivo.");
    }

    /**
     * Download recordings that have a URL but no local file.
     */
    private function downloadMissing(PhoneCallService $phoneCallService): void
    {
        $calls = PhoneCall::whereNotNull('recording_url')
            ->whereNull('recording_disk_path')
            ->where('started_at', '>=', now()->subDays(30))
            ->orderBy('id')
            ->get();

        if ($calls->isEmpty()) {
            $this->info('All recordings already stored locally.');

            return;
        }

        $this->info("Downloading {$calls->count()} recording(s) to local storage...");

        $downloaded = 0;
        foreach ($calls as $call) {
            if ($phoneCallService->downloadRecording($call)) {
                $downloaded++;
                $this->line("  #{$call->id}: downloaded");
            } else {
                $this->line("  #{$call->id}: download failed");
            }
        }

        $this->info("{$downloaded}/{$calls->count()} recording(s) downloaded.");
    }

    private function autoTranscribe(PhoneCall $call): void
    {
        if (! TranscriptionConfig::autoTranscribeEnabled() || ! TranscriptionConfig::isConfigured()) {
            return;
        }

        if ($call->isTranscribed() || $call->isTranscribing()) {
            return;
        }

        $minDuration = TranscriptionConfig::minDurationSeconds();
        if (($call->recording_duration ?? 0) < $minDuration) {
            return;
        }

        $cmd = sprintf('php %s calls:transcribe %d > /dev/null 2>&1 &', base_path('artisan'), $call->id);
        Process::run($cmd);
        $this->line('    → transcription queued');
    }
}
