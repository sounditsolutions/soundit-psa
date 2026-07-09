# Closeout Pipeline — Phased Implementation Plan

**Date:** 2026-07-09
**Bead:** psa-8f74
**Design:** `docs/superpowers/specs/2026-07-09-closeout-pipeline-design.md`
**Status:** Draft (pending owner review — do not start Phase 1 until the design is approved)

This plan formalizes the two existing post-resolution stages (resolution drafting + knowledge
mining) into an explicit **Closeout pipeline**, then extends it. **Phase 1 is behavior-preserving
and is the only phase specified at task granularity here** — Phases 2–5 are task outlines that
each warrant their own short design pass when prioritized (they introduce new AI outputs and, for
C/D, wiki read-back that must route through `WikiRetrieval`).

Read the design doc first. Key facts this plan assumes, all verified against `main`:

- Trigger today: `TicketObserver::updated()` has **two** closeout branches — miner dispatch
  (`app/Observers/TicketObserver.php:78-85`) and drafter dispatch (`:92-99`).
- Existing stages: `MineTicketKnowledge` (`app/Jobs/MineTicketKnowledge.php`),
  `TicketResolutionDrafter` (`app/Services/TicketResolutionDrafter.php`) +
  `GenerateTicketResolution` (`app/Jobs/GenerateTicketResolution.php`).
- Shared ledger: `wiki_runs` + `WikiRunType` (`app/Enums/WikiRunType.php`) + `WikiRunStatus`.
- Shared machinery: `WikiTicketContext`, `WikiRedactor`, `WikiBudget`, `WikiRetrieval` (§6 boundary).
- Config pattern: `app/Support/WikiConfig.php` / `app/Support/TriageConfig.php`.
- Orchestrator template: `app/Services/Triage/TriagePipeline.php` (esp. `run()` + `runStage()` `:215`).

---

## Phase 1 — Formalize (behavior-preserving)

**Goal:** one orchestrator, one dispatch point, per-stage structure — with **zero** change to
what drafting or mining actually produce. The safety net is a golden test (Task 8) asserting
output equivalence with today's observer-chained flow.

### Task 1: `WikiRunType` — add the `Closeout` orchestration case

`app/Enums/WikiRunType.php` — add one case (the enum already holds `MineTicket`, `DraftResolution`,
`SyncFacts`, `Maintain`, `Backfill`, `Compose`):

```php
case Closeout = 'closeout';   // pipeline-level orchestration row (links child stage runs)
```

New-stage cases (`DocGap`, `KbDraft`, `Followup`) are added in their own phases, not here.

### Task 2: `CloseoutConfig` support class

`app/Support/CloseoutConfig.php` — thin, static, delegates the master gate to the wiki so Phase 1
changes no behavior. Mirrors `TriageConfig::stageEnabled()`.

```php
namespace App\Support;

class CloseoutConfig
{
    /** Master gate — the pipeline runs iff wiki auto-mine is on (unchanged from today). */
    public static function enabled(): bool
    {
        return WikiConfig::autoMineEnabled();
    }

    /**
     * Per-stage gate. Existing stages (resolution_draft, mining) track autoMineEnabled so
     * Phase 1 is observable-equivalent. New stages read their own opt-in, default OFF.
     */
    public static function stageEnabled(string $stage): bool
    {
        return match ($stage) {
            'resolution_draft', 'mining' => WikiConfig::autoMineEnabled(),
            'doc_gaps'  => (bool) Setting::getValue('closeout_stage_doc_gaps'),
            'kb_draft'  => (bool) Setting::getValue('closeout_stage_kb_draft'),
            'followups' => (bool) Setting::getValue('closeout_stage_followups'),
            default => false,
        };
    }

    public static function model(): string
    {
        return Setting::getValue('closeout_model') ?: WikiConfig::model();
    }

    public static function systemUserId(): ?int
    {
        return Setting::getValue('closeout_system_user_id') ?: TriageConfig::systemUserId();
    }

    public static function sweepEnabled(): bool
    {
        return self::enabled() && (bool) Setting::getValue('closeout_sweep_enabled');
    }
}
```

### Task 3: Extract `KnowledgeMiningStage` from `MineTicketKnowledge`

Lift the body of `MineTicketKnowledge::handle()` (`app/Jobs/MineTicketKnowledge.php:53-308`) into
a callable service so both the orchestrator and the retained job share it. **Copy the logic
verbatim** — gates, idempotency, `wiki_runs` write, stage loop, quarantine, `ComposeClientOverview`
fan-out — changing only the class shell and the budget-deferral signaling.

`app/Services/Closeout/Stages/KnowledgeMiningStage.php`:

```php
namespace App\Services\Closeout\Stages;

class KnowledgeMiningStage
{
    public function __construct(
        private readonly WikiSkeletonService $skeleton,
        private readonly WikiTicketContext $context,
        private readonly WikiRedactor $redactor,
        private readonly WikiFactService $facts,
        private readonly WikiComposerService $composer,
    ) {}

    /**
     * @return array{status: string, facts?: int, deferred?: bool, run_id?: int}
     *   status ∈ {completed, quarantined, failed, skipped, deferred}
     */
    public function run(Ticket $ticket, string $triggeredBy = 'auto'): array
    {
        // ... exact body of MineTicketKnowledge::handle(), except:
        //  - budget exhaustion returns ['status' => 'deferred'] instead of $this->release(3600);
        //    the *caller* (job or orchestrator) decides how to defer.
        //  - the AiClient wiki-model rebind stays as-is.
    }
}
```

`MineTicketKnowledge::handle()` becomes a thin wrapper (preserving the queue-release semantics for
the backfill path):

```php
public function handle(KnowledgeMiningStage $stage): void
{
    $result = $stage->run($this->ticketId, $this->triggeredBy);
    if (($result['deferred'] ?? false) && $this->job) {
        $this->release(3600); // budget exhausted — retry tomorrow (unchanged behavior)
    }
}
```

Keep `MineTicketKnowledge`'s `WithoutOverlapping("wiki-mine-client-{id}")` middleware for the
standalone/backfill path. The orchestrator holds its own outer lock (Task 4) and calls the stage
*service* directly, so there is no job-in-job lock nesting.

**Verification for this task:** the extracted stage must produce byte-identical `wiki_runs` rows
and fact writes as before — this is what Task 8's golden test pins.

### Task 4: `CloseoutPipeline` orchestrator

`app/Services/Closeout/CloseoutPipeline.php` — model on `TriagePipeline`. Opens a
`WikiRunType::Closeout` row, checks budget, runs stages through a `runStage()` helper
(transplanted from `TriagePipeline::runStage()` `:215`), records `stages_completed` /
`stage_results` / `errors` / `ai_tokens_used`.

```php
namespace App\Services\Closeout;

class CloseoutPipeline
{
    public function __construct(
        private readonly TicketResolutionDrafter $drafter,          // Stage A (exists)
        private readonly Stages\KnowledgeMiningStage $mining,       // Stage B (Task 3)
        // Stages C/D/E injected in their own phases.
    ) {}

    public function run(Ticket $ticket, string $triggeredBy = 'auto', ?int $userId = null): WikiRun
    {
        // Pre-checks mirror MineTicketKnowledge's gates: merge-closure skip, budget ceiling.
        $run = WikiRun::create([
            'run_type' => WikiRunType::Closeout->value,
            'subject_type' => 'ticket', 'subject_id' => $ticket->id,
            'status' => WikiRunStatus::Running->value, 'triggered_by' => $triggeredBy,
        ]);

        // Stage A — Resolution Drafting: only when resolution is empty (never overwrite).
        $this->runStage('resolution_draft', $ticket, function () use ($ticket) {
            if (filled($ticket->resolution)) return ['skipped' => 'resolution_present'];
            $draft = $this->drafter->draft($ticket, 'auto');
            if ($draft === null) return ['drafted' => false];
            $ticket->forceFill(['resolution' => $draft, 'resolution_ai_drafted' => true])->save();
            return ['drafted' => true];
        });

        // Stage B — Knowledge Mining: mines the (now-present, human or drafted) resolution.
        $ticket->refresh();
        $this->runStage('mining', $ticket, fn () => $this->mining->run($ticket, $triggeredBy));

        // Stages C/D/E appended here in later phases, each behind stageEnabled().

        $run->update([
            'status' => WikiRunStatus::Completed->value,
            'stages_completed' => $this->stagesCompleted,
            'stage_results' => $this->stageResults,
            'errors' => $this->errors,
            'ai_tokens_used' => $this->rolledUpTokens(),
        ]);
        return $run;
    }

    private function runStage(string $name, Ticket $ticket, callable $handler): void
    {
        if (! CloseoutConfig::stageEnabled($name)) return;
        try { $this->stageResults[$name] = $handler(); $this->stagesCompleted[] = $name; }
        catch (\Throwable $e) { $this->errors[] = ['stage' => $name, 'message' => $e->getMessage()]; }
    }
}
```

**Critical detail — Stage A→B in one run.** Today the drafter writes `resolution` and the *save*
re-fires the observer to trigger mining. Here the pipeline calls Stage A then Stage B directly, so
Stage A's `save()` still fires the observer — which would dispatch a *second* pipeline. Break that
loop the same way today's code does: the observer's dispatch is keyed such that this save is
absorbed (the mining branch will run, but the content-hash idempotency makes it a reaffirm/no-op
if this run already mined). **Simplest robust fix:** guard the observer so a save performed by the
closeout system user (or carrying a run-scoped flag) does not re-dispatch — see Task 5. Document
and test this explicitly (Task 8) — it is the one genuine behavioral subtlety in Phase 1.

### Task 5: `RunCloseoutPipeline` job + observer consolidation

`app/Jobs/RunCloseoutPipeline.php` — cf. `RunTriagePipeline`; `WithoutOverlapping("closeout-client-{clientId}")`
with `->releaseAfter(120)->expireAfter(900)` (mirrors the miner). `handle()` resolves
`CloseoutPipeline` and calls `run()`.

`app/Observers/TicketObserver.php` — replace both closeout branches (`:75-99`) with one dispatch,
plus the re-entrancy guard the design calls for:

```php
$isTerminal = in_array($ticket->status, [TicketStatus::Resolved, TicketStatus::Closed], true);
if ($isTerminal
    && ($ticket->wasChanged('status') || $ticket->wasChanged('resolution'))
    && WikiConfig::autoMineEnabled()
    && ! app(CloseoutRunGuard::class)->isInsidePipeline($ticket->id)) {
    RunCloseoutPipeline::dispatch($ticket->id);
}
```

The `CloseoutRunGuard` (a tiny request/job-scoped flag set while `CloseoutPipeline::run()` executes)
prevents Stage A's `save()` from re-dispatching the pipeline. Alternative, no new class: have Stage
A set a known sentinel and gate the observer on it, or attribute the save to the closeout system
user and reuse the existing system-user skip. Pick the least-magic option at implementation time;
whichever it is, Task 8 tests the "no double-dispatch, no lost re-mine on human edit" invariants.

### Task 6: `CloseoutConfig` settings keys registered + Settings UI

Register `closeout_model`, `closeout_system_user_id`, `closeout_sweep_enabled` (and the new-stage
flags, defined but off) wherever the app enumerates known settings. Add the toggles to the
Integrations/Settings page alongside the existing wiki settings, with per-stage estimated-cost
hints (matching the `wiki_auto_mine` UI treatment).

### Task 7: `docs/INSTALL.md`

Add the new settings keys and (from Phase 5) the `closeout:sweep` scheduled command, per the repo
living-docs rule.

### Task 8: Tests (the safety net)

- **`tests/Feature/Closeout/BehaviorPreservationTest.php`** — the golden test. Given a resolved
  ticket with (a) empty and (b) present resolution, running the pipeline yields the same
  `tickets.resolution`, `resolution_ai_drafted`, and `wiki_facts` as the pre-refactor
  observer-chained flow. Freeze representative AI outputs via a mocked `AiClient`.
- **`tests/Unit/Closeout/CloseoutConfigTest.php`** — `stageEnabled()` matrix; master gate delegates
  to `autoMineEnabled()`.
- **`tests/Feature/Closeout/ObserverConsolidationTest.php`** — one dispatch on resolve; Stage A→B
  no double-dispatch; human resolution edit re-mines (reaffirm); merge-closure & system-user skips.
- **`tests/Unit/Closeout/KnowledgeMiningStageTest.php`** — extracted stage returns `deferred` on
  budget exhaustion; quarantine on redaction hit; idempotent re-run.

**Gate:** `scripts/gc-verify.sh` green (full suite + Pint on changed files + secret guard) before
handoff.

---

## Phase 2 — Stage C: Documentation-Gap Detection *(new AI output; §6 read-back)*

> Warrants a short design pass first (gap heuristics, prompt, flag data-model on the needs-review
> surface). Outline:

- **Task C1** — `WikiRunType::DocGap`; `closeout_stage_doc_gaps` setting (off).
- **Task C2** — `app/Services/Closeout/Stages/DocGapStage.php`: deterministic staleness/contradiction
  signals (reuse `WikiMaintainService` primitives) + a bounded AI pass that reads the client's wiki
  **via `WikiRetrieval`** (design §4 — never raw `wiki_facts`/`wiki_pages`) and emits gap flags.
- **Task C3** — persist flags to the client wiki **needs-review** surface (reuse the existing
  unverified/disputed/stale affordance); optional private ticket note (§8.1 AI-addendum UI).
- **Task C4** — wire `DocGapStage` into `CloseoutPipeline` behind `stageEnabled('doc_gaps')`.
- **Task C5** — tests: §6 routing (delimited records, cross-client isolation), flag-only (no page
  write), gating.

## Phase 3 — Stage D: KB / Runbook Drafting *(completes dormant global-pattern design)*

- **Task D1** — `WikiRunType::KbDraft`; `closeout_stage_kb_draft` setting (off).
- **Task D2** — pattern detector (N similar resolutions on a subject/category in a window) —
  deterministic, cheap, gates the AI draft.
- **Task D3** — `KbDraftStage`: AI drafts a runbook/how-to at the correct scope (client `deviation`
  or global `runbook`/`pattern` kind), born `unverified`, reading source patterns **via
  `WikiRetrieval`** with cross-client isolation. Draft page surfaced in the wiki draft/addendum UX;
  **never** auto-published to portal.
- **Task D4** — human promote-to-publish flow; tests (isolation, unverified-birth, no portal leak).

## Phase 4 — Stage E: Follow-up Ticket Suggestions *(suggest-only)*

- **Task E1** — `WikiRunType::Followup`; `TicketSource::Closeout`; `closeout_stage_followups` (off).
- **Task E2** — `FollowupStage`: AI proposes suggestions (title, rationale, priority, source-ticket
  link) from the resolution; **writes suggestions, not tickets**.
- **Task E3** — source-ticket UI to review; **accept → `TicketService` creates the ticket** (linked,
  `TicketSource::Closeout`), attributed to a human. Never auto-creates.
- **Task E4** — tests: suggest-only (no ticket until accept); accept creates linked ticket.

## Phase 5 — Sweep + observability + UX polish

- **Task S1** — `app/Console/Commands/CloseoutSweepCommand.php` (`closeout:sweep`): batched,
  budget-bounded, priority-ordered over recently resolved tickets whose `Closeout` run never ran or
  failed (generalizes `wiki:backfill`); scheduled in `routes/console.php`, gated on `sweepEnabled()`.
- **Task S2** — pipeline-level observability: surface `WikiRunType::Closeout` runs (stages, tokens,
  errors) on the existing wiki-runs/health views.
- **Task S3** — UX polish, ties to psa-s5bf and the deferred ticket-detail sidebar (design §7).

---

## Sequencing & risk

- **Phase 1 must land first and alone** — it is behavior-preserving and is the seam every later
  stage hangs off. Do not bundle a new stage into Phase 1; the golden test's value is that Phase 1
  changes nothing observable.
- The observer-consolidation re-entrancy (Task 4/5) is the only real subtlety in Phase 1 — treat
  its invariants (single dispatch, no lost re-mine on human edit) as acceptance criteria.
- Phases 2–4 each add new AI spend → each ships behind an **off-by-default** `closeout_stage_*` flag
  and each needs its short design pass. Stages C/D **must** route wiki reads through `WikiRetrieval`
  (design §4) — this is the enduring form of the bead's "§6 hardening first" note now that the
  boundary exists on `main`.
- No phase pushes to `main` directly or deploys — PR + gated refinery only (repo hard rules).
