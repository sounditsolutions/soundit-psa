<?php

namespace App\Jobs;

use App\Enums\TechnicianRunState;
use App\Models\AssistantConversation;
use App\Models\Client;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Services\Agent\SignificanceGate;
use App\Services\Agent\Steering\LeaveItOutcomeRecorder;
use App\Services\Agent\Steering\LessonCapture;
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
 *   3. Active re-check (CO-8): client record must still be active.
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

        // 3. Active re-check (CO-8): prospects are AI-visible, but deactivated
        //    client records are still out.
        if (! Client::active()->whereKey($ticket->client_id)->exists()) {
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
        //    Skipped for correction-driven runs (psa-rmus): a correction SUPERSEDES an existing
        //    proposal (replaces, doesn't add to the flood), and the operator explicitly asked —
        //    so the anti-flood cap must not silently drop their correction.
        if (! $this->correctionDriven) {
            if (TechnicianRun::where('action_type', 'propose_close')
                ->where('state', TechnicianRunState::AwaitingApproval)
                ->count() >= AgentConfig::maxPendingProposals()) {
                return;
            }
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
        //    Skipped for correction-driven runs (psa-rmus, P1): this "is it worth the agent's
        //    attention?" filter exists for the AUTONOMOUS review pass — it must NEVER veto an
        //    EXPLICIT operator correction (which silently no-op'd real operator corrections on
        //    prod). An operator who declined + corrected has already decided it's worth a look.
        if (! $this->correctionDriven) {
            if (! app(SignificanceGate::class)->assess($ticket)) {
                return;
            }
        }

        // 9. Resolve correction context (correction-driven runs only) so the agent can
        //    thread provenance into the resulting TechnicianRun's proposed_meta.
        //    $correctionConversation is hoisted outside the if so step 11 can reference it.
        $correctionContext = null;
        $correctionConversation = null;
        if ($this->correctionDriven) {
            $correctionConversation = AssistantConversation::where('context_type', 'ticket_correction')
                ->where('context_id', $ticket->id)
                ->latest()
                ->first();

            if ($correctionConversation !== null) {
                // reorder() clears the messages() relation's default orderBy('id') ASC — without
                // it, ->latest() only appends a SECONDARY sort and the ASC default still wins,
                // returning the OLDEST message (wrong provenance with >1 same-day correction).
                $latestUserMessage = $correctionConversation->messages()->where('role', 'user')->reorder()->orderByDesc('id')->first();
                $correctionContext = [
                    'conversation_id' => $correctionConversation->id,
                    'operator_id' => $correctionConversation->user_id,
                    'summary' => mb_substr((string) optional($latestUserMessage)->content, 0, 200),
                ];
            }
        }

        // 10. Wake the agent to reason and (maybe) propose a close.
        $outcome = app(TechnicianAgent::class)->run($ticket, $correctionContext);

        // 10.5. VISIBLE LEAVE-IT (psa-3q0c, psa-rmus FIX 2): when the operator's correction re-assessed
        //       this ticket and the agent produced NO new proposal (chose to leave it as-is), the old
        //       proposal was already superseded — so the cockpit card would just VANISH with no trace.
        //       Record the reasoned "left as-is" outcome as an assistant turn on the SAME
        //       ticket_correction conversation so the operator sees an outcome, never silence. Only on a
        //       correction-driven run that actually assessed and left it. ($outcome is null only under a
        //       test mock; the real run() always returns an outcome.) Recorder is fail-soft internally.
        if ($this->correctionDriven && $correctionConversation !== null && $outcome?->leftAsIs()) {
            app(LeaveItOutcomeRecorder::class)->record($correctionConversation, $outcome->narration);
        }

        // 11. LEARN (psa-ck6x): distill the operator's correction into a durable wiki fact so the agent
        //     never needs that correction twice. Reached ONLY on a correction-driven run that already
        //     passed the unconditional dormancy (#1) + emergency-halt (#4.5) guards above, and only after
        //     the agent actually re-assessed (step 10). LessonCapture is fail-soft internally.
        if ($this->correctionDriven && $correctionConversation !== null) {
            app(LessonCapture::class)->capture($ticket, $correctionConversation);
        }
    }
}
