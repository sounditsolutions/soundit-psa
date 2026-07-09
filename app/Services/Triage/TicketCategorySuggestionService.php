<?php

namespace App\Services\Triage;

use App\Enums\CategorySuggestionStatus;
use App\Models\Ticket;
use App\Models\TicketCategorySuggestion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Lifecycle management for AI-suggested ticket categories (psa-xop / GitHub #80).
 *
 * The triage `set_ticket_category` tool routes suggestions here when the
 * approval workflow is enabled ({@see \App\Support\TriageConfig::categoryApprovalEnabled()}).
 * Staff then approve (apply the category to the ticket) or reject from the queue.
 */
class TicketCategorySuggestionService
{
    /**
     * Record an AI-suggested category for staff approval.
     *
     * At most one pending suggestion exists per ticket: a fresh suggestion
     * supersedes any prior pending one (the AI may re-triage the same ticket),
     * so the queue never accumulates stale duplicates.
     */
    public function suggest(Ticket $ticket, string $category, ?string $subcategory = null): TicketCategorySuggestion
    {
        $subcategory = ($subcategory !== null && $subcategory !== '') ? $subcategory : null;

        $suggestion = TicketCategorySuggestion::query()
            ->where('ticket_id', $ticket->id)
            ->where('status', CategorySuggestionStatus::Pending)
            ->first();

        if ($suggestion) {
            $suggestion->update([
                'category' => $category,
                'subcategory' => $subcategory,
            ]);
        } else {
            $suggestion = TicketCategorySuggestion::create([
                'ticket_id' => $ticket->id,
                'category' => $category,
                'subcategory' => $subcategory,
                'status' => CategorySuggestionStatus::Pending,
            ]);
        }

        Log::info('[Triage] Category suggestion recorded for approval', [
            'ticket_id' => $ticket->id,
            'suggestion_id' => $suggestion->id,
            'category' => $category,
            'subcategory' => $subcategory,
        ]);

        return $suggestion;
    }

    /**
     * Approve a pending suggestion: apply the category to the ticket and stamp the review.
     * No-op if the suggestion is not pending (idempotent against double-submits).
     */
    public function approve(TicketCategorySuggestion $suggestion, int $reviewerId): void
    {
        if ($suggestion->status !== CategorySuggestionStatus::Pending) {
            return;
        }

        DB::transaction(function () use ($suggestion, $reviewerId) {
            $ticket = $suggestion->ticket;

            if ($ticket) {
                $ticket->update([
                    'category' => $suggestion->category,
                    'subcategory' => $suggestion->subcategory,
                ]);
            }

            $suggestion->update([
                'status' => CategorySuggestionStatus::Approved,
                'reviewed_by' => $reviewerId,
                'reviewed_at' => now(),
            ]);
        });

        Log::info('[Triage] Category suggestion approved', [
            'ticket_id' => $suggestion->ticket_id,
            'suggestion_id' => $suggestion->id,
            'reviewer_id' => $reviewerId,
        ]);
    }

    /**
     * Reject a pending suggestion without altering the ticket.
     * No-op if the suggestion is not pending.
     */
    public function reject(TicketCategorySuggestion $suggestion, int $reviewerId): void
    {
        if ($suggestion->status !== CategorySuggestionStatus::Pending) {
            return;
        }

        $suggestion->update([
            'status' => CategorySuggestionStatus::Rejected,
            'reviewed_by' => $reviewerId,
            'reviewed_at' => now(),
        ]);

        Log::info('[Triage] Category suggestion rejected', [
            'ticket_id' => $suggestion->ticket_id,
            'suggestion_id' => $suggestion->id,
            'reviewer_id' => $reviewerId,
        ]);
    }

    /**
     * Number of suggestions awaiting review (drives the sidebar badge).
     */
    public function pendingCount(): int
    {
        return TicketCategorySuggestion::query()
            ->where('status', CategorySuggestionStatus::Pending)
            ->count();
    }
}
