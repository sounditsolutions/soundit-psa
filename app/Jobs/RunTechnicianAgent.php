<?php

namespace App\Jobs;

use App\Enums\TechnicianRunState;
use App\Models\AssistantConversation;
use App\Models\Client;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Services\Agent\SignificanceGate;
use App\Services\Agent\TechnicianAgent;
use App\Support\AgentConfig;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * RunTechnicianAgent — reactive per-ticket wake job.
 *
 * The agent REACTS to tickets the review pass surfaces. This job is dispatched
 * from TriageReviewOpen (the review-ping branch, CO-17) after the existing triage
 * review job, so it only ever runs when triage is fully configured.
 *
 * Guard chain (each an early return):
 *   1. Dormancy: AgentConfig::enabled() — returns when the flag is off.
 *   2. Ticket exists.
 *   3. Operational re-check (CO-8): client must be Active AND is_active.
 *   4. Ticket is open.
 *   5. Dedup (CO-5): ticket already has an AwaitingApproval propose_close run.
 *   6. Depth-cap (CO-11): global AwaitingApproval propose_close count >= maxPendingProposals.
 *   7. Change-throttle (CO-16): skip if already evaluated since the ticket last changed.
 *   8. SignificanceGate: cheap Haiku check — skip if clearly still active.
 *   9. TechnicianAgent::run — the agent reasons and (maybe) proposes a close.
 *
 * No cooldown beyond the change-throttle (which re-reacts only when the ticket
 * changes). Re-surfacing a changed ticket is fine (Haiku is cheap; dedup guards
 * prevent duplicate held proposals).
 */
class RunTechnicianAgent implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public readonly int $ticketId, public readonly bool $correctionDriven = false) {}

    /**
     * Parameterless handle — services resolved via app() so the job is
     * instantiatable without the container and tests can swap mocks.
     */
    public function handle(): void
    {
        // 1. Dormancy guard — a queued job must not fire after the flag flips off.
        if (! AgentConfig::enabled()) {
            return;
        }

        // 2. Ticket must exist.
        $ticket = Ticket::find($this->ticketId);
        if (! $ticket) {
            return;
        }

        // 3. Operational re-check (CO-8): the client must be Active AND is_active.
        //    Not merely non-prospect — a deactivated active-stage client is also out.
        if (! Client::operational()->whereKey($ticket->client_id)->exists()) {
            return;
        }

        // 4. Ticket must be open.
        if (! $ticket->status->isOpen()) {
            return;
        }

        // 4.5. Emergency halt (A2b): during an open emergency the autonomous agent must
        //      NOT act — no client reply, no close, no flag. This mirrors DraftPipeline's
        //      halt, which used to cover the reply path before A2b moved replies here; the
        //      agent is the single chokepoint for both the inbound reply-wake and the
        //      review-pass close-wake, so this restores (and broadens) the emergency cover.
        if (\App\Models\TechnicianEmergency::hasOpenEmergency($ticket)) {
            return;
        }

        // 5. Dedup (CO-5): don't re-propose if a propose_close is already waiting for
        //    approval. A2b note: this now ALSO defers the reply-wake — when a close
        //    proposal is pending, the agent leaves the ticket entirely (no reply draft is
        //    stacked alongside an unresolved close). Conservative by design; resolving the
        //    pending close re-opens evaluation (its content changes the ticket → throttle
        //    clears). A known, tested limitation — refine later if replies should pre-empt.
        //    Skipped for correction-driven runs — the operator has already superseded the
        //    pending proposal; re-evaluate immediately.
        if (! $this->correctionDriven) {
            if (TechnicianRun::where('ticket_id', $this->ticketId)
                ->where('action_type', 'propose_close')
                ->where('state', TechnicianRunState::AwaitingApproval)
                ->exists()) {
                return;
            }
        }

        // 6. Depth-cap (CO-11): anti-flood — don't accumulate an unbounded held-proposal queue.
        if (TechnicianRun::where('action_type', 'propose_close')
            ->where('state', TechnicianRunState::AwaitingApproval)
            ->count() >= AgentConfig::maxPendingProposals()) {
            return;
        }

        // 7. Change-throttle (CO-16): skip if we already evaluated this ticket since it last
        //    changed. A client reply or status edit bumps updated_at, clearing the lock.
        //    Set the marker BEFORE the gate so should-stay tickets (where the gate returns
        //    false and no run is created) are still marked evaluated — not re-evaluated next
        //    pass. The agent's own propose_close creates a TechnicianRun, not a ticket update,
        //    so accepting a proposal does not falsely re-trigger evaluation on the same ticket.
        //    Skipped for correction-driven runs — an operator correction is an explicit demand
        //    for immediate re-evaluation regardless of the throttle marker.
        if (! $this->correctionDriven) {
            $cacheKey = "agent_eval:{$ticket->id}";
            $lastEval = Cache::get($cacheKey);
            if ($lastEval !== null && $ticket->updated_at !== null && $ticket->updated_at->timestamp <= $lastEval) {
                return; // already evaluated since this ticket last changed — re-react only on change
            }
            Cache::put($cacheKey, now()->timestamp, now()->addDays(30)); // TTL bounds cache growth only
        }

        // 8. Significance gate — cheap Haiku check; false = "clearly still active, skip".
        if (! app(SignificanceGate::class)->assess($ticket)) {
            return;
        }

        // 9. Resolve correction context (correction-driven runs only) so the agent can
        //    thread provenance into the resulting TechnicianRun's proposed_meta.
        $correctionContext = null;
        if ($this->correctionDriven) {
            $conv = AssistantConversation::where('context_type', 'ticket_correction')
                ->where('context_id', $ticket->id)
                ->latest()
                ->first();

            if ($conv !== null) {
                // reorder() clears the messages() relation's default orderBy('id') ASC — without
                // it, ->latest() only appends a SECONDARY sort and the ASC default still wins,
                // returning the OLDEST message (wrong provenance with >1 same-day correction).
                $latestUserMessage = $conv->messages()->where('role', 'user')->reorder()->orderByDesc('id')->first();
                $correctionContext = [
                    'conversation_id' => $conv->id,
                    'operator_id' => $conv->user_id,
                    'summary' => mb_substr((string) optional($latestUserMessage)->content, 0, 200),
                ];
            }
        }

        // 10. Wake the agent to reason and (maybe) propose a close.
        app(TechnicianAgent::class)->run($ticket, $correctionContext);
    }
}
