<?php

namespace App\Services\Technician;

use App\Enums\NoteType;
use App\Enums\TechnicianRunState;
use App\Enums\WhoType;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Services\TicketResolutionDrafter;
use App\Support\AiConfig;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * The Phase-1 "safe core" brain (spec §4.2/§6). After the acknowledgment it judges
 * ownability and proposes a resolution, recorded as a HELD awaiting_approval gate
 * action carrying its text on a run row for the cockpit (Plan 1B). It NEVER sends
 * anything substantive: propose_resolution is Approve tier (default-deny) with no
 * grant, so the gate records awaiting_approval without executing. Budget-guarded,
 * idempotent, fail-closed.
 *
 * A2b: the client-REPLY drafting this pipeline used to do is RETIRED — the reactive
 * agent's send_reply tool is the sole producer of held replies now. This class is
 * reduced to the propose_resolution branch (Mayor Decision A: keep it, no feature loss).
 */
class DraftPipeline
{
    public function __construct(
        private readonly TechnicianActionGate $gate,
        private readonly TechnicianClassifier $classifier,
        private readonly TicketResolutionDrafter $resolutionDrafter,
        private readonly TechnicianBudget $budget,
    ) {}

    public function run(Ticket $ticket): void
    {
        // Fail-closed: no AI, AI globally disabled, or budget reached → hold (v2 adds isEnabled()).
        if (! AiConfig::isConfigured() || ! AiConfig::isEnabled()) {
            return;
        }

        if ($this->budget->dailyLimitReached()) {
            Log::info('[Technician] Daily token budget reached — holding draft pipeline', ['ticket_id' => $ticket->id]);

            return;
        }

        if (\App\Models\TechnicianEmergency::hasOpenEmergency($ticket)) {
            Log::info('[Technician] Open emergency — halting autonomous pipeline', ['ticket_id' => $ticket->id]);

            return;
        }

        // A2b: the client-REPLY drafting that used to live here is RETIRED — the reactive
        // agent's send_reply tool is the SOLE producer of held replies now (so the two can
        // never double-produce). What remains is the held resolution proposal. The
        // unaddressed-client-reply gate is preserved as resolution's existing trigger: only
        // propose after a genuine new client message, so a job retry with nothing new is a
        // no-op. (The supersede of stale held replies moved with the reply, into SendReplyTool.)
        if (! $this->hasUnaddressedClientReply($ticket)) {
            return;
        }

        $assessment = $this->classifier->classify($ticket);

        if (! $assessment->ownable) {
            Log::info('[Technician] Ticket not ownable — leaving for a human', [
                'ticket_id' => $ticket->id,
                'confidence' => $assessment->confidence,
                'reasons' => $assessment->reasons,
            ]);

            return;
        }

        // Proposed resolution — ONLY when there is genuine client substance to resolve
        // (a real, non-AI reply), NOT at intake where the lone Reply note is the bot's
        // own ack (which would fire a needless AI call + a WikiRun on every inbound). (v2)
        if ($this->hasClientSubstance($ticket)) {
            $resolution = $this->resolutionDrafter->draft($ticket, 'technician');
            if (is_string($resolution) && trim($resolution) !== '') {
                $this->recordHeld(
                    $ticket,
                    'propose_resolution',
                    $resolution,
                    ['reasons' => $assessment->reasons],
                    $assessment->confidence,
                    0, // resolution tokens are governed by WikiBudget, not TechnicianBudget
                    'Proposed a resolution (awaiting approval).',
                );
            }
        }
    }

    /**
     * True when the latest client (non-AI EndUser) reply is newer than our latest
     * propose_resolution run — i.e. a client message we haven't yet proposed a resolution
     * for. This is the pre-AI short-circuit: a job retry with nothing new stays a no-op
     * (no re-spend), while a genuine new client reply re-opens the resolution proposal.
     *
     * A2b: this now keys on the pipeline's OWN propose_resolution output (it formerly keyed
     * on send_reply, which the agent produces now — keying on that would never short-circuit
     * here and would re-spend AI on every run).
     */
    private function hasUnaddressedClientReply(Ticket $ticket): bool
    {
        $latestClientReply = $ticket->notes()
            ->where('note_type', NoteType::Reply->value)
            ->where('ai_authored', false)
            ->where('who_type', WhoType::EndUser->value)
            ->latest('noted_at')
            ->first();

        $latestResolutionRun = TechnicianRun::where('ticket_id', $ticket->id)
            ->where('action_type', 'propose_resolution')
            ->latest('created_at')
            ->first();

        if (! $latestClientReply) {
            return $latestResolutionRun === null; // intake: act once
        }

        return $latestResolutionRun === null
            || $latestResolutionRun->created_at === null
            || $latestResolutionRun->created_at->lt($latestClientReply->noted_at);
    }

    /** True when a real (non-AI) client/human Reply note exists — distinct from the bot's ack. */
    private function hasClientSubstance(Ticket $ticket): bool
    {
        return $ticket->notes()
            ->where('note_type', NoteType::Reply)
            ->where('ai_authored', false)
            ->exists();
    }

    /**
     * Persist the held draft on a run (for the cockpit) and record the held action
     * through the gate exactly once (on fresh creation), fail-closed.
     *
     * @param  array<string, mixed>  $meta
     */
    private function recordHeld(
        Ticket $ticket,
        string $actionType,
        string $content,
        array $meta,
        float $confidence,
        int $tokensUsed,
        string $summary,
    ): void {
        $hash = hash('sha256', $actionType.':'.$ticket->id.':'.$content);

        $run = TechnicianRun::firstOrCreate(
            [
                'ticket_id' => $ticket->id,
                'action_type' => $actionType,
                'content_hash' => $hash,
            ],
            [
                'client_id' => $ticket->client_id,
                'state' => TechnicianRunState::AwaitingApproval,
                'proposed_content' => $content,
                'proposed_meta' => $meta,
                'confidence' => $confidence,
                'tokens_used' => $tokensUsed,
            ],
        );

        // Idempotent: only record the held action the first time we create the run — OR
        // when we find a stale (Superseded/Done/Denied) run with the same content hash
        // and revive it so the cockpit is never left with zero drafts after a nudge.
        if (! $run->wasRecentlyCreated) {
            if ($run->state !== TechnicianRunState::AwaitingApproval) {
                // Revive: the identical-body superseded/done/denied run must be re-presented.
                $run->update([
                    'state' => TechnicianRunState::AwaitingApproval->value,
                    'proposed_content' => $content,
                    'proposed_meta' => $meta,
                    'confidence' => $confidence,
                    'tokens_used' => $tokensUsed,
                ]);
            } else {
                // Already awaiting approval this turn — idempotent, do not re-dispatch.
                return;
            }
        }

        $this->gate->dispatch(
            actionType: $actionType,
            ticketId: $ticket->id,
            clientId: $ticket->client_id,
            contentHash: $hash,
            summary: $summary,
            runId: $run->id,
            // Tripwire: Approve-tier-without-grant means the gate records
            // awaiting_approval WITHOUT calling this. If it ever runs, a
            // misconfigured AUTO tier is trying to auto-send — fail loudly.
            executor: function () use ($actionType): void {
                throw new LogicException("[Technician] {$actionType} must not auto-execute in Phase 1A (it is hold-for-approval).");
            },
        );
    }
}
