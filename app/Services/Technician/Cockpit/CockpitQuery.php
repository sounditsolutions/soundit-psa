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
        return TechnicianRun::where('state', TechnicianRunState::AwaitingApproval->value)->count();
    }

    public function pendingDrafts(): Collection
    {
        return TechnicianRun::query()
            ->where('state', TechnicianRunState::AwaitingApproval->value)
            ->with(['ticket.client', 'ticket.contact'])
            ->get()
            ->sortBy(fn (TechnicianRun $run) => [
                $this->isOverdue($run->ticket) ? 0 : 1,         // overdue first
                optional($run->created_at)->getTimestamp() ?? 0, // then oldest
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
