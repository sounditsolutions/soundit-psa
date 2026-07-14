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
 * Recovery is safe to do bluntly ONLY for action types whose whole side effect is a single gate DB
 * transaction with any external send AFTER the committed Done — a stranded run of those provably
 * rolled back and never sent, so re-approval replays cleanly (TechnicianRun::isRecoverySafeToReopen).
 * The staged VENDOR actions (cipp_stage_*, tactical_stage_*) fire their upstream call BEFORE the
 * local audit/Done, so a crash between the two leaves 'executing' with the side effect already done;
 * reopening those would let a fresh approval DUPLICATE a create-user / script / wipe. So they are
 * NEVER auto-reopened — they are surfaced loudly for manual review instead. The release is a CAS
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

    /** @return array{reaped: int, run_ids: array<int, int>, flagged_unsafe: int, unsafe_run_ids: array<int, int>} */
    public function reap(): array
    {
        $cutoff = now()->subMinutes(self::STALE_AFTER_MINUTES);
        $reaped = [];
        $flaggedUnsafe = [];

        TechnicianRun::query()
            ->staleExecuting($cutoff)
            ->get()
            ->each(function (TechnicianRun $run) use (&$reaped, &$flaggedUnsafe): void {
                if (! $run->isRecoverySafeToReopen()) {
                    // A staged vendor action (CIPP/Tactical) may have already fired its upstream
                    // call before the crash. Reopening it would let a re-approval DUPLICATE that
                    // side effect, so we never do — we surface it loudly for a human to reconcile.
                    $flaggedUnsafe[] = $run->id;
                    Log::error(
                        '[Technician] A side-effecting action is stranded in "executing" and needs '.
                        'MANUAL review — its vendor side effect (CIPP/Tactical) may already have fired, '.
                        'so it is NOT auto-returned to the approval queue (a re-approval could duplicate it).',
                        [
                            'run_id' => $run->id,
                            'ticket_id' => $run->ticket_id,
                            'action_type' => $run->action_type,
                            'claimed_at' => optional($run->claimed_at)->toIso8601String(),
                        ],
                    );

                    return;
                }

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

        return [
            'reaped' => count($reaped),
            'run_ids' => $reaped,
            'flagged_unsafe' => count($flaggedUnsafe),
            'unsafe_run_ids' => $flaggedUnsafe,
        ];
    }
}
