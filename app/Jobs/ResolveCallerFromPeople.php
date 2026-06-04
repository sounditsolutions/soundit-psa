<?php

namespace App\Jobs;

use App\Models\PhoneCall;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ResolveCallerFromPeople implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(
        private readonly int $callId,
    ) {}

    public function handle(\App\Services\PhoneCallService $phoneCallService): void
    {
        $call = PhoneCall::find($this->callId);
        if (! $call) {
            return;
        }

        // Already fully resolved or manually confirmed by a tech
        if ($call->client_id !== null || $call->person_confirmed) {
            return;
        }

        $person = $phoneCallService->findPersonByPhoneNumber($call->from_number);

        if ($person) {
            $call->person_id = $person->id;
            $call->client_id = $person->client_id;
            $call->save();

            Log::info('[CallerResolve] Match found', [
                'call_id' => $call->id,
                'person_id' => $person->id,
                'client' => $person->client?->name,
            ]);

            return;
        }

        Log::warning('[CallerResolve] No match found', [
            'call_id' => $call->id,
            'from_number' => $call->from_number,
        ]);
    }
}
