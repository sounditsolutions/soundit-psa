<?php

namespace App\Services\Technician;

use App\Models\TechnicianRun;
use Illuminate\Support\Facades\Log;

/**
 * psa-xz0z — recover TechnicianRuns stranded in 'executing'.
 *
 * claimForExecution() flips awaiting_approval → executing OUTSIDE the gate's DB transaction, and
 * only releaseClaim() (in the approval service's catch) flips it back. So a process death — OOM,
 * request timeout, or PHP-FPM restarting mid-request during a DEPLOY — runs no catch, and the run
 * is stuck in 'executing' forever: claimForExecution() requires awaiting_approval so it can never
 * win again, every retry returns 'already_handled', and the operator's approvable action is
 * silently lost behind a message that says it went through.
 *
 * Recovery is SAFE to do bluntly: the gate wraps executor + audit in one transaction, so a
 * stranded run's action was rolled back — nothing half-executed. Returning it to awaiting_approval
 * lets the operator re-approve and the gate replays cleanly. The release is a CAS
 * (releaseClaim → where state = executing), so a run that legitimately completes between our fetch
 * and the release is a no-op and is never miscounted.
 */
class StaleClaimReaper
{
    /**
     * How long a run may sit in 'executing' before it is presumed dead. The gate transaction
     * (create note/close + audit + advanceTo(Done)) is sub-second — the email send happens AFTER
     * the run is already Done — so anything still executing minutes later is stranded, not slow.
     * A generous margin keeps a pathologically slow request from ever being reaped mid-flight.
     */
    public const STALE_AFTER_MINUTES = 5;

    /** @return array{reaped: int, run_ids: array<int, int>} */
    public function reap(): array
    {
        $cutoff = now()->subMinutes(self::STALE_AFTER_MINUTES);
        $reaped = [];

        TechnicianRun::query()
            ->staleExecuting($cutoff)
            ->get()
            ->each(function (TechnicianRun $run) use (&$reaped): void {
                if (! $run->releaseClaim()) {
                    return; // completed between the fetch and here — not ours to claim.
                }

                $reaped[] = $run->id;
                Log::warning(
                    '[Technician] Reaped a stale execution claim — a held action was stranded in '.
                    "'executing' (process death or a deploy) and has been returned to the approval queue.",
                    [
                        'run_id' => $run->id,
                        'ticket_id' => $run->ticket_id,
                        'action_type' => $run->action_type,
                        'claimed_at' => optional($run->claimed_at)->toIso8601String(),
                    ],
                );
            });

        return ['reaped' => count($reaped), 'run_ids' => $reaped];
    }
}
