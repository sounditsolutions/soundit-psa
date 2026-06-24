<?php

namespace App\Jobs;

use App\Enums\TranscriptionStatus;
use App\Models\PhoneCall;
use App\Services\TranscriptionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Job 1 of 2 in the split transcription pipeline.
 *
 * Downloads the Plivo CDN recording to a persisted local path
 * (call-recordings/{id}.mp3 on the local disk) and dispatches
 * {@see TranscribePhoneCall} to handle Whisper STT + AI analysis.
 *
 * Separating download from compute lets each step have an appropriately
 * sized timeout.  A 12.8 MB file at ~24 KB/s needs ~529 s to download;
 * previously that consumed most of the 600 s job budget before Whisper
 * and AI analysis had even started.
 *
 * File lifecycle:
 *   - Written to Storage::disk('local') as call-recordings/{id}.mp3
 *   - Persisted in phone_calls.recording_disk_path so TranscribePhoneCall
 *     can find it across workers.
 *   - Cleaned up by TranscribePhoneCall after transcription succeeds or
 *     fails (never orphaned on the disk).
 *
 * Idempotency: if recording_disk_path is already set and the file exists
 * on disk, the download is skipped and TranscribePhoneCall is dispatched
 * immediately (safe to retry).
 */
class DownloadRecording implements ShouldQueue
{
    use Queueable;

    /**
     * 720 s — generous budget for the CDN download alone.
     * At ~24 KB/s a 12.8 MB recording takes ~529 s; add headroom for
     * retries inside downloadRecording() (up to 3×15 s back-off).
     */
    public int $timeout = 720;

    public int $tries = 2;

    public function __construct(
        private readonly int $callId,
    ) {}

    public function handle(TranscriptionService $service): void
    {
        $call = PhoneCall::find($this->callId);

        if (! $call) {
            Log::warning('[DownloadRecording] Call not found', ['call_id' => $this->callId]);

            return;
        }

        if (! $call->recording_url) {
            Log::warning('[DownloadRecording] Call has no recording URL — skipping', [
                'call_id' => $this->callId,
            ]);

            return;
        }

        // Idempotency: if a previous attempt already persisted the file, skip.
        if ($call->recording_disk_path && Storage::disk('local')->exists($call->recording_disk_path)) {
            Log::info('[DownloadRecording] File already present, dispatching transcription', [
                'call_id' => $this->callId,
                'path' => $call->recording_disk_path,
            ]);
            TranscribePhoneCall::dispatch($this->callId);

            return;
        }

        $diskPath = null;

        try {
            // downloadRecording() streams to a system tmp file using the PR#60
            // timeout strategy (connect_timeout + stall-abort + backstop).
            $tmpFile = $service->downloadRecordingForJob($call->recording_url);

            // Move from tmpfs to a stable, worker-visible path on the local disk.
            $diskPath = "call-recordings/{$call->id}.mp3";
            Storage::disk('local')->put($diskPath, fopen($tmpFile, 'r'));
            @unlink($tmpFile);

            $call->update(['recording_disk_path' => $diskPath]);

            Log::info('[DownloadRecording] Recording saved', [
                'call_id' => $this->callId,
                'path' => $diskPath,
                'size' => Storage::disk('local')->size($diskPath),
            ]);
        } catch (\Throwable $e) {
            Log::error('[DownloadRecording] Download failed', [
                'call_id' => $this->callId,
                'error' => $e->getMessage(),
            ]);

            // Clean up any partial file from the persisted path.
            if ($diskPath && Storage::disk('local')->exists($diskPath)) {
                Storage::disk('local')->delete($diskPath);
            }

            $call->update([
                'transcription_status' => TranscriptionStatus::Failed,
                'transcription_error' => substr('Download failed: '.$e->getMessage(), 0, 500),
            ]);

            // Rethrow so Laravel can retry (up to $tries).
            if ($this->attempts() < $this->tries) {
                throw $e;
            }

            return;
        }

        // Dispatch the compute step — Whisper STT + AI analysis — with its own budget.
        TranscribePhoneCall::dispatch($this->callId);
    }
}
