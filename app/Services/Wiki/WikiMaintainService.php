<?php

namespace App\Services\Wiki;

use App\Enums\WikiAuthorType;
use App\Enums\WikiComposeOutcome;
use App\Enums\WikiFactStatus;
use App\Enums\WikiRunStatus;
use App\Enums\WikiRunType;
use App\Models\Client;
use App\Models\Ticket;
use App\Models\WikiFact;
use App\Models\WikiLink;
use App\Models\WikiPage;
use App\Models\WikiRun;
use App\Support\WikiBudget;
use App\Support\WikiConfig;
use Illuminate\Support\Facades\DB;

class WikiMaintainService
{
    /** Safety cap — a garbage ticket must not mass-dispute the ledger in one night. */
    private const MAX_DISPUTES_PER_RUN = 50;

    public function __construct(
        private readonly WikiFactService $facts,
        private readonly WikiOverviewComposer $composer,
    ) {}

    /**
     * Run all five maintenance sweeps and record one ledger row for today.
     *
     * @return array<string, mixed> the stage_results stored on the maintain run
     */
    public function run(string $triggeredBy = 'cron'): array
    {
        // Maintain is event-shaped (not subject-state), but key a stable daily hash so a manual run
        // plus the 03:00 scheduled run on the same day update one ledger row instead of appending a dup.
        $run = WikiRun::updateOrCreate(
            [
                'run_type' => WikiRunType::Maintain->value,
                'subject_type' => null,
                'subject_id' => null,
                'source_content_hash' => 'maintain:'.now()->toDateString(),
            ],
            ['status' => WikiRunStatus::Running->value, 'triggered_by' => $triggeredBy, 'errors' => null],
        );

        $results = [
            'stale' => $this->stalenessSweep(),
            'contradictions' => $this->contradictionSweep(),
            'lint' => $this->linkLint(),
            'open_tickets' => $this->staleOpenTicketSweep(),
            'regen' => $this->regenStaleOverviews(),
        ];

        $run->update([
            'status' => WikiRunStatus::Completed->value,
            'stages_completed' => array_keys($results),
            'stage_results' => $results,
        ]);

        return $results;
    }

    /** Measurement only — staleness is computed, not stored (§4.2). */
    private function stalenessSweep(): array
    {
        $byClient = WikiFact::stale()
            ->selectRaw('client_id, COUNT(*) c')
            ->groupBy('client_id')
            ->pluck('c', 'client_id');

        return ['total' => (int) $byClient->sum(), 'by_client' => $byClient->all()];
    }

    /**
     * Cross-source contradictions the per-run merge could not see (sync vs ticket facts that arrived
     * in separate runs). Deterministic, no AI. The "differ" test mirrors the merge stage EXACTLY —
     * `trim($statement)` equality — so the sweep fires precisely when merge would NOT have reaffirmed.
     * NOT normalizeSubjectKey (that normalizes the entity key, not a value comparator).
     * Pair-only; humans resolve (§4.4). Bounded so a garbage ticket can't mass-dispute.
     */
    private function contradictionSweep(): array
    {
        $filed = 0;

        // Candidate subjects = those with >1 live fact. Found at the DB layer so we never ->get()
        // the whole fact table; then load just the small per-subject groups.
        $candidateKeys = WikiFact::query()
            ->where('status', '!=', WikiFactStatus::Retired->value)
            ->selectRaw('client_id, subject_key, COUNT(*) c')
            ->groupBy('client_id', 'subject_key')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($candidateKeys as $key) {
            if ($filed >= self::MAX_DISPUTES_PER_RUN) {
                break; // overflow is implicit in filed count vs the cap
            }

            $rows = WikiFact::query()
                ->where('client_id', $key->client_id)
                ->where('subject_key', $key->subject_key)
                ->where('status', '!=', WikiFactStatus::Retired->value)
                ->whereNull('disputed_with_fact_id') // skip facts already in a dispute
                ->get();

            // "Differ" iff ≥2 distinct trimmed statements — identical to the merge "same → reaffirm" rule.
            if ($rows->map(fn (WikiFact $f) => trim($f->statement))->unique()->count() < 2) {
                continue;
            }

            if ($rows->contains(fn (WikiFact $f) => $f->pinned)) {
                continue; // pinned challenges go through the §4.4 addendum path, never the sweep
            }

            $incumbent = $rows->sortByDesc('id')->first(); // newest is the standing claim
            foreach ($rows->where('id', '!=', $incumbent->id) as $other) {
                if ($filed >= self::MAX_DISPUTES_PER_RUN) {
                    break;
                }

                if (trim($other->statement) === trim($incumbent->statement)) {
                    continue; // identical wording — a reaffirmation, not a contradiction
                }

                // Respect a prior human dismissal: don't re-raise from already-dismissed evidence (§4.4).
                if ($incumbent->pinned
                    && WikiFactService::isSubsetOfDismissed(
                        $other->source_refs ?? [],
                        $incumbent->dismissed_evidence ?? []
                    )) {
                    continue;
                }

                $this->facts->markDisputed($other, $incumbent); // thin wrapper over pairAsDisputed()
                $filed++;
            }
        }

        return ['filed' => $filed];
    }

    private function linkLint(): array
    {
        $dead = WikiLink::whereNull('to_page_id')->count();

        // Skeleton-seeded pages (system-authored) start with no inbound links by design — exclude them
        // so a fresh client doesn't report false orphans. Genuine orphans are ai/human-authored pages
        // that nothing links to. Overview pages are also excluded (they're client root pages).
        $orphans = WikiPage::active()
            ->where('kind', '!=', \App\Enums\WikiPageKind::Overview->value)
            ->where('created_by_type', '!=', WikiAuthorType::System->value)
            ->whereDoesntHave('backlinks')
            ->count();

        $archivedRefs = WikiLink::whereHas(
            'toPage',
            fn ($q) => $q->where('is_archived', true)
        )->count();

        return ['dead_links' => $dead, 'orphan_pages' => $orphans, 'archived_refs' => $archivedRefs];
    }

    /**
     * Phase-5 default: FLAG long-idle open tickets carrying unmined knowledge — do NOT auto-mine.
     * Auto-mining is INERT today: MineTicketKnowledge returns early on empty($ticket->resolution),
     * and open tickets have none; covering them needs a resolution-independent context + a notes-folded
     * content hash (deferred — Task 2 risk note). The flag is surfaced on the health/index affordance
     * (Task 3), not buried only in stage_results.
     */
    private function staleOpenTicketSweep(): array
    {
        $cutoff = now()->subDays(WikiConfig::staleOpenTicketDays());

        $candidates = Ticket::query()
            ->whereNotIn('status', $this->closedTicketStatuses()) // open tickets only
            ->where('updated_at', '<', $cutoff)
            ->whereHas('notes') // substantive content exists
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('wiki_runs')
                    ->whereColumn('wiki_runs.subject_id', 'tickets.id')
                    ->where('wiki_runs.subject_type', 'ticket')
                    ->where('wiki_runs.status', WikiRunStatus::Completed->value);
            })
            ->limit(200)
            ->pluck('id');

        return ['flagged' => $candidates->count(), 'ticket_ids' => $candidates->take(50)->all()];
    }

    /**
     * Backstop for sync-driven fact changes (the sync writer never dispatches a recompose).
     * compose() returns a WikiComposeOutcome; only clients whose facts changed actually spend tokens
     * (hash-skip before any AI call), so O(clients) attempts is cheap.
     */
    private function regenStaleOverviews(): array
    {
        if (WikiBudget::dailyLimitReached()) {
            return ['skipped' => 'budget', 'composed' => 0];
        }

        $composed = 0;
        Client::query()->eachById(function (Client $client) use (&$composed) {
            if (WikiBudget::dailyLimitReached()) {
                return false; // halt the chunk walk cleanly
            }
            if ($this->composer->compose($client) === WikiComposeOutcome::Composed) {
                $composed++;
            }
        });

        return ['composed' => $composed];
    }

    /**
     * Terminal ticket statuses — aligned with the real TicketStatus enum values.
     *
     * @return array<int, string>
     */
    private function closedTicketStatuses(): array
    {
        return [
            \App\Enums\TicketStatus::Resolved->value,
            \App\Enums\TicketStatus::Closed->value,
        ];
    }
}
