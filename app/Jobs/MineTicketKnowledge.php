<?php

namespace App\Jobs;

use App\Enums\WikiFactVolatility;
use App\Enums\WikiRunStatus;
use App\Enums\WikiRunType;
use App\Models\Ticket;
use App\Models\WikiPage;
use App\Models\WikiRun;
use App\Services\Ai\AiClient;
use App\Services\Wiki\Mining\WikiFactExtractor;
use App\Services\Wiki\Mining\WikiRedactor;
use App\Services\Wiki\Mining\WikiTicketContext;
use App\Services\Wiki\WikiComposerService;
use App\Services\Wiki\WikiFactService;
use App\Services\Wiki\WikiSkeletonService;
use App\Support\AiConfig;
use App\Support\WikiBudget;
use App\Support\WikiConfig;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

class MineTicketKnowledge implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(
        private readonly int $ticketId,
        private readonly string $triggeredBy = 'auto',
    ) {}

    /**
     * Per-client WithoutOverlapping — prevents two mine jobs for the same client
     * running simultaneously. expireAfter(900) is mandatory: without it a crashed
     * worker would hold the lock forever and silently drop all future mining for
     * that client. releaseAfter(120) re-queues a blocked job instead of failing it.
     */
    public function middleware(): array
    {
        $ticket = Ticket::find($this->ticketId);
        $clientId = $ticket?->client_id ?? $this->ticketId;

        return [(new WithoutOverlapping("wiki-mine-client-{$clientId}"))->releaseAfter(120)->expireAfter(900)];
    }

    public function handle(
        WikiSkeletonService $skeleton,
        WikiTicketContext $context,
        WikiRedactor $redactor,
        WikiFactService $facts,
        WikiComposerService $composer,
    ): void {
        // ── Gate 1: auto-mine must be explicitly enabled ─────────────────────
        if (! WikiConfig::autoMineEnabled()) {
            return;
        }

        // ── Gate 1b: an AI provider must be configured ───────────────────────
        // Mining is entirely AI-driven — the extract stage calls the model. Without an
        // API key the run would throw deep inside AiClient (a TypeError on the null key),
        // dead-letter after its retries, and leave a Failed wiki_runs row with no facts
        // and no operator-visible cause. Skip cleanly instead (mirrors the triage
        // pipeline's graceful skip of AI stages when unconfigured); the Client Wiki
        // settings card surfaces the actionable "configure an AI provider" warning.
        if (! AiConfig::isConfigured()) {
            Log::warning('[MineTicketKnowledge] Skipping — no AI provider configured; set an AI API key in Settings → Integrations', [
                'ticket_id' => $this->ticketId,
            ]);

            return;
        }

        $ticket = Ticket::find($this->ticketId);

        // ── Gate 2: ticket must exist, have a resolution, not be a merge closure ──
        if (! $ticket) {
            Log::warning('[MineTicketKnowledge] Ticket not found', ['ticket_id' => $this->ticketId]);

            return;
        }

        if (empty($ticket->resolution)) {
            return; // no resolution → nothing to mine
        }

        // Merge-closure guard: tickets merged into another have a parent_ticket_id.
        // Mining a merge closure would duplicate knowledge already on the parent ticket.
        if ($ticket->parent_ticket_id !== null) {
            return;
        }

        // ── Idempotency: content hash keyed on ticket id + resolution text ───
        // Security M2: hash does NOT include updated_at — only stable content fields.
        // This prevents re-running on non-content updates (status changes, assignments).
        $contentHash = hash('sha256', $ticket->id.'|'.$ticket->resolution);

        // Only terminal-success and quarantine states block a re-run. A Failed or
        // still-Running row must NOT block: a mid-pipeline crash leaves partial facts,
        // and the queue retry (or a later trigger) needs to re-process — the reaffirm
        // path makes re-mining idempotent. Quarantined stays blocking: it is a
        // deliberate security terminal state, never to be silently re-attempted.
        $alreadyRan = WikiRun::where('subject_type', 'ticket')
            ->where('subject_id', $ticket->id)
            ->where('source_content_hash', $contentHash)
            ->whereIn('status', [WikiRunStatus::Completed->value, WikiRunStatus::Quarantined->value])
            ->exists();

        if ($alreadyRan) {
            Log::debug('[MineTicketKnowledge] Skipping — content hash already processed', [
                'ticket_id' => $ticket->id,
                'hash' => $contentHash,
            ]);

            return;
        }

        // ── Architecture fix: rebind AiClient with wiki_model BEFORE the try ─
        // Rebind in the container so WikiFactExtractor (resolved via DI) uses the
        // wiki-configured model rather than the global default. Must be outside the
        // try block so the binding is active for everything resolved inside it.
        // The guard compares the wiki model to the global default: when they differ
        // a dedicated client is bound; when they match (including in tests, which
        // never set wiki_model) the rebind is skipped, leaving any test-bound mock
        // instance untouched — app()->instance() outranks app()->bind().
        if (WikiConfig::model() !== AiConfig::model()) {
            app()->bind(AiClient::class, fn () => new AiClient(WikiConfig::model()));
        }

        // ── Budget check: shared daily pool across ALL wiki AI usage (spec §5.3) ─
        // WikiBudget sums every run_type (mining + overview compose), so mining and
        // hot-summary composition can't independently blow the cap — they share it.
        if (WikiBudget::dailyLimitReached()) {
            Log::info('[MineTicketKnowledge] Daily budget exhausted — deferring', [
                'ticket_id' => $ticket->id,
                'tokens_today' => WikiBudget::tokensUsedToday(),
                'limit' => WikiConfig::dailyTokenLimit(),
            ]);

            // Release back to queue with a 1-hour delay so it retries tomorrow.
            // $this->job is null in dispatchSync (no real queue connection) — only release
            // when an actual queue job object is present.
            if ($this->job) {
                $this->release(3600);
            }

            return;
        }

        // ── Open the wiki_runs ledger entry ───────────────────────────────────
        // updateOrCreate on the idempotency tuple: a prior Failed or Running row for
        // this same hash (a crashed/retried attempt — which the gate above deliberately
        // does NOT treat as blocking) is reset to Running and re-driven, rather than
        // colliding with the unique (subject_type, subject_id, source_content_hash) index.
        $run = WikiRun::updateOrCreate(
            [
                'subject_type' => 'ticket',
                'subject_id' => $ticket->id,
                'source_content_hash' => $contentHash,
            ],
            [
                'run_type' => WikiRunType::MineTicket,
                'status' => WikiRunStatus::Running,
                'triggered_by' => $this->triggeredBy,
                'errors' => null,
                'stages_completed' => null,
                'stage_results' => null,
            ],
        );

        try {
            // ── Stage 1: Gather — build bounded, pre-redacted context ────────
            $miningContext = $context->build($ticket);

            // ── Stage 2: Extract — one AI call returns candidate facts ────────
            $extractor = app(WikiFactExtractor::class);
            $extraction = $extractor->extract($miningContext);
            $candidates = $extraction['facts'];

            // ── Stage 3: Scan + quarantine (security H1 + H2) ────────────────
            // Scan BOTH statement AND subject_key for each candidate (H2).
            // Quarantine is PER-CANDIDATE for CREDENTIAL-class hits ONLY. The redactor's
            // credential corpus is deliberately broad (bare `token`, `license key` keyword
            // forms) and the billing/licensing vocabulary sits right on top of it — "the
            // Adobe license key is held by the office manager" trips the credential pattern
            // on a statement the prompt's own contract invites. Failing the WHOLE run on the
            // first hit discarded every other fact from that ticket AND left the content
            // hash in a terminal state, so those facts were never re-mined. The offending
            // candidate alone is dropped (never stored); the run continues.
            // INJECTION and MARKER hits keep the ORIGINAL per-run contract. They are not a
            // false-positive-prone keyword match on one statement — they are evidence the
            // extraction itself absorbed attacker instructions from the untrusted ticket
            // body, so every sibling candidate out of that same extraction is suspect. One
            // such hit quarantines the WHOLE run: nothing is written, and the terminal
            // Quarantined status puts the ticket in front of a human.
            // Quarantine payload records violation_classes and subject_key but
            // NEVER the raw statement text (H1 — prevents leaking the secret
            // even into the errors log that operators/admins can read).
            $quarantinedCandidates = [];
            $scanned = [];
            $runQuarantining = false; // any non-credential class seen → quarantine the RUN

            foreach ($candidates as $candidate) {
                $statementViolations = $redactor->scan($candidate['statement'] ?? '');
                $subjectKeyViolations = $redactor->scan($candidate['subject_key'] ?? '');
                $allViolations = array_merge($statementViolations, $subjectKeyViolations);

                if (empty($allViolations)) {
                    $scanned[] = $candidate;

                    continue;
                }

                $violationClasses = array_values(array_unique(array_column($allViolations, 'class')));

                // Anything other than 'credential' (injection, marker) is per-RUN.
                if (array_diff($violationClasses, ['credential']) !== []) {
                    $runQuarantining = true;
                }

                $quarantinedCandidates[] = [
                    'stage' => 'scan',
                    // H1: subject_key is stable metadata (not user-generated secret
                    // content) and is safe to log. The STATEMENT is NEVER included.
                    'subject_key' => $candidate['subject_key'] ?? null,
                    'violation_classes' => $violationClasses,
                    'message' => 'Candidate failed post-extraction secret/injection scan',
                ];

                Log::warning('[MineTicketKnowledge] Candidate quarantined — scan violation', [
                    'ticket_id' => $ticket->id,
                    'subject_key' => $candidate['subject_key'] ?? null,
                    'violation_classes' => $violationClasses,
                ]);
            }

            // An injection/marker hit on ANY candidate, or nothing surviving the scan → the
            // RUN is quarantined, exactly as before: a deliberate security terminal state,
            // never silently re-attempted. Nothing was written, so nothing is lost by the
            // content hash staying blocked.
            if ($runQuarantining || ($scanned === [] && $quarantinedCandidates !== [])) {
                $run->update([
                    'status' => WikiRunStatus::Quarantined,
                    'errors' => $quarantinedCandidates,
                    'stages_completed' => ['gather', 'extract'],
                    'ai_tokens_used' => $extraction['tokens'],
                ]);

                return;
            }

            $candidates = $scanned;

            // ── Stage 4: Merge — write facts into the wiki ────────────────────
            $skeleton->ensureForClient($ticket->client);

            $touchedAnchors = []; // page_slug => [anchor => true]

            foreach ($candidates as $candidate) {
                $pageSlug = $candidate['page'];
                $anchor = $candidate['anchor'];

                $page = WikiPage::forClient($ticket->client_id)
                    ->where('slug', $pageSlug)
                    ->first();

                if (! $page) {
                    Log::warning('[MineTicketKnowledge] Page not found for candidate', [
                        'ticket_id' => $ticket->id,
                        'page' => $pageSlug,
                    ]);

                    continue;
                }

                $volatility = $candidate['volatility'] === 'volatile'
                    ? WikiFactVolatility::Volatile
                    : WikiFactVolatility::Durable;

                $sourceRefs = [['type' => 'ticket', 'id' => $ticket->id]];

                $fact = $facts->upsertMinedFact(
                    $page,
                    $anchor,
                    $candidate['subject_key'],
                    $candidate['statement'],
                    $volatility,
                    $sourceRefs,
                    (float) $candidate['confidence'],
                );

                if ($fact !== null) {
                    $touchedAnchors[$pageSlug][$anchor] = true;
                }
            }

            // ── Stage 5: Compose — recompose only touched sections ────────────
            foreach ($touchedAnchors as $pageSlug => $anchors) {
                $page = WikiPage::forClient($ticket->client_id)
                    ->where('slug', $pageSlug)
                    ->first();

                if (! $page) {
                    continue;
                }

                foreach (array_keys($anchors) as $anchor) {
                    $composer->composeSection($page->fresh(), $anchor);
                }
            }

            $candidatesReturned = $extraction['candidatesReturned'] ?? count($candidates);

            $run->update([
                'status' => WikiRunStatus::Completed,
                'stages_completed' => ['gather', 'extract', 'scan', 'merge', 'compose'],
                'stage_results' => [
                    'facts_extracted' => count($candidates),
                    'facts_discarded_by_extractor' => $extraction['discarded'],
                    // Per-candidate scan drops (H1: subject_key + violation classes only,
                    // never the statement) — a partial quarantine must be visible in the
                    // ledger, not hidden behind an otherwise-Completed run.
                    'facts_quarantined_by_scan' => count($quarantinedCandidates),
                    'quarantined_details' => $quarantinedCandidates,
                    // Per-candidate discard reasons (page/anchor/confidence/reason) so a
                    // zero-fact run is diagnosable from the ledger, not silent.
                    'discarded_details' => $extraction['discardedDetails'] ?? [],
                    // How many candidates the AI actually returned, plus a one-line outcome
                    // summary — so a zero-fact run ALWAYS records why (psa-tqv9): AI returned
                    // nothing vs. proposed-but-all-discarded vs. accepted-but-unwritable.
                    'ai_candidates_returned' => $candidatesReturned,
                    'outcome_reason' => $this->outcomeReason(
                        count($candidates),
                        $candidatesReturned,
                        $extraction['discarded'],
                        $touchedAnchors !== [],
                    ),
                    'touched_anchors' => array_map(fn ($a) => array_keys($a), $touchedAnchors),
                ],
                'ai_tokens_used' => $extraction['tokens'],
            ]);

            Log::info('[MineTicketKnowledge] Completed', [
                'ticket_id' => $ticket->id,
                'run_id' => $run->id,
                'facts' => count($candidates),
            ]);

            // ── Eager overview recompose — fact-changing mines only ───────────
            // $touchedAnchors is non-empty exactly when upsertMinedFact actually
            // wrote/changed at least one fact (it's keyed only for non-null upserts
            // at Stage 4). A routine zero-fact close leaves it empty and MUST NOT
            // enqueue a recompose — that gate is what keeps idle closes from spending
            // AI tokens. The composer's content-hash skip is the backstop if a
            // recompose is ever enqueued without a net fact change.
            if ($touchedAnchors !== []) {
                ComposeClientOverview::dispatch($ticket->client_id);
            }
        } catch (\Throwable $e) {
            $run->update([
                'status' => WikiRunStatus::Failed,
                'errors' => [['stage' => 'pipeline', 'message' => $e->getMessage()]],
            ]);

            Log::error('[MineTicketKnowledge] Failed', [
                'ticket_id' => $ticket->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * One-line, always-present summary of a mine's outcome so a zero-fact run is
     * self-explanatory in the wiki_runs ledger (psa-tqv9) instead of an empty result
     * that reads identically whether the AI found nothing or the pipeline silently
     * dropped real facts.
     */
    private function outcomeReason(int $factsExtracted, int $candidatesReturned, int $discarded, bool $wroteAnything): string
    {
        if ($wroteAnything) {
            return "Extracted {$factsExtracted} fact(s) and merged into the wiki.";
        }
        if ($factsExtracted > 0) {
            return "Extractor accepted {$factsExtracted} fact(s), but none matched an existing wiki page/anchor to write.";
        }
        if ($candidatesReturned > 0) {
            return "AI proposed {$candidatesReturned} candidate(s); all {$discarded} were discarded during validation — see discarded_details.";
        }

        return 'AI returned no candidate facts for this resolution — nothing documentation-worthy was extracted.';
    }
}
