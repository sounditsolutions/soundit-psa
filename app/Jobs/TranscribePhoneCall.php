<?php

namespace App\Jobs;

use App\Enums\TranscriptionStatus;
use App\Models\PhoneCall;
use App\Services\TranscriptionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Job 2 of 2 in the split transcription pipeline.
 *
 * Runs Whisper STT + AI analysis on a recording that has already been
 * downloaded to a local path by {@see DownloadRecording}.  If the call's
 * recording_disk_path is set and the file exists, TranscriptionService
 * skips the CDN download and goes straight to compute — keeping this
 * job's 600 s budget for Whisper and AI only.
 *
 * Can also be used standalone (e.g. from the artisan command) when the
 * download has not been pre-split; in that case TranscriptionService
 * falls back to an inline download.
 *
 * File cleanup: the persisted recording file (recording_disk_path) is
 * deleted here after transcription succeeds or fails so the disk stays
 * clean regardless of the outcome.
 */
class TranscribePhoneCall implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /**
     * Budget for Whisper STT + AI analysis only (download already handled by
     * DownloadRecording).  600 s remains the ceiling; all of it is now
     * available for compute rather than being shared with the CDN transfer.
     */
    public int $timeout = 600;

    public function __construct(
        private readonly int $callId,
    ) {}

    public function handle(TranscriptionService $service): void
    {
        // Use pessimistic locking to prevent duplicate transcriptions
        $call = DB::transaction(function () {
            $call = PhoneCall::where('id', $this->callId)->lockForUpdate()->first();

            if (! $call) {
                Log::warning('[Transcription] Call not found', ['call_id' => $this->callId]);

                return null;
            }

            // Bail if already processing or completed
            if (in_array($call->transcription_status, [
                TranscriptionStatus::Processing,
                TranscriptionStatus::Completed,
            ])) {
                Log::debug('[Transcription] Skipping — already '.$call->transcription_status->value, [
                    'call_id' => $this->callId,
                ]);

                return null;
            }

            return $call;
        });

        if (! $call) {
            // Still clean up the local file if one was set — avoids orphaned files
            // when a second attempt finds the call already completed.
            $this->cleanupDiskFile($call ?? PhoneCall::find($this->callId));

            return;
        }

        try {
            $service->transcribe($call);
        } catch (\Throwable $e) {
            Log::error('[Transcription] Job failed', [
                'call_id' => $this->callId,
                'error' => $e->getMessage(),
            ]);

            // Don't rethrow — the service already set status to failed.
            // Only rethrow if we want Laravel to retry (for retriable errors).
            if ($this->attempts() < $this->tries) {
                throw $e;
            }
        } finally {
            // Clean up the persisted local recording file so the disk stays tidy
            // regardless of transcription outcome (success, failure, or skip).
            $this->cleanupDiskFile($call);
        }
    }

    /**
     * Delete the locally-persisted recording file (if any) and clear the DB column.
     * Safe to call with null (e.g. if the call was deleted between jobs).
     */
    private function cleanupDiskFile(?PhoneCall $call): void
    {
        if (! $call || ! $call->recording_disk_path) {
            return;
        }

        if (Storage::disk('local')->exists($call->recording_disk_path)) {
            Storage::disk('local')->delete($call->recording_disk_path);
        }

        $call->update(['recording_disk_path' => null]);
    }
}
