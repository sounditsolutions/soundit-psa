<?php

namespace App\Jobs;

use App\Enums\TechnicianRunState;
use App\Models\TechnicianRun;
use App\Services\Technician\Notify\OperatorNotifier;
use App\Support\StagedActionLabels;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Tell the operator an action is waiting for them (Tier-1, psa-2f0bg).
 *
 * Dispatched by TechnicianRunObserver on the transition into AwaitingApproval, with
 * ->afterCommit() — so this never runs inside the staging transaction and never runs
 * at all for a stage that rolls back (CO-21).
 *
 * THE CONTENT BAR IS THE POINT. This email must be DECIDE-FROM-LOCKSCREEN. "A staged
 * action awaits — tap to view" merely moves the friction: the operator still has to
 * open the cockpit, which is the owner-dependency this exists to remove. So the body
 * carries the client, the ticket, the action type, and the ACTUAL proposed content.
 * The cockpit link is the fallback for the full view, not the decision surface.
 *
 * WHAT IT MUST NEVER CARRY: a credential. proposed_meta holds the encrypted execution
 * payload, so this deliberately projects named fields rather than dumping meta — and a
 * test asserts a staged password reset's notification contains no payload blob. A
 * staged reset has no password yet (one is minted only on approval, and surfaces only
 * in the cockpit), and this notification must never become the thing that leaks one.
 */
class NotifyStagedActionAwaitingApproval implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public readonly int $runId) {}

    public function handle(OperatorNotifier $notifier): void
    {
        $run = TechnicianRun::with(['ticket.client'])->find($this->runId);

        // The job runs LATER, on a worker, so the run may have moved on since it was
        // staged. Two ways it can be gone, both a no-op rather than a failure — a stale
        // notification is worse than none:
        //   - deleted entirely (null), or
        //   - approved / denied / superseded, i.e. no longer AwaitingApproval.
        // The second was the arch+security REVISE (psa-xm8c2 / psa-3pq0f): emailing the
        // proposed content of an action that has ALREADY been decided both misinforms
        // the operator and leaks content for an action that never happened. Re-check the
        // persisted state here — it is the source of truth for the approval wait.
        if ($run === null || ! $this->isAwaitingApproval($run)) {
            return;
        }

        $ticket = $run->ticket;
        $client = $ticket?->client;
        $clientName = $client?->name ?? 'Unknown client';
        $subjectLine = $ticket?->subject ?? '(no subject)';
        $actionLabel = StagedActionLabels::humanLabel($run->action_type);

        $subject = "{$actionLabel} approval: {$clientName} ticket #{$run->ticket_id}";

        $body = implode("\n", array_filter([
            "{$clientName} — ticket #{$run->ticket_id}: {$subjectLine}",
            "Awaiting your approval: {$actionLabel}",
            '',
            'What it will do:',
            trim((string) $run->proposed_content) !== '' ? trim((string) $run->proposed_content) : '(no proposed content recorded)',
            '',
            $this->reasonLine($run),
            '',
            'Approve or deny: '.rtrim(config('app.url'), '/').'/cockpit',
        ], fn (?string $line) => $line !== null));

        $notifier->notify($subject, $body);
    }

    /**
     * The stage-time `reason` is what the agent recorded as the ask. Surfaced as-is —
     * this job does NOT summarise or rewrite it (no AI step); making that first line
     * decision-grade is the agent's job, not the notifier's.
     */
    private function isAwaitingApproval(TechnicianRun $run): bool
    {
        $state = $run->state;

        return $state instanceof TechnicianRunState
            ? $state === TechnicianRunState::AwaitingApproval
            : $state === TechnicianRunState::AwaitingApproval->value;
    }

    private function reasonLine(TechnicianRun $run): ?string
    {
        $meta = is_array($run->proposed_meta) ? $run->proposed_meta : [];
        $reasons = $meta['reasons'] ?? null;

        if (! is_array($reasons) || $reasons === []) {
            return null;
        }

        $first = $reasons[0] ?? null;

        return is_string($first) && trim($first) !== '' ? 'Why: '.trim($first) : null;
    }
}
