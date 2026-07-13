<?php

namespace App\Services\Triage;

use App\Enums\NoteType;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TriageRun;
use App\Services\Ai\AiClient;
use App\Support\TriageConfig;
use Illuminate\Support\Facades\Log;

/**
 * Conversation Review mode.
 * Periodically reviews open tickets to detect resolved, stale, or waiting tickets.
 * Can auto-close resolved/junk tickets when enabled via settings.
 */
class ConversationReviewer
{
    // Priority-based cooldown periods (hours) before re-reviewing a ticket.
    private const COOLDOWN_HOURS = [
        'p1' => 4,
        'p2' => 4,
        'p3' => 12,
        'p4' => 24,
    ];

    /**
     * Review a ticket's conversation history and assess its current state.
     */
    public static function review(
        Ticket $ticket,
        AiClient $ai,
        \App\Services\TicketService $ticketService,
        bool $isManual = false,
    ): array {
        // Skip cooldown and human-touched checks for manual triggers
        if (! $isManual) {
            // Check cooldown — skip if reviewed too recently
            if (self::isWithinCooldown($ticket)) {
                Log::debug('[Triage] Review skipped — within cooldown', ['ticket_id' => $ticket->id]);

                return ['skipped' => true, 'reason' => 'cooldown'];
            }

            // Skip if a human touched this ticket in the last 4 hours
            if (self::wasRecentlyHumanTouched($ticket)) {
                Log::debug('[Triage] Review skipped — human touched recently', ['ticket_id' => $ticket->id]);

                return ['skipped' => true, 'reason' => 'human_touched'];
            }
        }

        $ticket->loadMissing(['client', 'contact', 'assignee', 'notes.author']);

        // Build conversation history for AI review
        $context = ContextBuilder::buildForTicket($ticket);
        $system = Prompts::REVIEW_SYSTEM_PROMPT."\n\n".$context;

        Log::info('[Triage] Running conversation review', ['ticket_id' => $ticket->id]);

        $data = $ai->completeJson($system, 'Review this ticket\'s conversation history and provide your assessment as JSON.');

        $result = ReviewResult::fromArray($data);

        Log::info('[Triage] Review result', [
            'ticket_id' => $ticket->id,
            'assessment' => $result->assessment,
            'confidence' => $result->confidence,
            'confidence_score' => $result->confidenceScore,
        ]);

        // Take action based on assessment and settings
        $actionTaken = self::takeAction($ticket, $result, $ticketService);

        // Always write a note (even if action was taken)
        self::writeRecommendation($ticket, $result, $ticketService, $actionTaken);

        $resultArray = $result->toArray();
        $resultArray['action_taken'] = $actionTaken;

        return $resultArray;
    }

    /**
     * Take automated action based on the review assessment.
     * Returns a string describing the action taken, or null if none.
     */
    private static function takeAction(
        Ticket $ticket,
        ReviewResult $result,
        \App\Services\TicketService $ticketService,
    ): ?string {
        // Auto-close requires the setting to be enabled; when the agent is on it owns
        // closing via the audited, human-approvable propose_close path — this un-gated
        // review auto-close stands down (even in held-only mode, the held phase IS the
        // calibration; all closes route through the agent's gate).
        if (! TriageConfig::reviewAutoCloseEnabled() || \App\Support\AgentConfig::enabled()) {
            return null;
        }

        $threshold = TriageConfig::reviewAutoCloseThreshold();

        // Only auto-close for resolved or junk assessments above the threshold
        if (! in_array($result->assessment, ['resolved', 'junk'])) {
            return null;
        }

        // Guard against race condition: ticket may have been closed between
        // job dispatch and execution
        $ticket->refresh();
        if (in_array($ticket->status, [TicketStatus::Closed, TicketStatus::Resolved])) {
            return null;
        }

        if (! $result->meetsThreshold($threshold)) {
            Log::info('[Triage] Review below auto-close threshold', [
                'ticket_id' => $ticket->id,
                'assessment' => $result->assessment,
                'score' => $result->confidenceScore,
                'threshold' => $threshold,
            ]);

            return null;
        }

        $systemUserId = TriageConfig::systemUserId();
        if (! $systemUserId) {
            return null;
        }

        try {
            $ticketService->changeStatus(
                $ticket,
                TicketStatus::Closed,
                $systemUserId,
                "Auto-closed by AI review: {$result->assessment} (confidence: {$result->confidenceScore}%)",
            );

            Log::info('[Triage] Ticket auto-closed by review', [
                'ticket_id' => $ticket->id,
                'assessment' => $result->assessment,
                'confidence_score' => $result->confidenceScore,
                'threshold' => $threshold,
            ]);

            return "auto_closed ({$result->assessment}, {$result->confidenceScore}%)";
        } catch (\Throwable $e) {
            Log::error('[Triage] Failed to auto-close ticket', [
                'ticket_id' => $ticket->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Check if the ticket was reviewed within its priority-based cooldown period.
     */
    private static function isWithinCooldown(Ticket $ticket): bool
    {
        $priorityValue = $ticket->priority instanceof TicketPriority
            ? $ticket->priority->value
            : ($ticket->priority ?? 'p3');

        $cooldownHours = self::COOLDOWN_HOURS[$priorityValue] ?? 24;

        $lastReview = TriageRun::where('ticket_id', $ticket->id)
            ->where('mode', 'review')
            ->where('status', 'completed')
            ->whereRaw("JSON_EXTRACT(ai_tokens_used, '$.input_tokens') > 0")
            ->latest()
            ->first();

        if (! $lastReview) {
            return false;
        }

        return $lastReview->completed_at?->diffInHours(now()) < $cooldownHours;
    }

    /**
     * Check if a human touched this ticket in the last 4 hours.
     * "Touched" = added a note, changed status, or updated the ticket.
     */
    private static function wasRecentlyHumanTouched(Ticket $ticket): bool
    {
        $systemUserId = TriageConfig::systemUserId();
        $fourHoursAgo = now()->subHours(4);

        // Check for human notes in the last 4 hours
        // Includes: staff notes (author_id set, not system user) and portal/email replies (who_type = EndUser)
        $humanNote = $ticket->notes()
            ->where('noted_at', '>=', $fourHoursAgo)
            ->whereNotIn('note_type', [
                NoteType::AiTriage->value,
                NoteType::System->value,
                NoteType::StatusChange->value,
            ])
            ->where(function ($q) use ($systemUserId) {
                $q->where(function ($q2) use ($systemUserId) {
                    // psa-3s7a: when no AI actor resolves ($systemUserId is null), EVERY authored
                    // note is by definition a human's. Guard the exclusion — a bare
                    // `author_id != NULL` is never true in SQL, which would silently make this
                    // match nothing and stop the reviewer skipping human-touched tickets.
                    $q2->whereNotNull('author_id')
                        ->when($systemUserId !== null, fn ($q3) => $q3->where('author_id', '!=', $systemUserId));
                })->orWhere('who_type', \App\Enums\WhoType::EndUser);
            })
            ->exists();

        if ($humanNote) {
            return true;
        }

        return false;
    }

    /**
     * Write the review recommendation as an AI triage note.
     */
    private static function writeRecommendation(
        Ticket $ticket,
        ReviewResult $result,
        \App\Services\TicketService $ticketService,
        ?string $actionTaken,
    ): void {
        $systemUserId = TriageConfig::systemUserId();
        if (! $systemUserId) {
            return;
        }

        $labels = [
            'resolved' => 'Appears Resolved',
            'waiting_customer' => 'Waiting on Customer',
            'waiting_us' => 'Needs Our Attention',
            'junk' => 'Appears to be Junk',
            'active' => 'Actively Being Worked',
        ];

        $label = $labels[$result->assessment] ?? $result->assessment;

        $note = "AI Review: {$label} ({$result->confidenceScore}% confidence)\n"
            ."Reasoning: {$result->reasoning}";

        if ($actionTaken) {
            $note .= "\n\nAction taken: Ticket auto-closed.";
        } elseif ($result->assessment === 'resolved' && $result->isHighConfidence()) {
            $note .= "\n\nSuggestion: This ticket appears resolved. Consider closing it.";
        } elseif ($result->assessment === 'waiting_customer') {
            $note .= "\n\nSuggestion: We're waiting on the customer. Consider following up if no response.";
        } elseif ($result->assessment === 'waiting_us') {
            $note .= "\n\nSuggestion: The customer is waiting on us. This needs attention.";
        }

        $ticketService->addNote(
            $ticket,
            $note,
            NoteType::AiTriage,
            true, // private
            $systemUserId,
        );
    }
}
