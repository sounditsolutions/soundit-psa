<?php

namespace App\Jobs;

use App\Enums\TranscriptionStatus;
use App\Models\PhoneCall;
use App\Services\TranscriptionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TranscribePhoneCall implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;
    public int $timeout = 600;

    public function __construct(
        private readonly int $callId,
    ) {}

    public function handle(TranscriptionService $service): void
    {
        // Use pessimistic locking to prevent duplicate transcriptions
        $call = DB::transaction(function () {
            $call = PhoneCall::where('id', $this->callId)->lockForUpdate()->first();

            if (!$call) {
                Log::warning('[Transcription] Call not found', ['call_id' => $this->callId]);
                return null;
            }

            // Bail if already processing or completed
            if (in_array($call->transcription_status, [
                TranscriptionStatus::Processing,
                TranscriptionStatus::Completed,
            ])) {
                Log::debug('[Transcription] Skipping — already ' . $call->transcription_status->value, [
                    'call_id' => $this->callId,
                ]);
                return null;
            }

            return $call;
        });

        if (!$call) {
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
        }
    }
}
