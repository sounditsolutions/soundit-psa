<?php

namespace App\Services\Wiki;

use App\Enums\WikiRunStatus;
use App\Enums\WikiRunType;
use App\Jobs\MineTicketKnowledge;
use App\Models\Ticket;
use App\Models\WikiRun;
use App\Support\WikiBudget;
use App\Support\WikiConfig;
use Illuminate\Support\Facades\DB;

class WikiBackfillService
{
    /**
     * Return a dry-run estimate — no jobs dispatched, nothing written.
     *
     * Returns: ticket_count, auto_mine_on, estimated_tokens, daily_ceiling, oldest, newest.
     *
     * @return array{ticket_count: int, auto_mine_on: bool, estimated_tokens: int, daily_ceiling: int, oldest: int|null, newest: int|null}
     */
    public function plan(?int $clientId, int $batch): array
    {
        $tickets = $this->candidates($clientId, $batch);
        $count = $tickets->count();

        return [
            'ticket_count' => $count,
            'auto_mine_on' => WikiConfig::autoMineEnabled(),
            // ≤ today's daily ceiling — backfill is budget-capped, not per-run-cap capped
            'estimated_tokens' => min($count * $this->perTicketEstimate(), WikiConfig::dailyTokenLimit()),
            'daily_ceiling' => WikiConfig::dailyTokenLimit(),
            'oldest' => $tickets->first()?->id,
            'newest' => $tickets->last()?->id,
        ];
    }

    /**
     * Dispatch MineTicketKnowledge jobs for unmined closed tickets, oldest-first,
     * bounded by $batch and the shared daily budget.
     *
     * ADAPTATION: MineTicketKnowledge hard-returns at Gate 1 unless autoMineEnabled().
     * Gating on isEnabled() would dispatch jobs that silently no-op. Gate on autoMineEnabled()
     * so we never report phantom work.
     *
     * @return int number of jobs dispatched
     */
    public function execute(?int $clientId, int $batch): int
    {
        // Mining HARD-gates on autoMineEnabled() (Gate 1 in MineTicketKnowledge); without it
        // every dispatched job no-ops. Gate backfill the SAME way so we never report phantom work.
        if (! WikiConfig::autoMineEnabled()) {
            return 0;
        }

        $dispatched = 0;

        foreach ($this->candidates($clientId, $batch) as $ticket) {
            if (WikiBudget::dailyLimitReached()) {
                break;
            }

            // MineTicketKnowledge constructor: (int $ticketId, string $triggeredBy = 'auto')
            MineTicketKnowledge::dispatch($ticket->id, 'backfill');
            $dispatched++;
        }

        return $dispatched;
    }

    /**
     * Realistic per-ticket token cost from recent mine runs; nominal fallback when history is thin.
     *
     * Uses the last ~20 completed mine_ticket runs' ai_tokens_used (input + output averaged).
     * Fallback to 12_000 nominal when no history exists.
     * Do NOT use maxTokensPerRun — that's the cap (~50k), not the average actual usage.
     */
    private function perTicketEstimate(): int
    {
        $recent = WikiRun::where('run_type', WikiRunType::MineTicket->value)
            ->whereNotNull('ai_tokens_used')
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn ($r) => (int) ($r->ai_tokens_used['input'] ?? 0) + (int) ($r->ai_tokens_used['output'] ?? 0))
            ->filter();

        return $recent->isNotEmpty() ? (int) $recent->avg() : 12_000;
    }

    /**
     * Closed/resolved tickets that haven't been mined to completion yet.
     *
     * - Closed or resolved status (aligns with TicketStatus enum: Closed='closed', Resolved='resolved')
     * - Has a non-null resolution (MineTicketKnowledge returns early on empty resolution)
     * - Optional client filter
     * - Correlated whereNotExists on wiki_runs (no Ticket::wikiRuns relation exists — wiki_runs
     *   stores subject_type='ticket' + subject_id, not a morphTo on Ticket)
     * - Ordered oldest-first (closed_at ASC, then id ASC as tiebreaker) — freshest knowledge lands
     *   last and wins reaffirmations/disputes
     * - Batch-limited
     *
     * @return \Illuminate\Support\Collection<int, Ticket>
     */
    private function candidates(?int $clientId, int $batch): \Illuminate\Support\Collection
    {
        return Ticket::query()
            ->whereIn('status', [
                \App\Enums\TicketStatus::Closed->value,
                \App\Enums\TicketStatus::Resolved->value,
            ])
            ->whereNotNull('resolution')
            ->when($clientId, fn ($q) => $q->where('client_id', $clientId))
            ->whereNotExists(fn ($q) => $q
                ->select(DB::raw(1))
                ->from('wiki_runs')
                ->whereColumn('wiki_runs.subject_id', 'tickets.id')
                ->where('wiki_runs.subject_type', 'ticket')
                ->where('wiki_runs.run_type', WikiRunType::MineTicket->value)
                ->where('wiki_runs.status', WikiRunStatus::Completed->value)
            )
            ->orderBy('closed_at')
            ->orderBy('id')
            ->limit($batch)
            ->get();
    }
}
