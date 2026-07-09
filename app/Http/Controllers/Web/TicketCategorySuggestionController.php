<?php

namespace App\Http\Controllers\Web;

use App\Enums\CategorySuggestionStatus;
use App\Http\Controllers\Controller;
use App\Models\TicketCategorySuggestion;
use App\Services\Triage\TicketCategorySuggestionService;

/**
 * Staff approval queue for AI-suggested ticket categories (psa-xop / GitHub #80).
 */
class TicketCategorySuggestionController extends Controller
{
    public function index()
    {
        $pending = TicketCategorySuggestion::query()
            ->where('status', CategorySuggestionStatus::Pending)
            ->with(['ticket.client'])
            ->latest()
            ->get();

        $recent = TicketCategorySuggestion::query()
            ->whereIn('status', [CategorySuggestionStatus::Approved, CategorySuggestionStatus::Rejected])
            ->with(['ticket.client', 'reviewer'])
            ->orderByDesc('reviewed_at')
            ->limit(20)
            ->get();

        return view('triage.category-suggestions.index', [
            'pending' => $pending,
            'recent' => $recent,
        ]);
    }

    public function approve(TicketCategorySuggestion $suggestion, TicketCategorySuggestionService $service)
    {
        if ($suggestion->status !== CategorySuggestionStatus::Pending) {
            return redirect()->route('triage.category-suggestions.index')
                ->with('error', 'That suggestion has already been reviewed.');
        }

        $service->approve($suggestion, (int) auth()->id());

        return redirect()->route('triage.category-suggestions.index')
            ->with('success', "Category applied to ticket #{$suggestion->ticket_id}.");
    }

    public function reject(TicketCategorySuggestion $suggestion, TicketCategorySuggestionService $service)
    {
        if ($suggestion->status !== CategorySuggestionStatus::Pending) {
            return redirect()->route('triage.category-suggestions.index')
                ->with('error', 'That suggestion has already been reviewed.');
        }

        $service->reject($suggestion, (int) auth()->id());

        return redirect()->route('triage.category-suggestions.index')
            ->with('success', 'Category suggestion rejected.');
    }
}
