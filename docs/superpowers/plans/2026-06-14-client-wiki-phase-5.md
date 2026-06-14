# Client Wiki — Phase 5 Implementation Plan (Maintenance Loop · Health Surfacing · Verification-UX Polish · `wiki:export` · `wiki:backfill`)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. TDD throughout — write the failing test first.

**Goal:** Take the wiki from "learns on every ticket close" (Phase 4) to **self-maintaining at steady state**. A nightly job sweeps for staleness, cross-source contradictions, and broken links; regenerates only the overviews whose facts actually changed; and covers never-closing tickets. Health (unverified / disputed / stale) surfaces as quiet affordances. The verification UI gets the §8.1 polish the QA pass flagged, and wiki pages stop being navigational dead-ends. Finally, `wiki:export` gives OSS adopters a plain-text egress/backup, and `wiki:backfill` populates history from the existing closed-ticket corpus under the daily budget.

**Foundation (already merged):** Phase 4 landed on `main` at commit `30cc870` (PR #23, 2026-06-13). `WikiRetrieval` (§6 boundary), the three retrieval tools, `WikiOverviewComposer`, `WikiBudget` (shared daily pool), and the §4.6 injection swap are all present. The queue worker (`soundit-psa-queue.service`) was restarted post-merge and runs the Phase-4 code. **Phase 5 has no unmerged dependency** — it extends a green `main`.

**Architecture:** Two new orchestration services behind two new commands. `WikiMaintainService` runs the nightly sweeps and records one `maintain` run in `wiki_runs`; `wiki:maintain` is scheduled in `routes/console.php`, gated on `wiki_maintenance_enabled` and the shared daily budget. `WikiBackfillService` reuses `MineTicketKnowledge` oldest-first under `wiki_backfill_batch_size` and the daily budget; `wiki:backfill` is dry-run-by-default (writes nothing until `--execute`). `WikiExportService` walks pages to an Obsidian-shaped vault under `storage/app/wiki-exports/` with identifier-only provenance frontmatter. Staleness becomes a computed `WikiFact` predicate (volatile + un-reaffirmed + not sync-sourced), never a stored status — exactly as the spec frames it. The UI work is pure Blade/controller polish over the existing `_provenance` panel and `wiki/show` page; no data-model change.

**Tech Stack:** Laravel 12 / PHP 8.3, existing `AiClient`, database queue with `WithoutOverlapping`, `Schedule::` facade in `routes/console.php`, existing wiki services (`WikiFactService`, `WikiPageService`, `WikiComposerService`, `WikiOverviewComposer`, `WikiRedactor`, `WikiSearchService`), Bootstrap 5 Blade (no build step), PHPUnit on SQLite `:memory:` with `AiClient` mocked via the container.

**Spec:** `docs/superpowers/specs/2026-06-12-client-wiki-design.md` — §7 (maintenance loop), §8 / §8.1 (human surface + binding interaction-design requirements), §4.2 (staleness is a computed flag; sync-backed facts never go stale), §5.3 (shared budget, idempotency), §9 (config keys + `wiki:export` posture), §11 (testing), §13 (injection residuals). Decisions doc: `docs/superpowers/specs/2026-06-13-client-wiki-mining-coverage-decisions.md` — `wiki:backfill` = thin reuse of `MineTicketKnowledge`, oldest-first, mandatory `--dry-run` cost estimate; never-closing tickets covered by a **stale-open-ticket maintenance sweep**, not a trigger change. Run `php artisan test` on `main` first to confirm the green baseline.

**Branch:** `feat/client-wiki-phase-5` off `main` in a worktree (superpowers:using-git-worktrees). **Do not develop in the primary `/home/charlie/repos/soundit-psa` checkout — the dev server (`soundit-psa-dev.service`) serves from it on `main`.**

**Conventions (unchanged from Phase 1–4):** logic in `app/Services/Wiki/`, thin controllers, string columns + PHP enums, `\RuntimeException` for business-rule violations, `WikiPageService`/`WikiFactService` are the only page/fact write paths, `AiClient` container-resolved, Pint before every commit, TDD throughout. **CI secret-guard gotcha (recurs in redaction tests):** `scripts/gc-verify.sh` greps the PR diff for secret shapes; never let a literal PEM header / cloud-key / chat-token / company email address appear contiguously in source — assemble such fixtures from runtime fragments. Local check before push: `git diff -U0 main...HEAD | grep -nEi '<GUARD_RE>'` must be empty (see Final verification for the exact expression).

---

## Persona review — incorporated (Rev 2)

This plan passed a four-lens persona gate (security / architecture / product / UX) against the live `main` code. Three lenses returned **approve-with-changes**; UX returned **revise** on Task 4 alone. All findings were local; none structural. The load-bearing ones and their resolutions, folded into the tasks below:

- **Arch HIGH — contradiction "differ" test misused `normalizeSubjectKey`** (a whitespace→hyphen/lowercase key normalizer) on free-text statements. Replaced with the merge stage's own invariant: `trim($statement)` equality, so the sweep fires precisely when merge would NOT have reaffirmed. [Task 2]
- **Arch HIGH — `Ticket::wikiRuns` relation does not exist** (`wiki_runs` stores `subject_type`/`subject_id`, not a morph). Both the open-ticket sweep and the backfill candidates query now use a correlated `whereNotExists` subquery. [Tasks 2, 6]
- **Arch HIGH — `markDisputed` duplicated the merge-stage pairing writer.** Extract `pairAsDisputed()` from `upsertMinedFact` (sets BOTH `disputed_with_fact_id` pointers symmetrically + both statuses) and reuse it; `markDisputed` is a thin wrapper. The draft's one-sided link would have broken `resolveDispute` and the provenance panel. [Task 2]
- **Arch MED — `Schedule::…->expireAfter(3600)` is not a Schedule method** (queue-middleware only) → would BadMethod. Use `->withoutOverlapping(60)`. [Task 2]
- **Security HIGH — `wiki:export` was a new UNSCANNED egress.** Page bodies (human/`site_notes`/pre-merge prose) were never output-scanned. Now `WikiRedactor::scan` runs per page; a hit withholds the body (placeholder) and records the slug. [Task 5]
- **Security HIGH — export non-public guard was bypassable** (`public/` is the docroot; substring check ignores traversal/symlink). Now resolves to an absolute real path, requires it inside `storage_path('app')` and outside `public_path()`, rejects `..`. Files `0600`, dirs `0700`, no error-suppression. [Task 5]
- **Security HIGH — `wiki:backfill execute()` gated on `isEnabled()`, but mining hard-returns unless `autoMineEnabled()`** (default OFF, independent). It would dispatch N jobs that all no-op while reporting success. Backfill now gates on `autoMineEnabled()` and surfaces the gate state in the dry-run. [Task 6]
- **Security/Arch MED — retire-actor controller call was misread.** Live `WikiFactController::retire` calls `$facts->retire($fact)` with no user. The controller call must be edited to pass `auth()->user()` (and preserve its `composeSection` call). [Tasks 1, 7]
- **UX HIGH (revise) — §8.1 #2 superseded treatment was unreachable AND wrong.** The controller loads `$facts` with `whereNot('status', Retired)`, so retired facts never reach the panel; and §4.4 "superseded by sync" is a *disputed live-claim* case, not retirement. Resolution: drop the broad Retired styling — §8.1 #2 is met by the existing inline AI-challenge/dispute block; a dimmed/struck row, if wanted, is scoped to `Disputed && counter source=Sync` with the exact "(superseded by sync, pending review)" label. [Task 4]
- **UX HIGH — provenance statement still crammed** because the line leads with the badge. The statement now gets its own full-width line; badge above, source refs below, actions in a row beneath. [Task 4]
- **UX MED — `sectionSummaries()` can't add stale via `whereIn('status')`** (staleness is computed). Now integrates `scopeStale()` per `section_anchor`. Plus a zero-state guard so a stale-only wiki still surfaces the muted count. [Task 3]
- **UX MED — nav had a redundant back-link** (breadcrumb + in-card "← All pages") and a no-margin active-item contrast. Keep the clickable breadcrumb, drop the in-card back-link, verify active contrast against the navy theme token. [Task 4]
- **Product MED — backfill estimate showed `batch × maxTokensPerRun` = 2.5× the daily cap.** Now estimates from recent `wiki_runs.ai_tokens_used` averages (fallback nominal), capped at the daily ceiling, presented as a range + rough $, with "cannot exceed the daily ceiling; resumes next day." [Task 6]
- **Product MED — flag-only open-ticket sweep needed honest framing + an actionable surface.** The mining job returns early on `empty($ticket->resolution)`, so auto-mining open tickets is *inert today*, not merely hash-blocked. The deferral reason is corrected, and the flag surfaces on a health/index affordance, not only in `stage_results`. [Task 2]
- **Product MED — cost copy omitted maintenance + backfill.** Settings copy now states nightly maintenance recomposes only changed clients (near-zero at steady state via hash-skip) and backfill is operator-initiated/dry-run-first under the same ceiling. [Task 7]
- **Arch/Prod MED — `regenStaleOverviews` "did it compose" detection was brittle** (two `max(id)` probes per client; miscounts quarantines). `WikiOverviewComposer::compose()` gains a return enum (`Composed | SkippedUnchanged | SkippedBudget | SkippedNoOverview | Quarantined`); the sweep counts `=== Composed`. [Task 2]
- **Arch LOW — link-lint orphan count would flag every freshly-seeded skeleton page.** Exclude all skeleton-seeded kinds (or scope orphans to `created_by_type` ai|human), not just `overview`. [Task 2]
- **Product LOW — contradiction sweep false-positive noise.** Added a "near-duplicate phrasing does NOT dispute" test and a dismissed-evidence subset guard (reuse `isSubsetOfDismissed`). [Task 2]

Verdicts: Security **approve-with-changes**, Architecture **approve-with-changes**, Product **approve-with-changes**, UX **revise→resolved** (Task 4 rewritten per the above). Non-regressions the gate confirmed against live code: the Phase-4 inject-point scan still defends a hand-edited overview (clearing `composed_hash` not `composed_at` is correct and safe); the `retired_by` migration anchor/`nullOnDelete` is sound; the stale-only regen backstop correctly targets sync-driven fact changes (the sync writer never dispatches a recompose); FLAG-ONLY is the security-correct default for the open-ticket sweep.

---

## ⚠️ Open product decision (resolve before Task 4 ships — recommended default baked in)

QA filed two linked navigation findings against the wiki UI:

- **psa-7ph7** (P2) — *concrete*: individual wiki pages are dead-ends. From `wiki/show` the only off-page controls are Edit / History / Archive — no link back to the client index, no sibling-page links, no on-page search, and the breadcrumb is plain text. Reading three environment pages costs a browser-Back bounce between each.
- **psa-s5bf** (P2, Mayor-owned) — *umbrella*: "streamline per-client navigation for a tech under pressure." Raises a larger IA fork and explicitly says it "wants a brainstorm → small design before building."

There are two ways to satisfy this, and they are not the same size:

| Option | What it is | Cost | Risk |
|--------|-----------|------|------|
| **A — Incremental nav affordances (RECOMMENDED for Phase 5)** | On `wiki/show`: clickable breadcrumb, back-to-index link, an always-present sibling-page list in the right column, and an on-page search box. Closes **psa-7ph7** outright. | Small (Blade only, Task 4c) | Low |
| **B — Consolidated single-scroll client-environment view** | One `/clients/{client}/wiki` landing that renders overview + network + infrastructure + m365 + security + backup inline with an anchor sidebar; pages become anchors, not separate navigations. Addresses the **psa-s5bf** umbrella. | Medium–large (new IA, new controller path, render budget) | Medium — needs a design pass |

**This plan implements Option A in Task 4** and treats Option B as a flagged fast-follow that wants its own short design (per psa-s5bf's own note). **A is strictly additive and not throwaway — its sibling/search/index plumbing is exactly the anchor nav a consolidated view (B) reuses, so shipping A first de-risks B and loses nothing if we later build it.** A removes the reported pain (dead-end pages, browser-Back bounce between a client's environment pages); B is the deeper "read the whole environment in one motion" answer but needs the IA/render-budget design psa-s5bf calls for. If the owner prefers to fold B into Phase 5, Task 4 expands and Task 4c becomes the anchor-render path instead of per-page links. **Default if no decision arrives: ship A, leave psa-s5bf open as the deferred design.** Everything else in this plan is independent of this fork.

---

## File structure (locked)

```
# Task 1 — config + staleness foundation
database/migrations/2026_06_14_000001_add_retired_by_to_wiki_facts.php   NEW — nullable retired_by FK
app/Support/WikiConfig.php                          modify: stalenessDaysVolatile(), maintenanceEnabled(), backfillBatchSize(), staleOpenTicketDays()
app/Models/WikiFact.php                             modify: scopeStale(), isStale(), retiredBy() relation
app/Services/Wiki/WikiFactService.php               modify: retire(WikiFact, ?User) records retired_by

# Task 2 — maintenance loop
app/Services/Wiki/WikiMaintainService.php           NEW — staleness/contradiction/link-lint/stale-open-ticket/stale-only-regen sweeps
app/Enums/WikiComposeOutcome.php                    NEW — compose() return signal (Composed|SkippedUnchanged|SkippedBudget|SkippedNoOverview|Quarantined)
app/Services/Wiki/WikiOverviewComposer.php          modify: compose() returns WikiComposeOutcome (pure return-type add; mining job + fact controllers unaffected)
app/Services/Wiki/WikiFactService.php               modify: extract pairAsDisputed() from upsertMinedFact; add markDisputed() wrapper
app/Console/Commands/WikiMaintainCommand.php        NEW — wiki:maintain
routes/console.php                                  modify: Schedule::command('wiki:maintain')->dailyAt('03:00')->withoutOverlapping(60)

# Task 3 — health counters (add stale)
app/Http/Controllers/Web/WikiController.php         modify: healthCounts() + sectionSummaries() include stale
resources/views/wiki/index.blade.php                modify: render stale in the muted needs-review block
resources/views/wiki/show.blade.php                 modify: stale in section summaries + page badge

# Task 4 — §8.1 verification-UX polish + nav (psa-7ph7 / psa-ux48 / psa-s5bf-A)
resources/views/wiki/_provenance.blade.php          modify: stack actions under statement (psa-ux48); WCAG-AA superseded treatment
resources/views/wiki/show.blade.php                 modify: clickable breadcrumb, back-to-index, sibling list, on-page search (psa-7ph7)
resources/views/wiki/_page_nav.blade.php            NEW — sibling/index/search partial

# Task 5 — wiki:export
app/Services/Wiki/WikiExportService.php             NEW — Obsidian-shaped vault dump, identifier-only frontmatter
app/Console/Commands/WikiExportCommand.php          NEW — wiki:export {client?} {--all} {--path=} {--include-archived}

# Task 6 — wiki:backfill
app/Services/Wiki/WikiBackfillService.php           NEW — oldest-first reuse of MineTicketKnowledge, budget-aware, dry-run estimate
app/Console/Commands/WikiBackfillCommand.php        NEW — wiki:backfill {--client=} {--batch=} {--execute}  (dry-run by default)

# Task 7 — residual hardening
app/Http/Controllers/Web/WikiController.php         modify: clear meta['composed_hash'] on Overview body edit
app/Services/Wiki/WikiPageService.php               modify (if meta handling lives here): same
resources/views/settings/general.blade.php          modify: surface the three new settings (staleness/maintenance/backfill)

# Tests (tests/Feature/Wiki/ unless noted)
WikiStalenessTest.php            NEW (Task 1)   — isStale/scopeStale matrix
WikiMaintainServiceTest.php      NEW (Task 2)   — each sweep + the maintain run record
WikiMaintainCommandTest.php      NEW (Task 2)   — gating, budget defer, schedule wiring
WikiHealthCountersTest.php       NEW (Task 3)   — stale in healthCounts + zero-state silent
WikiPageNavTest.php              NEW (Task 4)   — index/sibling/search links present on show
WikiProvenancePanelTest.php      modify (Task 4)— stacked actions, superseded treatment
WikiExportTest.php               NEW (Task 5)   — layout, identifier-only frontmatter, non-public path
WikiBackfillTest.php             NEW (Task 6)   — dry-run writes nothing, oldest-first, batch cap, budget stop, idempotent
WikiFactActionsTest.php          modify (Task 7)— retire records retired_by
WikiOverviewEditConsistencyTest.php NEW (Task 7)— Overview body edit clears composed_hash
```

---

### Task 1: Config + staleness foundation + retire actor (PREREQUISITE)

Staleness is the computational primitive the maintenance sweep and health counters both consume. Build it first, with tests, so Tasks 2–3 stand on a tested predicate. Also lands the cheap schema change (`retired_by`) so later tasks don't re-touch the migration set.

**Files:** migration (new), `WikiConfig` (modify), `WikiFact` (modify), `WikiFactService` (modify). **Test:** `tests/Feature/Wiki/WikiStalenessTest.php`.

- [ ] **Step 1: Failing test** — `WikiStalenessTest.php`

```php
<?php

namespace Tests\Feature\Wiki;

use App\Enums\WikiFactSource;
use App\Enums\WikiFactStatus;
use App\Enums\WikiFactVolatility;
use App\Models\Setting;
use App\Models\WikiFact;
use App\Models\WikiPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WikiStalenessTest extends TestCase
{
    use RefreshDatabase;

    private function fact(array $attrs): WikiFact
    {
        $page = WikiPage::factory()->create();
        return WikiFact::factory()->create(array_merge([
            'page_id' => $page->id, 'client_id' => $page->client_id, 'scope' => $page->scope,
            'subject_key' => 'asset:dc01:fw', 'statement' => 'firmware 7.2.1',
            'status' => WikiFactStatus::Confirmed, 'source_type' => WikiFactSource::Ticket,
            'volatility' => WikiFactVolatility::Volatile, 'last_affirmed_at' => now()->subDays(120),
        ], $attrs));
    }

    public function test_volatile_unaffirmed_past_window_is_stale(): void
    {
        Setting::setValue('wiki_staleness_days_volatile', '90');
        $this->assertTrue($this->fact([])->isStale());
    }

    public function test_durable_fact_never_stale(): void
    {
        $this->assertFalse($this->fact(['volatility' => WikiFactVolatility::Durable])->isStale());
    }

    public function test_sync_sourced_volatile_fact_is_exempt(): void
    {
        // §4.2: sync-backed facts refresh at the source; they never go stale.
        $this->assertFalse($this->fact(['source_type' => WikiFactSource::Sync])->isStale());
    }

    public function test_retired_fact_not_stale(): void
    {
        $this->assertFalse($this->fact(['status' => WikiFactStatus::Retired])->isStale());
    }

    public function test_recently_affirmed_not_stale(): void
    {
        $this->assertFalse($this->fact(['last_affirmed_at' => now()->subDays(5)])->isStale());
    }

    public function test_scope_matches_accessor(): void
    {
        Setting::setValue('wiki_staleness_days_volatile', '90');
        $stale = $this->fact([]);
        $fresh = $this->fact(['last_affirmed_at' => now()]);
        $ids = WikiFact::stale()->pluck('id');
        $this->assertTrue($ids->contains($stale->id));
        $this->assertFalse($ids->contains($fresh->id));
    }
}
```

Run: `php artisan test --filter=WikiStalenessTest` — FAIL.

- [ ] **Step 2: Migration — `retired_by`**

`database/migrations/2026_06_14_000001_add_retired_by_to_wiki_facts.php`:

```php
return new class extends Migration {
    public function up(): void
    {
        Schema::table('wiki_facts', function (Blueprint $table) {
            $table->foreignId('retired_by')->nullable()->after('confirmed_by')
                ->constrained('users')->nullOnDelete();
        });
    }
    public function down(): void
    {
        Schema::table('wiki_facts', fn (Blueprint $t) => $t->dropConstrainedForeignId('retired_by'));
    }
};
```

ADAPTATION (note it): confirm `confirmed_by` is the right anchor column (per spec §4.1 it exists). Add `'retired_by'` to `WikiFact::$fillable`.

- [ ] **Step 3: `WikiConfig` accessors** (spec §9 defaults are authoritative)

```php
    public static function stalenessDaysVolatile(): int
    {
        return (int) (Setting::getValue('wiki_staleness_days_volatile') ?: 90);
    }

    /** Nightly maintenance defaults ON once the wiki is enabled (spec §9). */
    public static function maintenanceEnabled(): bool
    {
        return self::isEnabled() && (bool) (Setting::getValue('wiki_maintenance_enabled') ?? true);
    }

    public static function backfillBatchSize(): int
    {
        return (int) (Setting::getValue('wiki_backfill_batch_size') ?: 25);
    }

    /** Open tickets idle longer than this are candidates for the stale-open-ticket sweep. */
    public static function staleOpenTicketDays(): int
    {
        return (int) (Setting::getValue('wiki_stale_open_ticket_days') ?: 30);
    }
```

- [ ] **Step 4: `WikiFact` — staleness predicate + retire relation**

```php
    public function retiredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'retired_by');
    }

    /** Spec §4.2/§7: only volatile, non-sync, non-retired facts go stale. */
    public function isStale(): bool
    {
        if ($this->volatility !== WikiFactVolatility::Volatile
            || $this->status === WikiFactStatus::Retired
            || $this->source_type === WikiFactSource::Sync) {
            return false;
        }
        $last = $this->last_affirmed_at ?? $this->created_at;
        return $last !== null && $last->lt(now()->subDays(WikiConfig::stalenessDaysVolatile()));
    }

    /** COALESCE keeps SQLite (test) and MariaDB (prod) parity. */
    public function scopeStale($query)
    {
        return $query
            ->where('volatility', WikiFactVolatility::Volatile->value)
            ->where('source_type', '!=', WikiFactSource::Sync->value)
            ->where('status', '!=', WikiFactStatus::Retired->value)
            ->whereRaw('COALESCE(last_affirmed_at, created_at) < ?', [now()->subDays(WikiConfig::stalenessDaysVolatile())]);
    }
```

- [ ] **Step 5: `WikiFactService::retire` records the actor**

```php
    public function retire(WikiFact $fact, ?User $user = null): void
    {
        $fact->update([
            'status' => WikiFactStatus::Retired,
            'retired_by' => $user?->id,
        ]);
    }
```

**Edit the controller call** — live `WikiFactController::retire` currently calls `$facts->retire($fact)` (no actor). Change it to `$facts->retire($fact, auth()->user());` and **preserve its existing `$composer->composeSection(...)` call**. (Default-null keeps non-HTTP callers — supersession/`correct()` set `Retired` inline via `update()`, not via `retire()`, so they're unaffected.) The Task 7 Step 2 test pins this HTTP path.

- [ ] **Step 6: Run, pass, commit**

```bash
./vendor/bin/pint app/Support app/Models app/Services/Wiki database/migrations tests/Feature/Wiki
git add -A && git commit -m "feat(wiki): staleness predicate + Phase-5 config keys + retire actor (retired_by)"
```

---

### Task 2: `WikiMaintainService` + `wiki:maintain` + nightly schedule (§7)

One nightly job runs five sweeps and records a `maintain` run. Each sweep is independently testable; the service composes them and returns a results array stored on the run.

**Files:** `WikiMaintainService` (new), `WikiMaintainCommand` (new), `routes/console.php` (modify). **Tests:** `WikiMaintainServiceTest.php`, `WikiMaintainCommandTest.php`.

**Sweep contracts (Phase 2 decomposition — risk flagged inline):**

1. **Staleness sweep** — count `WikiFact::stale()`, grouped by client. *Pure measurement* (staleness is computed, never written). Output feeds health + the run result. No write. **Low risk.**
2. **Contradiction sweep** — within `(client_id, subject_key)`, find ≥2 non-retired, not-already-disputed facts whose normalized statements differ (the merge stage only sees contradictions inside a single run; sync-vs-ticket facts that arrived in separate runs slip through). Pair them as `disputed` via `WikiFactService`, newest as incumbent. **Low–medium risk** — "differ" is a normalized-string compare on an atomic subject_key, deterministic, no AI. Conservative: pair only, never retire; humans resolve.
3. **Link lint** — dead links (`wiki_links.to_page_id IS NULL`), orphan pages (active, non-`overview`, non-skeleton, zero backlinks), archived-page references. *Measurement only* — record counts + sample slugs in the run. No auto-repair. **Low risk.**
4. **Stale-open-ticket sweep** (per the #16 decision) — surface open tickets idle > `staleOpenTicketDays` with substantive unmined notes. **⚠️ RISK-FLAGGED — FLAG ONLY in Phase 5:** auto-mining open tickets is *architecturally inert today*, not merely hash-blocked — `MineTicketKnowledge` returns early on `empty($ticket->resolution)`, and open tickets have no resolution, so even dispatching the job mines nothing. Covering them needs a resolution-independent context path **and** a content hash that folds in the notes digest (today's key is `ticket.id | resolution`). Phase 5 therefore counts + lists candidates and surfaces them as an **actionable** health affordance (Task 3) — it does **not** auto-mine. Auto-mine is a flagged fast-follow needing both changes + a budget policy; **do not build it in Phase 5 unless the owner opts in.**
5. **Stale-only hot-summary regen** — for each client, call `WikiOverviewComposer::compose()`, which **already** no-ops when `meta['composed_hash']` matches the current fact digest. This is the backstop for *sync-driven* fact changes (the deterministic sync writer does not dispatch a recompose; only ticket mining does — Phase 4 Task 5). Budget-aware via the composer's own `WikiBudget` check. **Low risk** — reuses tested Phase-4 code; only clients whose facts changed actually spend tokens.

- [ ] **Step 1: Failing test** — `WikiMaintainServiceTest.php` (representative; expand per §11)

```php
public function test_contradiction_sweep_pairs_cross_source_facts(): void
{
    Setting::setValue('wiki_enabled', '1');
    [$client, $page] = $this->clientWithPage();
    $sync = $this->fact($page, 'asset:dc01:ram', 'DC01 has 32 GB RAM', WikiFactSource::Sync, WikiFactStatus::Confirmed);
    $tix  = $this->fact($page, 'asset:dc01:ram', 'DC01 has 16 GB RAM', WikiFactSource::Ticket, WikiFactStatus::Unverified);

    app(WikiMaintainService::class)->run('manual');

    $this->assertSame(WikiFactStatus::Disputed, $sync->fresh()->status);
    $this->assertSame(WikiFactStatus::Disputed, $tix->fresh()->status);
}

public function test_link_lint_counts_dead_and_orphan(): void
{
    Setting::setValue('wiki_enabled', '1');
    // a page linking to a non-existent slug → dead link; a second page nobody links → orphan
    // ... seed via WikiPageService so links rebuild ...
    $result = app(WikiMaintainService::class)->run('manual');
    $this->assertGreaterThanOrEqual(1, $result['lint']['dead_links']);
    $this->assertGreaterThanOrEqual(1, $result['lint']['orphan_pages']);
}

public function test_run_records_a_maintain_wiki_run(): void
{
    Setting::setValue('wiki_enabled', '1');
    app(WikiMaintainService::class)->run('cron');
    $run = WikiRun::where('run_type', 'maintain')->latest('id')->first();
    $this->assertNotNull($run);
    $this->assertSame(WikiRunStatus::Completed, $run->status);
    $this->assertArrayHasKey('stale', $run->stage_results);
}

public function test_stale_open_ticket_sweep_flags_not_mines(): void
{
    Setting::setValue('wiki_enabled', '1');
    Bus::fake();
    // an open ticket idle > threshold with notes, never mined
    // ... seed ...
    $result = app(WikiMaintainService::class)->run('cron');
    $this->assertGreaterThanOrEqual(1, $result['open_tickets']['flagged']);
    Bus::assertNotDispatched(MineTicketKnowledge::class); // Phase-5 default: flag only
}
```

Run — FAIL.

- [ ] **Step 2: Implement `WikiMaintainService`**

```php
<?php

namespace App\Services\Wiki;

use App\Enums\WikiAuthorType;
use App\Enums\WikiComposeOutcome;
use App\Enums\WikiFactStatus;
use App\Enums\WikiPageKind;
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

    /** @return array<string,mixed> the stage_results stored on the maintain run */
    public function run(string $triggeredBy = 'cron'): array
    {
        // Maintain is event-shaped (not subject-state), but key a stable daily hash so a manual run
        // plus the 03:00 scheduled run on the same day update one ledger row instead of appending a dup.
        $run = WikiRun::updateOrCreate(
            ['run_type' => WikiRunType::Maintain->value, 'subject_type' => null, 'subject_id' => null,
                'source_content_hash' => 'maintain:'.now()->toDateString()],
            ['status' => WikiRunStatus::Running->value, 'triggered_by' => $triggeredBy, 'errors' => null],
        );

        $results = [
            'stale'        => $this->stalenessSweep(),
            'contradictions' => $this->contradictionSweep(),
            'lint'         => $this->linkLint(),
            'open_tickets' => $this->staleOpenTicketSweep(),
            'regen'        => $this->regenStaleOverviews(),
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
        $byClient = WikiFact::stale()->selectRaw('client_id, COUNT(*) c')->groupBy('client_id')->pluck('c', 'client_id');
        return ['total' => (int) $byClient->sum(), 'by_client' => $byClient->all()];
    }

    /**
     * Cross-source contradictions the per-run merge could not see (sync vs ticket facts that arrived
     * in separate runs). Deterministic, no AI. The "differ" test mirrors the merge stage EXACTLY —
     * `trim($statement)` equality (cf. WikiFactService reaffirm path) — so the sweep fires precisely
     * when merge would NOT have reaffirmed; no `normalizeSubjectKey` (that's the entity key, not a
     * value comparator). Pair-only; humans resolve (§4.4). Bounded so a garbage ticket can't mass-dispute.
     */
    private function contradictionSweep(): array
    {
        $filed = 0;
        // Candidate subjects = those with >1 live fact. Found at the DB layer so we never ->get() the
        // whole fact table; then load just the small per-subject groups.
        $candidateKeys = WikiFact::query()
            ->where('status', '!=', WikiFactStatus::Retired->value)
            ->selectRaw('client_id, subject_key, COUNT(*) c')
            ->groupBy('client_id', 'subject_key')
            ->havingRaw('COUNT(*) > 1')->get();

        foreach ($candidateKeys as $key) {
            if ($filed >= self::MAX_DISPUTES_PER_RUN) {
                break; // overflow is flagged in the run result, not filed
            }
            $rows = WikiFact::query()
                ->where('client_id', $key->client_id)->where('subject_key', $key->subject_key)
                ->where('status', '!=', WikiFactStatus::Retired->value)
                ->whereNull('disputed_with_fact_id')   // skip facts already in a dispute
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
                if (trim($other->statement) === trim($incumbent->statement)) {
                    continue; // identical wording — a reaffirmation, not a contradiction
                }
                // Respect a prior human dismissal: don't re-raise from already-dismissed evidence (§4.4).
                if ($incumbent->pinned
                    && WikiFactService::isSubsetOfDismissed($other->source_refs ?? [], $incumbent->dismissed_evidence ?? [])) {
                    continue;
                }
                $this->facts->markDisputed($other, $incumbent); // thin wrapper over the extracted pairAsDisputed()
                $filed++;
            }
        }
        return ['filed' => $filed];
    }

    private function linkLint(): array
    {
        $dead = WikiLink::whereNull('to_page_id')->count();
        // Skeleton-seeded pages (system-authored) start with no inbound links by design — exclude them
        // so a fresh client doesn't report ~6 false orphans. Genuine orphans are ai/human pages nothing links to.
        $orphans = WikiPage::active()
            ->where('kind', '!=', WikiPageKind::Overview->value)
            ->where('created_by_type', '!=', WikiAuthorType::System->value) // ADAPTATION: confirm skeleton seeds created_by_type=system
            ->whereDoesntHave('backlinks')
            ->count();
        $archivedRefs = WikiLink::whereHas('toPage', fn ($q) => $q->where('is_archived', true))->count();
        return ['dead_links' => $dead, 'orphan_pages' => $orphans, 'archived_refs' => $archivedRefs];
    }

    /**
     * Phase-5 default: FLAG long-idle open tickets carrying unmined knowledge — do NOT auto-mine.
     * Auto-mining is INERT today: MineTicketKnowledge returns early on empty($ticket->resolution), and
     * open tickets have none; covering them needs a resolution-independent context + a notes-folded
     * content hash (deferred — Task 2 risk note + Self-review). The flag is surfaced on the health/index
     * affordance (Task 3), not buried only in stage_results.
     */
    private function staleOpenTicketSweep(): array
    {
        $cutoff = now()->subDays(WikiConfig::staleOpenTicketDays());
        $candidates = Ticket::query()
            ->whereNotIn('status', $this->closedTicketStatuses())   // ADAPTATION: align with TicketStatus enum
            ->where('updated_at', '<', $cutoff)
            ->whereHas('notes')                                     // substantive content exists
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('wiki_runs')
                ->whereColumn('wiki_runs.subject_id', 'tickets.id')
                ->where('wiki_runs.subject_type', 'ticket')
                ->where('wiki_runs.status', WikiRunStatus::Completed->value))
            ->limit(200)->pluck('id');
        return ['flagged' => $candidates->count(), 'ticket_ids' => $candidates->take(50)->all()];
    }

    /**
     * Backstop for sync-driven fact changes (the sync writer never dispatches a recompose).
     * compose() returns a WikiComposeOutcome; only clients whose facts changed actually spend tokens
     * (hash-skip before any AI call), so O(clients) attempts is cheap. No fragile max(id) probing.
     */
    private function regenStaleOverviews(): array
    {
        if (WikiBudget::dailyLimitReached()) {
            return ['skipped' => 'budget'];
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

    /** @return array<int,string> */
    private function closedTicketStatuses(): array
    {
        return ['closed', 'resolved']; // ADAPTATION: align with TicketStatus enum
    }
}
```

ADAPTATION notes (resolve at implementation): (a) **`markDisputed` reuses, not duplicates, the merge writer.** Extract the pairing tail of `WikiFactService::upsertMinedFact` into a private `pairAsDisputed(WikiFact $a, WikiFact $b): void` that sets BOTH rows' `disputed_with_fact_id` symmetrically + both statuses `Disputed` in one transaction (the existing inline pairing already does this — factor it out); `markDisputed(WikiFact $challenger, WikiFact $incumbent)` is a thin public wrapper. A one-sided link would break `resolveDispute` and the panel's two-sided render. (b) **`WikiOverviewComposer::compose()` must return `WikiComposeOutcome`** (`Composed|SkippedUnchanged|SkippedBudget|SkippedNoOverview|Quarantined`) — a pure return-type addition; the mining job + fact controllers ignore the return and are unaffected. (c) **No `Ticket::wikiRuns` relation exists** — `wiki_runs` stores `subject_type='ticket'`+`subject_id`, not a morph; use the correlated `whereNotExists` subquery shown (same form in Task 6). Align `closedTicketStatuses()` with the real `TicketStatus` enum. (d) `Client::eachById` chunks to bound memory; `return false` halts the walk.

- [ ] **Step 3: `WikiMaintainCommand`**

```php
class WikiMaintainCommand extends Command
{
    protected $signature = 'wiki:maintain';
    protected $description = 'Nightly wiki maintenance: staleness, contradictions, link lint, stale-open tickets, stale-only overview regen.';

    public function handle(WikiMaintainService $service): int
    {
        if (! WikiConfig::maintenanceEnabled()) {
            $this->info('Wiki maintenance disabled — skipping.');
            return self::SUCCESS;
        }
        if (WikiBudget::dailyLimitReached()) {
            $this->warn('Daily wiki token budget already reached — sweeps run, regen will skip.');
        }
        $r = $service->run('cron');
        $this->line(sprintf('maintain: %d stale · %d disputes filed · %d dead links · %d orphans · %d open-ticket flags · %d overviews regenerated',
            $r['stale']['total'], $r['contradictions']['filed'], $r['lint']['dead_links'], $r['lint']['orphan_pages'],
            $r['open_tickets']['flagged'], $r['regen']['composed'] ?? 0));
        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Schedule it** — `routes/console.php` (match the existing idiom)

```php
Schedule::command('wiki:maintain')
    ->dailyAt('03:00')
    ->withoutOverlapping(60)   // minutes — overlap-lock guard for a Scheduled command. NOT ->expireAfter() (queue-middleware only; BadMethods on the Schedule Event).
    ->runInBackground()
    ->when(fn () => \App\Support\WikiConfig::maintenanceEnabled());
```

- [ ] **Step 5: Run, pass, commit**

```bash
./vendor/bin/pint app/Services/Wiki app/Console/Commands routes tests/Feature/Wiki
git add -A && git commit -m "feat(wiki): nightly maintenance loop (staleness/contradiction/link-lint/open-ticket/stale-only regen) + wiki:maintain schedule"
```

---

### Task 3: Health counters — add `stale` (§7 + §8.1 #4)

Extend the existing `healthCounts()` / `sectionSummaries()` with stale, and render it in the **existing** muted needs-review block. §8.1 #4 binds: secondary styling (`badge bg-secondary` at rest), zero-states silent, never "you have N items to review."

**Files:** `WikiController` (modify), `wiki/index.blade.php` (modify), `wiki/show.blade.php` (modify). **Test:** `WikiHealthCountersTest.php`.

- [ ] **Step 1: Failing test**

```php
public function test_health_counts_include_stale(): void
{
    [$client, $page] = $this->clientWithPage();
    // one stale volatile fact + one fresh
    $counts = app(WikiController::class)->healthCounts($client->id);
    $this->assertSame(1, $counts['stale']);
}

public function test_zero_state_is_silent(): void
{
    $client = Client::factory()->create();
    $resp = $this->actingAs($this->staff())->get(route('clients.wiki.index', $client));
    $resp->assertDontSee('Needs review');     // §8.1 #4 — no nag when clean
}
```

- [ ] **Step 2: Controller** — add stale to both aggregators

```php
    public function healthCounts(?int $clientId): array
    {
        $scope = fn ($q) => $clientId ? $q->where('client_id', $clientId) : $q->whereNull('client_id');
        return [
            'unverified' => WikiFact::where('status', WikiFactStatus::Unverified->value)->tap($scope)->count(),
            'disputed'   => WikiFact::where('status', WikiFactStatus::Disputed->value)->tap($scope)->count(),
            'stale'      => WikiFact::stale()->tap($scope)->count(),
        ];
    }
```

ADAPTATION: keep the existing scoping idiom in `healthCounts`. **`sectionSummaries()` cannot add stale via `whereIn('status', …)`** — staleness is a computed predicate, not a status. Compute it per anchor with the Task-1 scope: `WikiFact::stale()->where('page_id', $page->id)->get()->groupBy('section_anchor')`, then merge a `· N stale` segment into the existing per-anchor parts (which today query only Unverified+Disputed). The ambient line stays plain text (color-free), satisfying §8.1 "never color alone".

- [ ] **Step 3: Views** — render stale in the muted block (zero-state silent)

`wiki/index.blade.php` needs-review block: add `|| ($health['stale'] ?? 0) > 0` to the block's outer `@if` guard (so a wiki clean except for stale still surfaces the muted count — otherwise the nightly sweep's signal is invisible in the UI), and render, only when `> 0`, `<span class="badge bg-secondary">{{ $health['stale'] }} stale</span>`. `wiki/show.blade.php` section summaries: append stale to the ambient `text-muted` line. Keep stale visually **subordinate** to the actionable unverified/disputed counts and frame it as informational ("last confirmed >90d"), not a review demand — staleness regenerates continuously, so it must not read as a standing to-do (§8.1 #4). No new visual weight.

- [ ] **Step 4: Run, pass, commit**

```bash
./vendor/bin/pint app/Http/Controllers/Web resources tests/Feature/Wiki
git add -A && git commit -m "feat(wiki): surface stale-fact counts on indexes + page summaries (muted, zero-state silent)"
```

---

### Task 4: §8.1 verification-UX polish + page navigation (psa-7ph7 / psa-ux48 / psa-s5bf-A)

Pure Blade/controller. Closes the two QA UX findings and the binding §8.1 items not yet met. **Option A** of the open product decision.

**Files:** `_provenance.blade.php` (modify), `wiki/show.blade.php` (modify), `_page_nav.blade.php` (new). **Tests:** `WikiPageNavTest.php` (new), `WikiProvenancePanelTest.php` (extend).

- [ ] **Step 1: Failing test** — `WikiPageNavTest.php`

```php
public function test_page_has_index_sibling_and_search_nav(): void
{
    Setting::setValue('wiki_enabled', '1');
    $client = Client::factory()->create();
    app(WikiSkeletonService::class)->ensureForClient($client); // seeds network, infrastructure, m365, ...
    $resp = $this->actingAs($this->staff())->get(route('clients.wiki.show', [$client, 'network']));

    $resp->assertSee(route('clients.wiki.index', $client), false);                 // back-to-index link
    $resp->assertSee(route('clients.wiki.show', [$client, 'infrastructure']), false); // a sibling link
    $resp->assertSee('name="q"', false);                                           // on-page search input
}
```

(Extend `WikiProvenancePanelTest` with: the fact statement is on its **own full-width line** — the action buttons are NOT in the same flex row as the statement (assert they sit in a separate block beneath it); and the §4.4 reader-safety case — a `Disputed` fact whose counter is `source=sync` — renders the exact label "(superseded by sync, pending review)". Do **NOT** assert on a `Retired` fact: the controller excludes retired facts from the panel (`whereNot('status', Retired)`), so any retired-fact styling is dead code.)

- [ ] **Step 2: `_page_nav.blade.php`** — sibling list + index link + search (psa-7ph7)

A right-column partial (always rendered on `show`, unlike the backlinks card which only appears when backlinks exist):

```blade
<div class="card mb-3">
    <div class="card-header small text-uppercase">Wiki</div>
    <div class="card-body py-2">
        <form action="{{ $searchAction }}" method="get" class="mb-0">
            <input type="search" name="q" class="form-control form-control-sm" placeholder="Search this wiki" aria-label="Search wiki">
        </form>
    </div>
    <ul class="list-group list-group-flush">
        @foreach ($siblings as $sib)
            <li class="list-group-item py-1 {{ $sib['active'] ? 'active' : '' }}">
                <a href="{{ $sib['url'] }}" class="small {{ $sib['active'] ? 'text-white' : '' }}">{{ $sib['title'] }}</a>
            </li>
        @endforeach
    </ul>
</div>
```

The controller's `renderShow()` passes `$siblings` (same scope, ordered by kind/title, current flagged `active`), `$indexUrl`, `$searchAction`. ADAPTATION: derive siblings from the same query `clientIndex()` already runs; reuse it. Make the `wiki/show` breadcrumb segments links (scope → index → current) — these are now the **only** back-to-index affordance (the redundant in-card back-link was dropped per the UX gate). **Verify the active-item contrast against the resolved navy `--bs-primary` token** — `list-group-item active` + `text-white` is ~4.5:1 on default Bootstrap blue (no margin); if the theme's primary is brighter, use an explicit darker active background so it holds WCAG AA. Watch total right-column weight (nav + backlinks + provenance) so page content isn't crowded.

- [ ] **Step 3: `_provenance.blade.php`** — un-cram (psa-ux48) + superseded treatment (§8.1 #2)

**Two fixes. (i) psa-ux48 — un-cram:** give the statement its OWN full-width line; don't lead it with the badge. **(ii) §8.1 #2 — do NOT broadly style `Retired` facts:** the controller loads `$facts` with `whereNot('status', Retired)`, so retired facts never reach this panel — a retired-fact branch is dead code. And §4.4 reserves the dimmed/struck "superseded by sync" treatment for a *live* human claim that structured sync contradicts — that fact is `Disputed`, not `Retired`. The existing AI-challenge/dispute block at the top of `_provenance.blade.php` already renders both sides of a live dispute inline; **§8.1 #2 is satisfied there.** Only if a distinct dimmed/struck reader-safety row is wanted, scope it to exactly that case.

```blade
{{-- Stacked, full-width statement (psa-ux48). NOTE: $facts already EXCLUDES Retired (controller). --}}
<div class="wiki-fact mb-3 pb-2 border-bottom">
    <div class="mb-1"><span class="badge {{ $fact->status->badgeClass() }}">{{ $fact->status->label() }}</span></div>
    <div class="small mb-1">{{ $fact->statement }}</div>   {{-- own line, full width — reads without 1-2-words-per-line wrap --}}
    <div class="small text-muted mb-1">{{-- source refs (unchanged) --}}</div>
    <div class="d-flex flex-wrap gap-2">{{-- Confirm / Correct / Retire — unchanged outline classes (§8.1 #3) --}}</div>
</div>
```

§8.1 #2 (only if you add the dimmed sync-superseded row, inside the existing dispute block): render the sync-contradicted side's statement with `class="text-decoration-line-through" style="color:#6b7280;"` paired with the literal label `(superseded by sync, pending review)` — verified `#6b7280` = 4.83:1 on white (passes AA at body size); strikethrough never alone; never weaken the label to a generic "superseded". ADAPTATION: confirm `WikiFactStatus::label()` exists (survey shows `badgeClass()`; fall back to `ucfirst($fact->status->value)` if not). Confirm the existing dispute block renders a *sync-sourced* counter (it keys off the Ticket-sourced challenger today); the sync-vs-human direction is the one place to add the dimmed row. Polish (UX): wrap each Correct/Retire `<details>` so its expanded editor opens to **full width**, not inside the wrapping action flex row.

- [ ] **Step 4: Wire the partial into `wiki/show.blade.php`**

Render `_page_nav` in the right column above the conditional backlinks card. Make the breadcrumb links. Keep the provenance `<details>` toggle (§8.1 #1 progressive disclosure) intact.

- [ ] **Step 5: Run, pass, commit; update the QA beads**

```bash
./vendor/bin/pint app/Http/Controllers/Web resources tests/Feature/Wiki
git add -A && git commit -m "fix(wiki): page nav (index/siblings/search, psa-7ph7), un-cram provenance panel (psa-ux48), WCAG-AA superseded treatment (§8.1)"
```

On merge: close **psa-7ph7** and **psa-ux48**; comment on **psa-s5bf** that Option A shipped and the consolidated-view (Option B) remains the open design.

---

### Task 5: `wiki:export` — one-way Obsidian vault dump (§9)

Plain-text egress / backup. Folders by scope/kind, frontmatter carrying an **identifier-only** provenance summary (fact status counts, source ticket/run **IDs**, timestamps — never source ticket *content*), `[[wikilinks]]` intact. Default output `storage/app/wiki-exports/` (never under `storage/app/public/`).

**Files:** `WikiExportService` (new), `WikiExportCommand` (new). **Test:** `WikiExportTest.php`.

- [ ] **Step 1: Failing test**

```php
public function test_export_writes_obsidian_layout_with_identifier_only_frontmatter(): void
{
    Storage::fake('local');
    $client = Client::factory()->create(['name' => 'Acme']);
    $page = WikiPage::factory()->forClient($client)->create([
        'slug' => 'network', 'title' => 'Network', 'kind' => WikiPageKind::Environment,
        'body_md' => "## Equipment\n\nSee [[infrastructure]].\n",
    ]);
    WikiFact::factory()->create([
        'page_id' => $page->id, 'client_id' => $client->id, 'scope' => WikiScope::Client,
        'subject_key' => 'network:fw', 'statement' => 'FortiGate 60F', 'status' => WikiFactStatus::Confirmed,
        'source_type' => WikiFactSource::Ticket, 'source_refs' => [['type' => 'ticket', 'id' => 4242]],
    ]);

    $result = app(WikiExportService::class)->export();
    $path = $result['path'];

    $file = $path.'/clients/acme/environment/network.md';
    $this->assertFileExists($file);
    $md = file_get_contents($file);
    $this->assertStringContainsString('[[infrastructure]]', $md);    // wikilinks intact
    $this->assertStringContainsString('ticket: 4242', $md);           // source IDENTIFIER present
    $this->assertStringNotContainsString('FortiGate 60F', $md);       // fact STATEMENT (content) NOT reproduced — IDs only
    $this->assertStringStartsWith(storage_path('app'), $path);        // fenced under storage/app, never the docroot
}

// Also add: test_body_failing_scan_is_withheld_on_export() — a page whose body_md carries an
// injection/secret shape exports the '[…withheld…]' placeholder and appears in $result['withheld'];
// test_rejects_path_under_public_docroot() and test_rejects_traversal_path() — safeBase() throws.
```

- [ ] **Step 2: Implement `WikiExportService`**

```php
class WikiExportService
{
    public function __construct(private readonly WikiRedactor $redactor) {}

    /** @return array{path:string,written:int,withheld:array<int,string>} */
    public function export(?int $clientId = null, ?string $path = null, bool $includeArchived = false): array
    {
        $base = $this->safeBase($path);
        $pages = WikiPage::query()
            ->when(! $includeArchived, fn ($q) => $q->where('is_archived', false))
            ->when($clientId, fn ($q) => $q->where('client_id', $clientId))
            ->with(['facts', 'client'])->get();

        $written = 0;
        $withheld = [];
        foreach ($pages as $page) {
            $dir = $base.'/'.($page->scope === WikiScope::Global ? 'global' : 'clients/'.$this->clientSlug($page)).'/'.$page->kind->value;
            if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
                throw new \RuntimeException("Export failed to create {$dir}");
            }
            // SECURITY (gate F1): page bodies carry human/site_notes/pre-merge prose never output-scanned —
            // don't write a secret/injection to a plaintext vault. Mirror WikiRetrieval::safeEnvelope.
            $body = $page->body_md;
            if ($this->redactor->scan($body) !== []) {
                $body = '[Wiki page body withheld: failed content-safety scan]';
                $withheld[] = $page->slug;
            }
            $file = $dir.'/'.basename($page->slug).'.md';
            file_put_contents($file, $this->frontmatter($page)."\n".$body);
            @chmod($file, 0600);
            $written++;
        }
        return ['path' => $base, 'written' => $written, 'withheld' => $withheld];
    }

    /** SECURITY (gate F2): fence the output root — inside storage/app, never the web docroot, no traversal. */
    private function safeBase(?string $path): string
    {
        $base = $path ?: storage_path('app/wiki-exports/'.now()->format('Ymd-His'));
        if (str_contains($base, '..')) {
            throw new \RuntimeException('Export path may not contain "..".');
        }
        $real = $this->realAncestor($base); // realpath() the nearest EXISTING ancestor — leaf doesn't exist yet; defeats symlink/traversal
        $storage = realpath(storage_path('app'));
        $public = realpath(public_path());
        if ($storage === false || ! str_starts_with($real, $storage)) {
            throw new \RuntimeException('Export must be written under storage/app.');
        }
        if ($public !== false && str_starts_with($real, $public)) {
            throw new \RuntimeException('Refusing to export under the web docroot.'); // public/ is the docroot (public/storage symlinks into storage/app/public)
        }
        return $base;
    }

    /** Identifiers only — no source ticket content reproduced (§9). */
    private function frontmatter(WikiPage $page): string
    {
        $byStatus = $page->facts->countBy(fn ($f) => $f->status->value);
        $refs = $page->facts->flatMap(fn ($f) => $f->source_refs ?? [])
            ->map(fn ($r) => ($r['type'] ?? '?').': '.($r['id'] ?? '?'))->unique()->values();
        $lines = ['---', 'title: '.$page->title, 'scope: '.$page->scope->value, 'kind: '.$page->kind->value,
            'slug: '.$page->slug, 'exported_at: '.now()->toIso8601String(),
            'facts: '.$byStatus->map(fn ($c, $s) => "$s=$c")->values()->implode(' '),
            'sources:'];
        foreach ($refs as $ref) {
            $lines[] = '  - '.$ref;
        }
        return implode("\n", [...$lines, '---']);
    }
}
```

ADAPTATION: `clientSlug()` = `Str::slug($page->client->name)`; eager-load `client`. Frontmatter is identifiers only — assert in the test that no `statement`/ticket body appears there.

- [ ] **Step 3: `WikiExportCommand`** — `wiki:export {client?} {--all} {--path=} {--include-archived}`; prints `$result['path']`, the written count, and any withheld slugs; `safeBase()` refuses docroot/traversal paths and the command surfaces the exception. ADAPTATION: `realAncestor()` walks up to the first existing dir and `realpath()`s it (so symlinks/`..` can't escape the storage fence).

- [ ] **Step 4: Run, pass, commit**

```bash
./vendor/bin/pint app/Services/Wiki app/Console/Commands tests/Feature/Wiki
git add -A && git commit -m "feat(wiki): wiki:export — one-way Obsidian vault dump, identifier-only provenance, non-public output"
```

---

### Task 6: `wiki:backfill` — populate history from closed tickets (§5.1 / #16)

Thin reuse of `MineTicketKnowledge`, **oldest-first** (freshest knowledge lands last and wins reaffirmations/disputes), bounded by `wiki_backfill_batch_size` and the shared daily budget. **Dry-run by default** with a mandatory cost estimate (#16); writes only with `--execute`. Idempotent via the existing content-hash (already-mined tickets are skipped).

**Files:** `WikiBackfillService` (new), `WikiBackfillCommand` (new). **Test:** `WikiBackfillTest.php`.

- [ ] **Step 1: Failing test**

```php
public function test_dry_run_writes_nothing_and_estimates(): void
{
    Setting::setValue('wiki_enabled', '1');
    Bus::fake();
    $this->closedTicketsWithResolutions(3);

    $plan = app(WikiBackfillService::class)->plan(null, 25); // dry-run estimate
    $this->assertSame(3, $plan['ticket_count']);
    $this->assertGreaterThan(0, $plan['estimated_tokens']);
    Bus::assertNotDispatched(MineTicketKnowledge::class);
    $this->assertSame(0, WikiRun::count());
}

public function test_oldest_first_and_batch_capped(): void
{
    Setting::setValue('wiki_enabled', '1');
    Bus::fake();
    $tickets = $this->closedTicketsWithResolutions(5); // created oldest→newest
    app(WikiBackfillService::class)->execute(null, 2);
    Bus::assertDispatchedTimes(MineTicketKnowledge::class, 2);
    // assert the two dispatched are the OLDEST two (inspect dispatched job ticketIds)
}

public function test_already_mined_ticket_is_skipped(): void { /* prior completed run on its content-hash → not re-dispatched */ }

public function test_budget_exhausted_stops_early(): void { /* tiny daily limit + a prior spend row → 0 dispatched */ }
```

**Test note:** every `execute()` test must also `Setting::setValue('wiki_auto_mine', '1')` — backfill gates on it. Add `test_auto_mine_off_dispatches_zero_and_warns()`.

- [ ] **Step 2: `WikiBackfillService`** — `plan()` (estimate, no writes) + `execute()` (dispatch under caps)

```php
class WikiBackfillService
{
    public function plan(?int $clientId, int $batch): array
    {
        $tickets = $this->candidates($clientId, $batch);
        $count = $tickets->count();
        return [
            'ticket_count' => $count,
            'auto_mine_on' => WikiConfig::autoMineEnabled(),
            'estimated_tokens' => min($count * $this->perTicketEstimate(), WikiConfig::dailyTokenLimit()), // ≤ daily ceiling, not the per-run CAP
            'daily_ceiling' => WikiConfig::dailyTokenLimit(),
            'oldest' => $tickets->first()?->id, 'newest' => $tickets->last()?->id,
        ];
    }

    /** Realistic per-ticket cost from recent mine runs; nominal fallback when history is thin. */
    private function perTicketEstimate(): int
    {
        $recent = WikiRun::where('run_type', WikiRunType::MineTicket->value)
            ->whereNotNull('ai_tokens_used')->latest('id')->limit(20)->get()
            ->map(fn ($r) => (int) ($r->ai_tokens_used['input'] ?? 0) + (int) ($r->ai_tokens_used['output'] ?? 0))->filter();

        return $recent->isNotEmpty() ? (int) $recent->avg() : 12_000; // nominal until history exists
    }

    public function execute(?int $clientId, int $batch): int
    {
        // Mining HARD-gates on autoMineEnabled() (Gate 1 in MineTicketKnowledge); without it every
        // dispatched job no-ops. Gate backfill the SAME way so we never report phantom work.
        if (! WikiConfig::autoMineEnabled()) {
            return 0;
        }
        $dispatched = 0;
        foreach ($this->candidates($clientId, $batch) as $ticket) {
            if (WikiBudget::dailyLimitReached()) {
                break;
            }
            MineTicketKnowledge::dispatch($ticket->id, 'backfill');
            $dispatched++;
        }
        return $dispatched;
    }

    /** Closed, resolved, oldest-first, not already mined-to-completion (no Ticket::wikiRuns relation — subquery). */
    private function candidates(?int $clientId, int $batch)
    {
        return Ticket::query()
            ->whereIn('status', ['closed', 'resolved'])      // ADAPTATION: TicketStatus enum
            ->whereNotNull('resolution')
            ->when($clientId, fn ($q) => $q->where('client_id', $clientId))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('wiki_runs')
                ->whereColumn('wiki_runs.subject_id', 'tickets.id')
                ->where('wiki_runs.subject_type', 'ticket')
                ->where('wiki_runs.run_type', WikiRunType::MineTicket->value)
                ->where('wiki_runs.status', WikiRunStatus::Completed->value))
            ->orderBy('closed_at')->orderBy('id')             // oldest-first (ADAPTATION: confirm closed_at column)
            ->limit($batch)->get();
    }
}
```

ADAPTATION: `MineTicketKnowledge`'s 2-arg constructor is `(int $ticketId, string $triggeredBy = 'auto')` — pass `'backfill'`. The `wikiRuns` "already mined" predicate must match the content-hash idempotency the job enforces (the job itself re-checks, so a spurious dispatch is harmless — it no-ops — but pre-filtering keeps the batch count honest). Confirm the `Ticket`↔`wiki_runs` relation.

- [ ] **Step 3: `WikiBackfillCommand`** — dry-run default

```php
    protected $signature = 'wiki:backfill {--client= : Limit to one client} {--batch= : Override batch size} {--execute : Actually mine (otherwise dry-run)}';

    public function handle(WikiBackfillService $service): int
    {
        $batch = (int) ($this->option('batch') ?: WikiConfig::backfillBatchSize());
        $clientId = $this->option('client') ? (int) $this->option('client') : null;
        $plan = $service->plan($clientId, $batch);

        $this->line("Backfill plan: {$plan['ticket_count']} ticket(s), est. ~{$plan['estimated_tokens']} tokens (≤ today's {$plan['daily_ceiling']} ceiling — backfill cannot exceed it and resumes next day), oldest #{$plan['oldest']} → newest #{$plan['newest']}.");
        if (! $plan['auto_mine_on']) {
            $this->warn('wiki_auto_mine is OFF — mining is gated; --execute would dispatch nothing. Enable auto-mine to backfill.');
        }
        if (! $this->option('execute')) {
            $this->info('Dry run — nothing mined. Re-run with --execute to dispatch.');
            return self::SUCCESS;
        }
        $n = $service->execute($clientId, $batch);
        $this->info("Dispatched {$n} mining job(s) (capped by batch + daily budget).");
        return self::SUCCESS;
    }
```

- [ ] **Step 4: Run, pass, commit**

```bash
./vendor/bin/pint app/Services/Wiki app/Console/Commands tests/Feature/Wiki
git add -A && git commit -m "feat(wiki): wiki:backfill — oldest-first reuse of mining, dry-run-by-default cost estimate, budget-capped"
```

---

### Task 7: Residual hardening (Phase-4 carry-overs)

Close the documented Phase-4 residuals that are cheap and correctness-relevant. Larger residuals are explicitly deferred (see Self-review).

**Files:** `WikiController` / `WikiPageService` (Overview-edit consistency), `settings/general.blade.php` (surface the new settings). **Tests:** `WikiOverviewEditConsistencyTest.php` (new); `WikiFactActionsTest.php` (retire actor — extend Task 1's behavior assertion).

- [ ] **Step 1: Overview-edit consistency** — a human edit of an Overview body must not leave a stale `composed_hash` that makes the next compose skip a now-divergent overview.

```php
public function test_editing_overview_body_clears_composed_hash(): void
{
    Setting::setValue('wiki_enabled', '1');
    $client = Client::factory()->create();
    $page = WikiPage::factory()->forClient($client)->create([
        'kind' => WikiPageKind::Overview, 'slug' => 'overview',
        'meta' => ['composed_at' => now()->toIso8601String(), 'composed_hash' => 'deadbeef'],
    ]);
    $this->actingAs($this->staff())->patch(route('wiki.update', $page), ['body_md' => "## Env\n\nHand-edited.\n"])->assertRedirect();
    $this->assertArrayNotHasKey('composed_hash', $page->fresh()->meta ?? []);
}
```

Fix: where `WikiController::update` (or `WikiPageService::updateBody`) persists an Overview-kind body edit, strip `composed_hash` from `meta` (keep `composed_at` so the overview keeps injecting until the nightly/next-mine recompose regenerates it). ADAPTATION: confirm whether meta is written in the controller or `updateBody`; do it in the single write path.

- [ ] **Step 2: retire actor** — assert `WikiFactController::retire` records `retired_by` (the column + service change landed in Task 1; this pins the HTTP path).

```php
public function test_retire_records_actor(): void
{
    $user = $this->staff();
    $fact = /* a confirmed fact */;
    $this->actingAs($user)->post(route('wiki.facts.retire', $fact))->assertRedirect();
    $this->assertSame($user->id, $fact->fresh()->retired_by);
}
```

- [ ] **Step 3: Surface the new settings + correct the cost copy** — add `wiki_staleness_days_volatile`, `wiki_maintenance_enabled`, `wiki_backfill_batch_size` to `settings/general.blade.php` under the existing wiki block, with the spec defaults and one-line help (`wiki_stale_open_ticket_days` optional — only if the open-ticket flag is surfaced in the UI). **Extend the existing wiki cost paragraph** (today it reads "$2–8/day" and describes only mining + recompose-on-mine) to add: (1) nightly maintenance recomposes **only clients whose facts changed since the last run** (hash-skip), so steady-state nightly cost is near-zero; (2) `wiki:backfill` is a separate, operator-initiated, **dry-run-first** spend that respects the same daily ceiling; (3) keep one hard-cap sentence. One short paragraph, no new mechanism.

- [ ] **Step 4: Run, pass, commit**

```bash
./vendor/bin/pint app/Http/Controllers/Web app/Services/Wiki resources tests/Feature/Wiki
git add -A && git commit -m "fix(wiki): clear composed_hash on Overview edit, record retire actor, expose Phase-5 settings"
```

---

## Final verification

- [ ] `php artisan test` — full suite green (Phase-4 baseline + all new Wiki tests).
- [ ] `vendor/bin/pint --test app resources routes database tests` — clean.
- [ ] **Secret guard** (must be empty): `git diff -U0 main...HEAD | grep -nEi '@couttspnw\.com|-----BEGIN [A-Z ]*PRIVATE KEY-----|xox[baprs]-[0-9A-Za-z-]{8,}|AKIA[0-9A-Z]{16}'`.
- [ ] **Maintenance smoke:** seed a cross-source contradiction + a dead link + a stale volatile fact; run `php artisan wiki:maintain`; confirm one `maintain` run with the expected `stage_results` and that the contradiction is now `disputed`.
- [ ] **Schedule smoke:** `php artisan schedule:list` shows `wiki:maintain` at 03:00, gated by `maintenanceEnabled`.
- [ ] **Export smoke:** `wiki:export --all` writes under `storage/app/wiki-exports/…`, frontmatter carries IDs not content, `[[wikilinks]]` survive, nothing lands under `public/`.
- [ ] **Backfill smoke:** `wiki:backfill` (no flags) prints a count + token estimate and mines nothing; `--execute` dispatches ≤ batch, oldest-first, and stops at the daily budget.
- [ ] **UX smoke (manual browser):** a client wiki page shows breadcrumb links + a sibling list + an on-page search box (psa-7ph7); the provenance panel reads on full lines with actions stacked below (psa-ux48); a retired fact shows muted-ink + strikethrough + "(superseded, pending review)".
- [ ] **Health smoke:** indices show `stale` only when `> 0`, in muted `bg-secondary`; a clean wiki shows no "Needs review" line.
- [ ] **Queue note:** `wiki:maintain` and `wiki:backfill` enqueue `ComposeClientOverview`/`MineTicketKnowledge`; the dev worker is already current, but after merge re-confirm `soundit-psa-queue.service` is running the merged code (`sudo systemctl restart soundit-psa-queue.service` if in doubt).

## Self-review (spec coverage + residual gaps)

| Requirement | Task | Status |
|---|---|---|
| §7 staleness sweep (volatile, sync-exempt) | 1–2 | Done + matrix test |
| §7 contradiction sweep (cross-source, deterministic) | 2 | Done (pair-only, humans resolve) |
| §7 link lint (dead/orphan/archived-ref) | 2 | Done (measurement; no auto-repair) |
| §7 hot-summary regen — stale clients only | 2 | Done (composer hash-skip; sync-change backstop) |
| #16 never-closing tickets via a maintenance sweep | 2 | **Partial — FLAG ONLY (actionable surface);** auto-mine is *inert today* (open tickets have no resolution) → opt-in fast-follow needs a resolution-independent context + notes-folded hash |
| §7 health counters (unverified/disputed/stale) | 3 | Done + zero-state-silent test |
| §8.1 #1 provenance progressive disclosure | 4 | Pre-existing `<details>` retained |
| §8.1 #2 superseded WCAG-AA | 4 | Met by the existing inline dispute block; optional dimmed row scoped to `Disputed`+sync counter (NOT `Retired`, which the panel excludes) |
| §8.1 #3 right-sized fact actions | 4 | Pre-existing outline styling retained; relocated below statement |
| §8.1 #4 health counters secondary / never a nag | 3 | Done |
| §8.1 #5 AI-addenda distinct, not alarming | 4 | Pre-existing flat-tonal block verified |
| psa-7ph7 page nav dead-ends | 4 | Done (index/sibling/search/breadcrumb) |
| psa-ux48 cramped provenance | 4 | Done (stacked layout) |
| psa-s5bf consolidated nav (umbrella) | — | **Deferred — Option B, open product decision** |
| §9 `wiki:export` (Obsidian, identifier-only, non-public) | 5 | Done |
| §5.1/#16 `wiki:backfill` (oldest-first, dry-run, budget) | 6 | Done |
| Phase-4 residual: Overview-edit ↔ composed_hash | 7 | Done |
| Phase-4 residual: retire actor | 1, 7 | Done (`retired_by`) |

**Explicitly deferred (not silently dropped):**
- **Stale-open-ticket auto-mining** — needs a content-hash that folds in a notes digest (today's key is `ticket.id | resolution`) and a policy for mining incomplete tickets + budget. Phase 5 flags; auto-mine is opt-in.
- **psa-s5bf consolidated client-environment view** (Option B) — wants its own short design pass.
- **`wiki_get_page` body fencing** — `scan()` is a finite corpus; fencing all page bodies as untrusted data is a stronger control, still a candidate (Phase-4 self-review carry-over).
- **Retrieval-time fact re-scan** for facts written before the merge-time filter / via the human-fact path (§4.4). *Conscious v1 call:* facts are scanned at mining write-time and the inline dispute + overview paths are scanned on read; the residual is the §4.4 human-authored fact path. Acceptable for v1 because the scan corpus is finite and that path is staff-authored — revisit if the wiki ingests less-trusted human input.
- **SQL-side budget aggregation** (`JSON_EXTRACT`) — `WikiBudget` sums in PHP for SQLite-test portability; per-day row count is budget-bounded, so this stays a perf candidate only if the ledger grows.
- **Mining merge/compose N+1** page lookups — eager-load cleanup; minor.
- **`site_notes` fallback injected unscanned** (live whenever an overview is empty — every client until first mine). *Conscious v1 call:* `site_notes` is staff-authored legacy content (unchanged Phase-3 behavior); only the overview path is scanned at the inject point. Pull a scan-on-serve here if `site_notes` ever carries less-trusted input.

---

## Planning provenance

Authored 2026-06-14 from a green `main` (Phase 4 merged @ `30cc870`). Grounded in: the wiki design spec (§7/§8.1/§9), the mining-coverage decisions (#16), the Phase-4 plan's self-review residual list, a full read-only survey of the live wiki module (services/enums/scheduler/views/tests), and the three QA UX findings (psa-7ph7 / psa-ux48 / psa-s5bf). **Next step: four-lens persona gate (security / architecture / product / UX) before implementation** — findings fold in as a "Persona review — incorporated (Rev 2)" section, matching the Phase-4 process.
