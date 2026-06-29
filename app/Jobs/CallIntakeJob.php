<?php

namespace App\Jobs;

use App\Models\PhoneCall;
use App\Services\Agent\Intake\CallIntakePipeline;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Queued entry point for the call-intake leg: after a call is transcribed, run the
 * CallIntakePipeline asynchronously so the routing decision (resolve → attach/create)
 * never blocks the transcription job or the webhook response.
 *
 * Mirrors TranscribePhoneCall: a pessimistic lock around the load means two dispatches
 * for the same call can't double-process it. The pipeline itself is dormant-gated and
 * fail-soft, so a missing call or a disabled feature is a clean no-op.
 */
class CallIntakeJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 180;

    public function __construct(
        private readonly int $callId,
    ) {}

    public function handle(): void
    {
        // Pessimistic lock on the load — mirrors TranscribePhoneCall so concurrent
        // dispatches (transcription retry, manual re-run) can't double-process.
        $call = DB::transaction(function (): ?PhoneCall {
            return PhoneCall::where('id', $this->callId)->lockForUpdate()->first();
        });

        if (! $call) {
            Log::warning('[CallIntake] Call not found for intake job', ['call_id' => $this->callId]);

            return;
        }

        app(CallIntakePipeline::class)->handle($call);
    }
}
