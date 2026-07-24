<?php

namespace App\Jobs;

use App\Models\TechnicianRun;
use App\Services\Technician\Notify\OperatorNotifier;
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

        // Denied, superseded or deleted between staging and the worker picking this up:
        // a stale notification is worse than none, so this is a no-op, not a failure.
        if ($run === null) {
            return;
        }

        $ticket = $run->ticket;
        $client = $ticket?->client;
        $clientName = $client?->name ?? 'Unknown client';
        $subjectLine = $ticket?->subject ?? '(no subject)';

        $subject = "Approval needed: {$run->action_type} for {$clientName} (ticket #{$run->ticket_id})";

        $body = implode("\n", array_filter([
            "{$clientName} — ticket #{$run->ticket_id}: {$subjectLine}",
            "Action awaiting your approval: {$run->action_type}",
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
