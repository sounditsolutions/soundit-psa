<?php

namespace App\Services\Technician\Cockpit;

use App\Enums\NoteType;
use App\Enums\TechnicianRunState;
use App\Enums\TicketStatus;
use App\Enums\WhoType;
use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use App\Models\PhoneCall;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Services\Agent\Steering\LeaveItOutcomeRecorder;
use Illuminate\Support\Collection;

/**
 * The cockpit's read model (Plan 1B). Two lanes the away operator must see in
 * one place: the held drafts to approve, and the tickets the AI could NOT draft
 * (so nothing falls through). Pure queries — no side effects.
 */
class CockpitQuery
{
    private const REPLY_ACTIONS = [
        'send_reply',
        'propose_resolution',
        'stage_email',
        'stage_public_note',
    ];

    private const CLOSURE_ACTIONS = [
        'propose_close',
        'propose_merge',
    ];

    /** How far back the "re-assessed → left as-is" lane looks (psa-3q0c). Self-clearing. */
    private const LEAVE_IT_WINDOW_HOURS = 48;

    /**
     * How far back the "closed directly by the agent" lane looks (psa-y4ft.1), and
     * therefore how long the one-click reopen stays offered. Public: the reopen
     * endpoint enforces the SAME window server-side so a stale card (or a replayed
     * form) can never reopen an ancient close.
     */
    public const DIRECT_CLOSE_WINDOW_HOURS = 48;

    /**
     * The states that mean "a human still owes this a decision" — the single
     * definition of PENDING. Everything the away operator should see on the nav
     * badge: executable proposals (AwaitingApproval), held flags (Flagged), and
     * — bd psa-xr84 — offline-queued actions (QueuedOffline, cancellable) plus
     * Expired ones that need an explicit re-confirm, so an expired action can't
     * go unnoticed by an operator who never opens /cockpit.
     *
     * Public because the get_staged_action_status MCP read (psa-gq7by) reports
     * the same set: an agent asking "what is still awaiting a human?" must never
     * drift from the number the cockpit badge shows that human.
     */
    public const PENDING_STATES = [
        TechnicianRunState::AwaitingApproval->value,
        TechnicianRunState::Flagged->value,
        TechnicianRunState::QueuedOffline->value,
        TechnicianRunState::Expired->value,
    ];

    public function pendingCount(): int
    {
        // Matches counts()'s pending/total fold-in.
        return TechnicianRun::whereIn('state', self::PENDING_STATES)->count();
    }

    /** @return array{replies:int,closures:int,actions:int,intake:int,flagged:int,needs:int,queued:int,pending:int,total:int} */
    public function counts(): array
    {
        $draftActionTypes = TechnicianRun::query()
            ->where('state', TechnicianRunState::AwaitingApproval->value)
            ->whereNotIn('action_type', ['intake_route', 'flag_attention'])
            ->pluck('action_type');

        $replies = $draftActionTypes
            ->filter(fn (string $type): bool => in_array($type, self::REPLY_ACTIONS, true))
            ->count();
        $closures = $draftActionTypes
            ->filter(fn (string $type): bool => in_array($type, self::CLOSURE_ACTIONS, true))
            ->count();
        $actions = $draftActionTypes
            ->filter(fn (string $type): bool => $this->isEndpointOrAccountAction($type))
            ->count();
        $intake = $this->intakeReviewCount() + $this->intakeSpamReviewCount();
        $flagged = $this->flaggedForAttention()->count();
        $needs = $this->needsAttention()->count();
        // bd psa-xr84 — offline-script queue: an approved action parked because its
        // device was offline (QueuedOffline) still needs an eye kept on it (cancel is
        // available), and one whose safety window elapsed (Expired) needs an explicit
        // re-confirm. Folded into pending/total so the "you're all clear" empty state
        // never fires while a queue item is still awaiting operator attention.
        $queued = TechnicianRun::query()
            ->whereIn('state', [TechnicianRunState::QueuedOffline->value, TechnicianRunState::Expired->value])
            ->count();

        $pending = $replies + $closures + $actions + $intake + $flagged + $queued;
        $total = $pending + $needs;

        return [
            'replies' => $replies,
            'closures' => $closures,
            'actions' => $actions,
            'intake' => $intake,
            'flagged' => $flagged,
            'needs' => $needs,
            'queued' => $queued,
            'pending' => $pending,
            'total' => $total,
        ];
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
        return $this->intakeReviewQuery()
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
        return $this->intakeSpamReviewQuery()
            ->latest('id')
            ->limit(20)
            ->get();
    }

    private function intakeReviewCount(): int
    {
        return $this->intakeReviewQuery()->count();
    }

    private function intakeSpamReviewCount(): int
    {
        return $this->intakeSpamReviewQuery()->count();
    }

    private function intakeReviewQuery()
    {
        return TechnicianRun::query()
            ->where('action_type', 'intake_route')
            ->where('state', TechnicianRunState::AwaitingApproval->value);
    }

    private function intakeSpamReviewQuery()
    {
        return PhoneCall::query()
            ->whereNotNull('intake_spam_score')
            ->whereNull('followed_up_at')
            ->whereNull('ticket_id')
            ->whereNull('client_id');
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

    /**
     * Approved staged actions parked because their target device was offline at
     * approval time (bd psa-xr84). Soonest-to-expire first — the operator should
     * see the most time-sensitive queue item first. Pure query.
     */
    public function queuedOffline(): Collection
    {
        return TechnicianRun::query()
            ->where('state', TechnicianRunState::QueuedOffline->value)
            ->with(['ticket.client', 'ticket.contact'])
            ->orderBy('expires_at')
            ->get();
    }

    /**
     * Queued actions whose safety window elapsed before the device came back
     * online (bd psa-xr84). These never auto-ran; they are re-surfaced here so
     * the operator can explicitly re-confirm (→ back into the approval queue)
     * or leave them be. Newest first — ordered by updated_at (not created_at):
     * the CAS transition into Expired stamps updated_at, so this is "most
     * recently expired first", not "most recently staged first". Pure query.
     */
    public function expiredQueue(): Collection
    {
        return TechnicianRun::query()
            ->where('state', TechnicianRunState::Expired->value)
            ->with(['ticket.client', 'ticket.contact'])
            ->orderBy('updated_at', 'desc')
            ->get();
    }

    /**
     * Autonomous DIRECT closes (set_ticket_status → Closed by the agent) within the
     * recent window whose ticket is STILL Closed (psa-y4ft.1). The held propose_close
     * path gets a one-click undo toast at approval time; a direct close executes with
     * no operator present, so the same reversibility is offered here as a durable
     * card with one-click Reopen. Self-clearing: a reopen by ANY path (cockpit,
     * ticket UI) drops the card via the ticket-status check, a cockpit reversal also
     * lands the run in Denied, and the rest age out of the window. Newest first.
     * Informational lane — deliberately NOT folded into counts()/pendingCount()
     * (the close already happened; nothing is pending). Pure query.
     */
    public function recentDirectCloses(): Collection
    {
        return TechnicianRun::query()
            ->where('action_type', 'direct_close')
            ->where('state', TechnicianRunState::Done->value)
            ->where('created_at', '>=', now()->subHours(self::DIRECT_CLOSE_WINDOW_HOURS))
            ->whereHas('ticket', fn ($q) => $q->where('status', TicketStatus::Closed->value))
            ->with(['ticket.client'])
            ->orderByDesc('created_at')
            ->get();
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

    /**
     * "Re-assessed from your correction → left as-is" (psa-3q0c). When an operator declines +
     * corrects a proposal and the re-assessment produces NO new proposal, the superseded card
     * would just vanish. The agent's leave-it decision is recorded as an assistant turn on the
     * ticket_correction conversation (LeaveItOutcomeRecorder); this lane surfaces the recent ones
     * so a correction never looks like it did nothing.
     *
     * We show a correction thread only when its MOST RECENT turn is an assistant leave-it (a newer
     * operator correction — a user turn — or a new proposal supersedes it), within a recent window,
     * for a still-open ticket on an active client. One (newest) entry per ticket. Pure query.
     *
     * @return Collection<int, object{ticket: Ticket, note: string, at: \Illuminate\Support\Carbon|null}>
     */
    public function reassessedLeftAsIs(): Collection
    {
        // ticket_correction conversations are daily-keyed (a conversation only ever receives
        // messages on its creation day — CorrectionRecorder keys on Y-m-d), so any conversation
        // holding a message inside the window was created inside the window + one day. Bounding
        // the pluck by created_at keeps this hot (per-page-load) query's IN-list from growing
        // unbounded as correction history accumulates. +24h absorbs the day boundary safely.
        $conversationIds = AssistantConversation::query()
            ->where('context_type', 'ticket_correction')
            ->where('created_at', '>=', now()->subHours(self::LEAVE_IT_WINDOW_HOURS + 24))
            ->pluck('id');

        if ($conversationIds->isEmpty()) {
            return collect();
        }

        // The latest message id per correction conversation (selectRaw mirrors needsAttention()).
        $latestIds = AssistantMessage::query()
            ->whereIn('conversation_id', $conversationIds)
            ->selectRaw('MAX(id) as id')
            ->groupBy('conversation_id')
            ->pluck('id');

        // Keep only those whose latest turn is an assistant leave-it within the window.
        $messages = AssistantMessage::query()
            ->whereIn('id', $latestIds)
            ->where('role', 'assistant')
            ->where('content', 'like', LeaveItOutcomeRecorder::NOTE_PREFIX.'%')
            ->where('created_at', '>=', now()->subHours(self::LEAVE_IT_WINDOW_HOURS))
            ->with('conversation')
            ->orderByDesc('created_at')
            ->get();

        if ($messages->isEmpty()) {
            return collect();
        }

        // Resolve tickets by the conversation's context_id — only open tickets on active clients.
        $ticketIds = $messages->pluck('conversation.context_id')->filter()->unique()->values();
        $tickets = Ticket::query()
            ->whereIn('id', $ticketIds)
            ->whereIn('status', $this->openStatuses())
            ->whereHas('client', fn ($q) => $q->where('is_active', true))
            ->with('client')
            ->get()
            ->keyBy('id');

        return $messages
            ->map(function (AssistantMessage $message) use ($tickets): ?object {
                $ticket = $tickets->get($message->conversation?->context_id);
                if ($ticket === null) {
                    return null;
                }

                return (object) [
                    'ticket' => $ticket,
                    'note' => $message->content,
                    'at' => $message->created_at,
                ];
            })
            ->filter()
            ->unique(fn (object $row): int => $row->ticket->id) // newest per ticket (already sorted desc)
            ->values();
    }

    private function isOverdue(?Ticket $ticket): bool
    {
        return $ticket?->due_at !== null && $ticket->due_at->isPast();
    }

    private function isEndpointOrAccountAction(string $actionType): bool
    {
        return str_starts_with($actionType, 'tactical_stage_')
            || str_starts_with($actionType, 'cipp_stage_');
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
