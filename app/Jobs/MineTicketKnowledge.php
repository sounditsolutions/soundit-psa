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

    /** Per-client WithoutOverlapping — prevents two mine jobs for the same client running simultaneously. */
    public function middleware(): array
    {
        $ticket = Ticket::find($this->ticketId);
        $clientId = $ticket?->client_id ?? $this->ticketId;

        return [new WithoutOverlapping("wiki:mine:client:{$clientId}")];
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

        $alreadyRan = WikiRun::where('subject_type', 'ticket')
            ->where('subject_id', $ticket->id)
            ->where('source_content_hash', $contentHash)
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
        // In tests, $this->mock(AiClient::class) registers a Mockery binding that we
        // must not override — only rebind when the container doesn't already hold a mock.
        $wikiModel = WikiConfig::model();
        $existingBinding = app()->bound(AiClient::class) ? app(AiClient::class) : null;
        if (! ($existingBinding instanceof \Mockery\MockInterface)) {
            app()->bind(AiClient::class, fn () => new AiClient($wikiModel));
        }

        // ── Budget check: sum today's wiki mine_ticket token usage ────────────
        $dailyLimit = WikiConfig::dailyTokenLimit();
        $tokensUsedToday = WikiRun::where('run_type', WikiRunType::MineTicket->value)
            ->whereDate('created_at', today())
            ->whereNotNull('ai_tokens_used')
            ->get()
            ->sum(function (WikiRun $run) {
                $t = $run->ai_tokens_used ?? [];

                return ((int) ($t['input'] ?? 0)) + ((int) ($t['output'] ?? 0));
            });

        if ($tokensUsedToday >= $dailyLimit) {
            Log::info('[MineTicketKnowledge] Daily budget exhausted — deferring', [
                'ticket_id' => $ticket->id,
                'tokens_today' => $tokensUsedToday,
                'limit' => $dailyLimit,
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
        $run = WikiRun::create([
            'run_type' => WikiRunType::MineTicket,
            'subject_type' => 'ticket',
            'subject_id' => $ticket->id,
            'source_content_hash' => $contentHash,
            'status' => WikiRunStatus::Running,
            'triggered_by' => $this->triggeredBy,
        ]);

        try {
            // ── Stage 1: Gather — build bounded, pre-redacted context ────────
            $miningContext = $context->build($ticket);

            // ── Stage 2: Extract — one AI call returns candidate facts ────────
            $extractor = app(WikiFactExtractor::class);
            $extraction = $extractor->extract($miningContext);
            $candidates = $extraction['facts'];

            // ── Stage 3: Scan + quarantine (security H1 + H2) ────────────────
            // Scan BOTH statement AND subject_key for each candidate (H2).
            // Quarantine payload records violation_classes and subject_key but
            // NEVER the raw statement text (H1 — prevents leaking the secret
            // even into the errors log that operators/admins can read).
            foreach ($candidates as $candidate) {
                $statementViolations = $redactor->scan($candidate['statement'] ?? '');
                $subjectKeyViolations = $redactor->scan($candidate['subject_key'] ?? '');
                $allViolations = array_merge($statementViolations, $subjectKeyViolations);

                if (! empty($allViolations)) {
                    $violationClasses = array_unique(array_column($allViolations, 'class'));
                    $run->update([
                        'status' => WikiRunStatus::Quarantined,
                        'errors' => [[
                            'stage' => 'scan',
                            // H1: subject_key is stable metadata (not user-generated secret
                            // content) and is safe to log. The STATEMENT is NEVER included.
                            'subject_key' => $candidate['subject_key'] ?? null,
                            'violation_classes' => $violationClasses,
                            'message' => 'Candidate failed post-extraction secret/injection scan',
                        ]],
                        'stages_completed' => ['gather', 'extract'],
                        'ai_tokens_used' => $extraction['tokens'],
                    ]);

                    Log::warning('[MineTicketKnowledge] Quarantined — scan violation', [
                        'ticket_id' => $ticket->id,
                        'subject_key' => $candidate['subject_key'] ?? null,
                        'violation_classes' => $violationClasses,
                    ]);

                    return;
                }
            }

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

            $run->update([
                'status' => WikiRunStatus::Completed,
                'stages_completed' => ['gather', 'extract', 'scan', 'merge', 'compose'],
                'stage_results' => [
                    'facts_extracted' => count($candidates),
                    'facts_discarded_by_extractor' => $extraction['discarded'],
                    'touched_anchors' => array_map(fn ($a) => array_keys($a), $touchedAnchors),
                ],
                'ai_tokens_used' => $extraction['tokens'],
            ]);

            Log::info('[MineTicketKnowledge] Completed', [
                'ticket_id' => $ticket->id,
                'run_id' => $run->id,
                'facts' => count($candidates),
            ]);
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
}
