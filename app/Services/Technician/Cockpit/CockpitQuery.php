<?php

namespace App\Services\Technician\Cockpit;

use App\Enums\NoteType;
use App\Enums\TechnicianRunState;
use App\Enums\TicketStatus;
use App\Enums\WhoType;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use Illuminate\Support\Collection;

/**
 * The cockpit's read model (Plan 1B). Two lanes the away operator must see in
 * one place: the held drafts to approve, and the tickets the AI could NOT draft
 * (so nothing falls through). Pure queries — no side effects.
 */
class CockpitQuery
{
    public function pendingCount(): int
    {
        // Everything the away operator must act on in the cockpit: executable
        // proposals (AwaitingApproval) AND held flags (Flagged). Feeds the nav badge.
        return TechnicianRun::whereIn('state', [
            TechnicianRunState::AwaitingApproval->value,
            TechnicianRunState::Flagged->value,
        ])->count();
    }

    /**
     * The "Flagged for your attention" lane (Increment H): held flag_attention
     * notices the agent raised when it judged a ticket over its head. Distinct from
     * the approval lane — these are NOT executable; a human acknowledges or dismisses
     * them. Oldest first. Pure query.
     */
    public function flaggedForAttention(): Collection
    {
        return TechnicianRun::query()
            ->where('action_type', 'flag_attention')
            ->where('state', TechnicianRunState::Flagged->value)
            ->with(['ticket.client'])
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Held intake suggestions awaiting operator review (psa-xcyo Task 3).
     * Surfaces intake_route AwaitingApproval runs so the operator can calibrate
     * the auto-attach threshold. Visibility only — no merge action (deferred).
     */
    public function intakeReview(): Collection
    {
        return TechnicianRun::query()
            ->where('action_type', 'intake_route')
            ->where('state', TechnicianRunState::AwaitingApproval->value)
            ->with('ticket')
            ->latest()
            ->limit(20)
            ->get();
    }

    /**
     * Suspected-spam calls awaiting operator review (psa-xcyo Task 6b).
     * Surfaces un-actioned calls flagged by the AI intake spam assessor so the
     * operator can one-tap mark-followed-up + block. A call leaves this lane as
     * soon as followed_up_at is set (by the block action or the plain dismiss).
     */
    public function intakeSpamReview(): Collection
    {
        return \App\Models\PhoneCall::query()
            ->whereNotNull('intake_spam_score')
            ->whereNull('followed_up_at')
            ->whereNull('ticket_id')
            ->whereNull('client_id')
            ->latest('id')
            ->limit(20)
            ->get();
    }

    public function pendingDrafts(): Collection
    {
        return TechnicianRun::query()
            ->where('state', TechnicianRunState::AwaitingApproval->value)
            // intake_route runs go to the dedicated Intake lane, not the approval queue.
            // flag_attention runs are Flagged-state so excluded by the state filter above,
            // but explicitly excluded here for clarity and future-safety.
            ->whereNotIn('action_type', ['intake_route', 'flag_attention'])
            ->with(['ticket.client', 'ticket.contact'])
            ->get()
            ->sortBy(fn (TechnicianRun $run) => [
                // Lane 0 = client-facing text approvals; Lane 1 = structural proposals.
                // A stale close/merge proposal must never preempt a time-sensitive reply approval.
                in_array($run->action_type, ['send_reply', 'propose_resolution', 'stage_email', 'stage_public_note'], true) ? 0 : 1,
                $this->isOverdue($run->ticket) ? 0 : 1,          // overdue first within lane
                optional($run->created_at)->getTimestamp() ?? 0,  // oldest first within lane
            ])
            ->values();
    }

    public function needsAttention(): Collection
    {
        $openStatuses = $this->openStatuses();

        return Ticket::query()
            ->whereIn('status', $openStatuses)
            ->whereHas('client', fn ($q) => $q->where('is_active', true))
            // The AI acked it (an AI-authored reply note exists)...
            ->whereHas('notes', fn ($q) => $q->where('ai_authored', true)->where('note_type', NoteType::Reply->value))
            // ...but there is no LIVE held reply draft for it...
            ->whereDoesntHave('technicianRuns', fn ($q) => $q
                ->where('action_type', 'send_reply')
                ->where('state', TechnicianRunState::AwaitingApproval->value))
            // ...and no non-AI staff reply has been added SINCE the AI ack (a human engaged after).
            // A human reply that pre-dates the ack is irrelevant — the AI saw it and still acked.
            ->whereDoesntHave('notes', fn ($q) => $q
                ->where('note_type', NoteType::Reply->value)
                ->where('ai_authored', false)
                ->where('who_type', WhoType::Agent->value)
                ->where('noted_at', '>', function ($sub) {
                    $sub->selectRaw('MAX(noted_at)')
                        ->from('ticket_notes')
                        ->whereColumn('ticket_id', 'tickets.id')
                        ->where('ai_authored', true)
                        ->where('note_type', NoteType::Reply->value);
                }))
            ->with(['client', 'contact'])
            ->orderBy('updated_at')
            ->get();
    }

    private function isOverdue(?Ticket $ticket): bool
    {
        return $ticket?->due_at !== null && $ticket->due_at->isPast();
    }

    /** @return array<int,int> the non-terminal ticket status values */
    private function openStatuses(): array
    {
        return collect(TicketStatus::cases())
            ->reject(fn (TicketStatus $s) => in_array($s, [TicketStatus::Closed, TicketStatus::Resolved], true))
            ->map(fn (TicketStatus $s) => $s->value)
            ->all();
    }
}
