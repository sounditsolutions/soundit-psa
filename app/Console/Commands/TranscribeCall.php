<?php

namespace App\Console\Commands;

use App\Enums\TranscriptionStatus;
use App\Models\PhoneCall;
use App\Services\TranscriptionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TranscribeCall extends Command
{
    protected $signature = 'calls:transcribe {call : The phone call ID}';
    protected $description = 'Transcribe a phone call recording (runs without timeout constraints)';

    public function handle(TranscriptionService $service): int
    {
        $callId = (int) $this->argument('call');

        // Pessimistic locking to prevent duplicate transcriptions
        $call = DB::transaction(function () use ($callId) {
            $call = PhoneCall::where('id', $callId)->lockForUpdate()->first();

            if (! $call) {
                $this->error("Call #{$callId} not found.");
                return null;
            }

            if ($call->transcription_status === TranscriptionStatus::Processing) {
                $this->warn("Call #{$callId} is already being transcribed.");
                return null;
            }

            return $call;
        });

        if (! $call) {
            return self::FAILURE;
        }

        try {
            $service->transcribe($call);
            $this->info("Call #{$callId} transcribed successfully.");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('[Transcription] Command failed', [
                'call_id' => $callId,
                'error' => $e->getMessage(),
            ]);
            $this->error("Transcription failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
