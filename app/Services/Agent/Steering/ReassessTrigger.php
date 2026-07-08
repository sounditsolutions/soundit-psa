<?php

namespace App\Services\Agent\Steering;

use App\Jobs\RunTechnicianAgent;
use App\Models\TechnicianRun;
use App\Models\Ticket;

/**
 * ReassessTrigger — immediately re-evaluate a ticket after an operator correction.
 *
 * Called by the cockpit steering layer when an operator denies or corrects a
 * pending proposal. The corrected run is superseded first so the dedup guard
 * (CO-5) no longer blocks the new evaluation; the job is dispatched with
 * correctionDriven=true so the change-throttle (CO-16) is also bypassed.
 *
 * The emergency-halt guard (#4.5) is UNCONDITIONAL — it fires even for
 * correction-driven runs and is not bypassed here.
 */
class ReassessTrigger
{
    public function reassess(Ticket $ticket, ?TechnicianRun $correctedRun): void
    {
        if ($correctedRun !== null) {
            $correctedRun->markSuperseded();
        }

        RunTechnicianAgent::dispatch($ticket->id, correctionDriven: true);
    }
}
