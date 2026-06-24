<?php

namespace App\Services\Technician;

use App\Enums\NoteType;
use App\Enums\TechnicianRunState;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Services\TicketResolutionDrafter;
use App\Support\AiConfig;
use App\Support\TechnicianConfig;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * The Phase-1 "safe core" brain (spec §4.2/§6). After the acknowledgment it
 * gathers, judges ownability, drafts a reply + proposes a resolution, and records
 * each as a HELD awaiting_approval gate action carrying its text on a run row for
 * the cockpit (Plan 1B). It NEVER sends anything substantive: send_reply /
 * propose_resolution are Approve tier (default-deny) with no grant, so the gate
 * records awaiting_approval without executing. Budget-guarded, idempotent,
 * fail-closed.
 */
class DraftPipeline
{
    public function __construct(
        private readonly TechnicianActionGate $gate,
        private readonly TechnicianClassifier $classifier,
        private readonly TechnicianReplyDrafter $replyDrafter,
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

        // Idempotency BEFORE any AI spend (v2): if a held action already exists for
        // this ticket, a Loop retry must not re-run the classifier/drafters. (A fresh
        // draft on a NEW client reply is the Plan-1B reply-hook's concern, not a retry.)
        $alreadyDrafted = TechnicianRun::where('ticket_id', $ticket->id)
            ->whereIn('action_type', ['send_reply', 'propose_resolution'])
            ->exists();
        if ($alreadyDrafted) {
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

        $actorName = TechnicianConfig::aiActorName();

        // Substantive reply — HELD for approval (never sent here).
        $draft = $this->replyDrafter->draft($ticket, $actorName);
        if ($draft !== null) {
            $this->recordHeld(
                $ticket,
                'send_reply',
                $draft->body,
                ['to' => $draft->to, 'reasons' => $assessment->reasons],
                $assessment->confidence,
                $assessment->tokensUsed + $draft->tokensUsed,
                'Drafted a client reply (awaiting approval).',
            );
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

        // Idempotent: only record the held action the first time we create the run.
        if (! $run->wasRecentlyCreated) {
            return;
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
