# Closeout Pipeline — Post-Resolution Knowledge Enrichment

**Date:** 2026-07-09
**Bead:** psa-8f74
**Status:** Draft (brainstorm + spec — pending owner review)
**Builds on:** Client Wiki design (`docs/superpowers/specs/2026-06-12-client-wiki-design.md`, Phases 1–5), the Triage pipeline (`app/Services/Triage/`), mining-coverage decisions (`docs/superpowers/specs/2026-06-13-client-wiki-mining-coverage-decisions.md`).
**Relates to:** psa-s5bf (wiki UX), the deferred ticket-detail sidebar (wiki spec §8/§12).

---

## 1. Problem & vision

Sound PSA has a rich **intake** pipeline — Triage (`app/Services/Triage/TriagePipeline.php`) —
that runs the moment a ticket is created: resolve contact, filter junk, classify billability,
match assets, deep technical triage. It is a staged, budgeted, ledgered, human-in-the-loop
orchestrator with per-stage toggles.

At the **other end** of the ticket lifecycle, the same kind of value is being produced — but by
**standalone jobs wired implicitly through an observer**, not by a pipeline. The owner's vision
(psa-8f74) is to grow this into the **outbound-side counterpart to Triage**: an explicit
*post-resolution / closeout* pipeline. Triage runs at intake; this runs at resolution. Where
Triage prepares a ticket for a human to *work*, closeout captures what was *learned* and closes
the wiki's verification loop (wiki spec §3, §6: "triage reads wiki facts → the ticket gets
worked → close mining reaffirms or contradicts what was read. Ticket flow is the verification
engine").

### 1.1 What already exists (the pipeline in embryo)

Since this bead was filed (2026-06-13), two post-resolution stages have shipped and already run
back-to-back — they just aren't *named* or *orchestrated* as a pipeline:

- **Knowledge mining** — `MineTicketKnowledge` (`app/Jobs/MineTicketKnowledge.php`): on
  resolution, mines durable client-environment facts into the wiki
  (gather → extract → scan/quarantine → merge → compose), recorded as `WikiRunType::MineTicket`.
- **Resolution drafting** — `TicketResolutionDrafter` (`app/Services/TicketResolutionDrafter.php`)
  + `GenerateTicketResolution` (`app/Jobs/GenerateTicketResolution.php`): when a ticket reaches
  a terminal state with an **empty** resolution, AI drafts a concise 1–3 sentence resolution
  from ticket context, writes it with `resolution_ai_drafted = true` (surfaced as an
  "AI-drafted · review" badge, `resources/views/tickets/show.blade.php:65`), recorded as
  `WikiRunType::DraftResolution`. Shipped **2026-06-14**, the day after this bead — it already
  closes the two gaps the bead names ("Resolve doesn't prompt for a resolution"; "empty
  resolution → nothing to mine").

They are chained today through **`TicketObserver::updated()`** and the resolution write
(`app/Observers/TicketObserver.php:75-99`): terminal + empty resolution → dispatch the drafter;
the drafter's save re-fires the observer; terminal + filled resolution → dispatch the miner.
Both stages share the **same wiki machinery**: the `WikiTicketContext` gather primitive, the
`WikiRedactor` secret/injection scan, the `WikiBudget` shared daily token pool, and the
`wiki_runs` ledger (six run types, five writers — `wiki_runs` is already the shared
post-resolution ledger).

So the "Miner → broader closeout pipeline" evolution the bead asks for is **half-done by
accretion**. The problem now is *structural*, not greenfield: the flow is implicit
(observer branches + a DB-save re-entrancy trick), it has no single entry point, no per-stage
opt-in, no pipeline-level observability, and no obvious slot for the three remaining
responsibilities. Every new stage added the current way is another `TicketObserver::updated()`
branch and another loop-avoidance comment — the observer is already carrying four side-effects
(§3.2). That does not scale to doc-gap detection, KB drafting, and follow-up tickets.

### 1.2 The remaining responsibilities (from the bead)

3. **Fill documentation gaps** — detect missing/stale client-environment info implied by the
   ticket and flag it, so the wiki stays *complete*, not just append-only. **Not built.**
4. **(later)** Draft/flag **KB articles** — tech-facing runbooks and client-facing how-tos —
   from recurring resolutions/patterns. The wiki *data model* for this exists (§4.6 global
   `runbooks/`, `patterns/`; the extractor is *specified* to optionally emit runbook-deviation
   / cross-client-pattern candidates, wiki spec §5.2) but **global pattern/runbook mining was
   deferred out of Phase 3 and not built in Phase 5** — this stage would *complete* an
   existing-but-dormant design.
5. **(later)** Generate/flag **follow-up tickets** — preventive/related work the resolution
   implies (e.g. "apply the same firmware fix at the client's other sites"). **Not built.**
   Nearest precedent: Phase 5's stale-open-ticket sweep, which **flags only, never acts**.

This design (brainstorm → spec) plus its companion phased plan
(`docs/superpowers/plans/2026-07-09-closeout-pipeline-phased-plan.md`) **(a)** consolidates the
two existing stages into an explicit pipeline and **(b)** specifies the three new stages on top
of it.

### 1.3 Guiding principles

- **Formalize before extending.** Phase 1 is a *behavior-preserving* consolidation of the two
  existing stages behind one orchestrator + one dispatch point. No new AI spend, no new outputs
  — just structure. Only then do new stages slot in. This de-risks the expansion and is the
  single highest-leverage step (it is the seam everything else hangs off).
- **Symmetry with Triage.** Same anatomy: an orchestrator, a run record, per-stage
  `stageEnabled()` toggles (cf. `TriageConfig::stageEnabled()`), isolated per-stage try/catch so
  one stage's failure never aborts the rest, a shared daily token ceiling, a system pseudo-user.
- **Reuse the wiki's machinery; do not fork it.** `WikiTicketContext`, `WikiRedactor`,
  `WikiBudget`, `wiki_runs`, `WithoutOverlapping` per client, content-hash idempotency, and the
  `WikiRetrieval` read boundary already exist and are hardened. New stages compose them.
- **Graded human-in-the-loop, matched to stakes** (§5). Inert descriptions (facts, drafted
  resolutions) may auto-write with a review marker, as they do today. **Actions** (creating a
  follow-up ticket) are *suggest-only, a human commits* — the Phase-5 flag-only precedent.
- **Respect the §6 read-back boundary** (§4). Any stage that reads *wiki* content back into an
  AI prompt must go through `WikiRetrieval` (structured serving + scanned bodies + cross-client
  `WHERE`), never raw `wiki_facts.statement` / `wiki_pages.body_md`.
- **Standalone / OSS-friendly.** Everything no-ops when the wiki or AI is disabled, exactly as
  today (master gate `WikiConfig::autoMineEnabled()`, graceful AI degradation).

---

## 2. Naming

The bead asks for a name symmetric with "Triage" (intake). Candidates: *post-triage*,
*Closeout*, *Resolution*, *Wrap-up*, *Scribe*, *Curator*.

**Recommendation: "Closeout pipeline."** It names the *lifecycle stage* (ticket closeout)
exactly as "triage" names the intake stage — the cleanest symmetry — is PSA-native, collides
with nothing, and generalizes over *all* stages (mining, resolution drafting, doc-gaps, KB,
follow-ups are all closeout activities), not just documentation.

| Candidate | Verdict |
|-----------|---------|
| **Closeout** ✅ | Lifecycle-stage symmetry with triage; neutral; generalizes over every stage. |
| Resolution | Collides with the `tickets.resolution` field and with one specific stage. |
| post-triage | Defines itself by negation; awkward as a namespace. |
| Wrap-up | Informal; weak as a class/namespace name. |
| Scribe / Curator | Evocative but *narrow* (documentation only); undersell follow-ups & resolution. Good candidates for a **sub-component** name, not the pipeline. |

**Home: `app/Services/Closeout/`**, mirroring `app/Services/Triage/`. This is a deliberate
choice over the alternative `app/Services/Wiki/Closeout/`: the pipeline is **broader than the
wiki** — it drafts resolutions (a ticket field) and proposes follow-up tickets (not wiki writes
at all). The existing stages already reflect this breadth: `TicketResolutionDrafter` lives at
top-level `app/Services/`, not under `Wiki/`. The wiki keeps its own vocabulary (mine, compose,
`wiki_runs`) for wiki-specific stages; "closeout" is the umbrella that sequences them.

| Triage (intake) | Closeout (resolution) |
|---|---|
| `app/Services/Triage/TriagePipeline.php` | `app/Services/Closeout/CloseoutPipeline.php` *(new)* |
| `app/Support/TriageConfig.php` | `app/Support/CloseoutConfig.php` *(new, thin)* |
| `app/Jobs/RunTriagePipeline.php` | `app/Jobs/RunCloseoutPipeline.php` *(new)* |
| `TicketObserver::created()` → triage | `TicketObserver::updated()` → closeout |
| `triage_runs` ledger | **reuse `wiki_runs`** + `WikiRunType::Closeout` (§3.1) |

---

## 3. Architecture — formalize the emergent flow

The orchestrator is modeled on `TriagePipeline` (`app/Services/Triage/TriagePipeline.php`):
open a run record, check the daily token ceiling, bind `AiClient` with the model override, run
each stage through a `runStage($name, $ticket, $handler)` helper that (a) short-circuits if the
stage is disabled, (b) wraps the handler in try/catch so one failure never aborts the rest,
(c) appends to `stagesCompleted` / `stageResults` / `errors`. This helper is
`TriagePipeline::runStage()` (`:215`) transplanted.

```
                       CLOSEOUT PIPELINE  (runs at ticket resolution)
   trigger                                                            outputs (graded HITL, §5)
 ┌──────────────┐   ┌───────────────────────────────────────────────┐
 │ TicketObserver│   │ CloseoutPipeline::run(Ticket, triggeredBy)     │
 │ ::updated()   │   │  pre: autoMineEnabled? daily budget? not a     │
 │ terminal &&   │──►│       merge-closure? per-stage enabled?        │
 │ (status||res  │   │  ┌───────────────────────────────────────────┐ │
 │  changed)     │   │  │ Stage A  Resolution Drafting  (EXISTS)  ──►│ │──► tickets.resolution
 │  → dispatch   │   │  │          TicketResolutionDrafter           │ │    (+ ai_drafted badge)
 │  ONE job      │   │  │ Stage B  Knowledge Mining  (EXISTS)  ─────►│ │──► wiki_facts (+ wiki_runs)
 │  (replaces 2  │   │  │          MineTicketKnowledge logic         │ │
 │   branches)   │   │  │ Stage C  Doc-Gap Detection  (NEW)  ───────►│ │──► needs-review flags
 └──────────────┘   │  │ Stage D  KB / Runbook Drafting  (NEW,later)►│ │──► draft KB pages
   also: manual     │  │ Stage E  Follow-up Suggestions  (NEW,later)►│ │──► suggested tickets
   button, cron     │  └───────────────────────────────────────────┘ │
                    │  each stage: CloseoutConfig::stageEnabled(x)?    │
                    │  shares WikiBudget · records a wiki_runs row     │
                    └───────────────────────────────────────────────┘
                       orchestration row: wiki_runs (WikiRunType::Closeout)
                       stages_completed / stage_results / errors / tokens
```

**Explicit sequencing replaces observer re-entrancy.** Today, drafting and mining are two
observer dispatches chained by a DB save (the drafter writes `resolution`, which re-fires the
observer, which dispatches the miner). The pipeline runs them **in order in one job**: draft (if
the resolution is empty) → then mine. This removes the re-entrancy trick and the two
loop-avoidance branches, and gives later stages a natural place in the sequence. (The
idempotency hash and the "human edited the resolution → re-mine" behavior are preserved; §3.2.)

### 3.1 Ledger: reuse `wiki_runs`, do **not** clone `triage_runs`

**Decision: no new `closeout_runs` table.** `wiki_runs` is *already* the shared post-resolution
ledger — six `WikiRunType` cases (`mine_ticket`, `sync_facts`, `maintain`, `backfill`, `compose`,
`draft_resolution`) across five writers, with per-stage `stage_results`, `errors`,
`ai_tokens_used`, `source_content_hash` idempotency, and `WikiRunStatus`
(pending/running/completed/failed/quarantined). Both existing closeout stages already write it.

- Each **stage** continues to write its own `wiki_runs` row via its existing service
  (`MineTicket`, `DraftResolution`, and new cases `DocGap`, `KbDraft`, `Followup`). Stage-level
  provenance, quarantine, and budget accounting are unchanged.
- The **orchestrator** writes one parent row, new `WikiRunType::Closeout`, capturing
  `stages_completed`, per-stage outcome summaries in `stage_results`, `errors`, and rolled-up
  `ai_tokens_used`. It links to child rows by `(subject_id, created_at)` window. This mirrors
  how `triage_runs` records the pipeline run while domain writes happen alongside — but stays in
  the one ledger the wiki UI, health counters, and `WikiBudget` already read.

*Rejected alternative:* a `closeout_runs` table cloning `triage_runs`. Rejected because it
fragments post-resolution observability across two ledgers, forks `WikiBudget`'s single-pool
accounting (which sums *all* `wiki_runs` today), and duplicates a schema that already exists.
The only thing `wiki_runs` lacks vs `triage_runs` is timing/feedback columns; if pipeline-level
`duration_ms` is wanted, add it to `wiki_runs` rather than clone the table.

### 3.2 Trigger — one dispatch, stage-gated internally

`TicketObserver::updated()` today has **two** closeout branches (`app/Observers/TicketObserver.php:75-99`):
the miner (`terminal && (status||resolution changed) && filled(resolution) && autoMineEnabled`)
and the drafter (`terminal && status changed && empty(resolution) && autoMineEnabled`). Replace
both with a **single** dispatch:

```php
if ($isTerminal && ($ticket->wasChanged('status') || $ticket->wasChanged('resolution'))
    && WikiConfig::autoMineEnabled()) {
    RunCloseoutPipeline::dispatch($ticket->id);
}
```

The pipeline reproduces every current behavior through **stage gating**, not observer branching:

- **Resolution empty** → Stage A drafts it (writes `resolution` + `resolution_ai_drafted=true`,
  exactly as `GenerateTicketResolution` does now), then Stage B mines the drafted text in the
  same run. No DB-save re-entrancy needed.
- **Resolution filled (human or prior AI draft)** → Stage A no-ops (never overwrite a present
  resolution — wiki spec §2 "Humans own their text; AI never rewrites it"), Stage B mines.
- **Human edits the resolution later** → observer re-fires on `wasChanged('resolution')`; Stage A
  no-ops (filled), Stage B re-mines to capture the correction — the content-hash key
  (`sha256(ticket_id | resolution)`) makes an unchanged re-fire a no-op and a changed one a
  reaffirm, identical to today.
- **Recursion guard** carries over: the pipeline's system pseudo-user is skipped by the observer,
  and merge-closures (`parent_ticket_id !== null`) are skipped, as the miner does now.

Additional entry points, all optional and gated (mirroring today's manual/backfill paths):

- **Manual** — a "Run closeout" button on the resolved-ticket view (like the existing "Draft
  with AI" button, `resources/views/tickets/show.blade.php:1194`), `triggeredByUserId` set.
- **`closeout:sweep`** cron — batched, budget-bounded, priority-ordered sweep over recently
  resolved tickets whose pipeline never ran or failed, mirroring `wiki:backfill` and
  `triage:review-open`. (`wiki:backfill` already covers mining backfill; the sweep generalizes
  it to the whole pipeline.)

---

## 4. The §6 read-back boundary (already built — route through it)

The bead flags: *"anything that READS wiki content back into AI context still needs that
hardening first."* **That hardening now exists.** Phase 4 shipped
`app/Services/Wiki/Retrieval/WikiRetrieval.php` — the single §6 boundary — and merged it to
`main`. So this is no longer a blocker to *build*; it is a boundary to *use*.

**What §6 requires** (wiki spec §6, verbatim):

> **Structured serving.** Facts are serialized to AI consumers as delimited structured records —
> e.g. `WIKI_FACT | subject: asset:DC01:ram | status: unverified | claim: "…"` — never as prose
> woven into the prompt… **Cross-client isolation at the query layer.** `client_id = null`
> returns global scope only… a `WHERE` clause (`client_id = ? OR scope = 'global'`), enforced in
> the tool implementation… never a prompt instruction.

`WikiRetrieval` implements exactly this: delimited `WIKI_FACT | …` records with JSON-encoded
free-text values (so a malicious statement can't forge record structure), control-char /
U+2028/2029 stripping, page bodies re-run through `WikiRedactor::scan()` before serving
(`safeEnvelope`), disputed facts served two-sided, and the cross-client `WHERE` in
`WikiSearchService::aiSearch`.

**Rule for new closeout stages:** any stage that reads wiki facts or page bodies into an AI
prompt **must** obtain them via `WikiRetrieval`, never by reading raw `wiki_facts.statement` or
`wiki_pages.body_md`. This binds **Stage C (doc-gap)** and **Stage D (KB drafting)** — both
reason over existing wiki content — and Stage E only if it reads wiki context to scope "other
sites." Stage A (drafts from ticket content) and Stage B (mining — the *write* side) do **not**
read wiki content back into a prompt, so they are unaffected.

Two residual gaps documented by Phase 4/5 (carry into stage prompts, do not re-introduce):
`wiki_get_page` still serves human prose that merely *passes* the finite `scan()` corpus, and
facts are not re-scanned at serving time. Stages that surface KB/doc-gap output to a *client*
must therefore keep a human in the loop before publication (§5).

---

## 5. The stages

Each stage is independently gated. **The two existing stages stay gated on
`WikiConfig::autoMineEnabled()`** (so Phase 1 changes no behavior). **Each new stage gets its own
`closeout_stage_*` opt-in, default off**, so enabling the pipeline never starts new AI spend
silently — the same posture as `wiki_auto_mine`. Every AI stage draws from the shared
`WikiBudget` daily pool and records a `wiki_runs` row.

**Graded HITL posture** (matched to stakes, reflecting existing precedent):

| Output kind | Posture | Precedent |
|---|---|---|
| Wiki facts (Stage B) | auto-write, born `unverified` + provenance | shipped (tiered autonomy, §4.3) |
| Drafted resolution (Stage A) | auto-write + `resolution_ai_drafted` review badge | shipped (`GenerateTicketResolution`) |
| Doc-gap (Stage C) | **flag only** on needs-review surface | Phase-5 stale-ticket sweep (flag-only) |
| KB draft (Stage D) | draft page born `unverified`; human promotes; **never** auto-published to portal | wiki draft/addendum UX |
| Follow-up ticket (Stage E) | **suggest only; a human creates the ticket** | Phase-5 flag-only; highest stakes = strictest gate |

### Stage A — Resolution Drafting  *(EXISTS — fold in as-is)*

`TicketResolutionDrafter::draft(Ticket, triggeredBy): ?string` + `GenerateTicketResolution`.
Gates on `hasSubstance()` (a reply note or a completed call summary) and `WikiBudget`; one
`AiClient::completeJson` call with an injection-hardened, secret-forbidding, "ticket text is
untrusted" system prompt; scans output via `WikiRedactor::scan()` (quarantine on hit); appends
budgeted Tactical telemetry. Returns null on no-substance / budget / unsafe / no-resolution.
**No behavioral change** — the orchestrator calls the existing service instead of the observer
dispatching the existing job. Runs first because a drafted resolution is Stage B's raw material.

### Stage B — Knowledge Mining  *(EXISTS — fold in as-is)*

`MineTicketKnowledge`'s pipeline (gather → extract → scan/quarantine → merge → compose), writing
`wiki_facts` + `WikiRunType::MineTicket`, sharing `WikiBudget`, quarantining on `WikiRedactor`
hits, idempotent by content hash, with `ComposeClientOverview` fan-out on fact-changing mines.
**No behavioral change.** Refactor: extract the job's `handle()` body into a callable
`KnowledgeMiningStage` (or a `MiningService`) that both the orchestrator and the retained
`MineTicketKnowledge` job (still used by `wiki:backfill`) call. This is the one true refactor in
Phase 1 and it is behavior-preserving (guarded by a golden test, §7).

### Stage C — Documentation-Gap Detection  *(NEW)*

**Goal.** Keep the wiki *complete*, not just append-only. When a resolution reveals the wiki is
missing or stale on something (a firewall model the `network` page never mentions; a fact past
its staleness window), flag it.

**Behavior.** Deterministic-first: reuse the maintenance loop's staleness/contradiction signals
(wiki spec §7) plus a bounded AI pass comparing the ticket's entities against the client's
existing wiki (**served via `WikiRetrieval` — §4**). Emits **gap flags** (subject, page/section,
one-line rationale) to the client wiki's existing **needs-review** surface (wiki spec §8) and
optionally a private ticket note. **No page is auto-written** — a flag invites a human (or a
future, explicitly-opted-in auto-fill) to add the fact. `WikiRunType::DocGap`.

**§6 gate: REQUIRED** (reads wiki content back into AI). Route all reads through `WikiRetrieval`.

### Stage D — KB / Runbook Drafting  *(NEW — later)*

**Goal.** From **recurring** resolutions/patterns (within a client, and at global scope across
clients), draft tech-facing **runbooks** and client-facing **how-tos**. This *completes* the
wiki's dormant global patterns/runbooks design (§4.6 taxonomy + §5.2's specified-but-deferred
cross-client-pattern extraction).

**Behavior.** Pattern detection first (N similar resolutions on a subject/category in a window)
— cheap, deterministic — *then* an AI draft. Output is a **draft KB page** at the right scope
(client runbook-`deviation` or global `runbook`/`pattern` kind), born `unverified`, surfaced via
the wiki draft/addendum UX for a human to promote. Client-facing how-tos are drafted but
**never** exposed to the portal without explicit human publication (portal exposure is itself a
deferred v1.x item, wiki spec §12). `WikiRunType::KbDraft`.

**§6 gate: REQUIRED** — and cross-client reasoning must use `WikiRetrieval`'s isolation
(global-scope patterns only; never leak one client's facts into another's KB).

### Stage E — Follow-up Ticket Suggestions  *(NEW — later)*

**Goal.** Surface preventive/related work the resolution implies ("same firmware fix at the
client's other sites"; "make the temporary firewall rule permanent").

**Behavior.** An AI pass proposes zero or more **suggestions** (title, rationale, suggested
priority, linked source ticket), rendered on the source ticket. **Suggest-only** — accepting
calls `TicketService` to create the ticket (`TicketSource::Closeout`, a new source value, linked
back). Never auto-creates: ticket creation is an *action*, the highest-stakes output here, so it
gets the strictest gate (Phase-5 flag-only precedent). `WikiRunType::Followup`.

**§6 gate:** reads ticket content; crosses the boundary only if it reads wiki context to scope
related assets/sites — if so, `WikiRetrieval` applies.

---

## 6. Security, budget, idempotency, concurrency — reuse verbatim

Nothing new; the Miner/Drafter controls lifted to the pipeline:

- **Redaction & quarantine.** Wiki-writing stages (B, and C/D when they write) run candidate text
  through `WikiRedactor` (secret-shape corpus + entropy + injection-scaffold + splice-marker;
  classes `credential` / `injection` / `marker`) and quarantine the `wiki_runs` row on a hit —
  unchanged. Stage A already scans its drafted resolution before writing.
- **Budget.** All AI stages share the **one** `WikiBudget` daily pool
  (`tokensUsedToday()` sums *every* `wiki_runs` row for `today()`; `dailyLimitReached()` vs
  `wiki_daily_token_limit`, default 500k), separate from Triage's pool. Per-run caps mirror
  `wiki_max_tokens_per_run`. On exhaustion, stages defer / no-op rather than drop
  (`MineTicketKnowledge` releases 3600s; the drafter returns null) — never silent loss.
  *Note:* keeping one shared pool is deliberate — it preserves the operator's single "total wiki
  AI spend" ceiling. A chatty new stage is bounded by its own `closeout_stage_*` off-by-default
  flag, not by pool-splitting.
- **Idempotency.** Mining's content-hash key (`sha256(ticket_id | resolution)`) is retained;
  terminal states (`Completed`, `Quarantined`) block re-runs, `Failed`/`Running` do not
  (crash-safe re-drive). The orchestration row can key on the same hash so a redundant sweep/
  retry is a no-op.
- **Concurrency.** `WithoutOverlapping("closeout-client-{id}")` per client with
  `expireAfter`/`releaseAfter`, exactly as `MineTicketKnowledge` (a crashed worker must not wedge
  a client forever). Reconciles with the existing per-job locks
  (`wiki-mine-client-{id}`, `ticket-resolution:{id}`) — the orchestrator holds the outer lock and
  calls stage *services* (not re-dispatches jobs), so inner locks don't self-deadlock; the
  retained standalone jobs keep their own locks for the backfill path.
- **System pseudo-user.** AI-authored notes/flags/suggestions attributed to a configurable
  `closeout_system_user_id` (falls back to the triage/wiki system user) so the recursion guard
  and audit trail work.

---

## 7. Human surface & testing

**Human surface** — new stages use the wiki spec **§8.1 "AI addendum" contract** (flat tonal
container, robot "AI note" label matching the existing `AiTriage` note treatment, inline
outline-styled accept/dismiss/edit, never `alert-danger` — this is a system of record):

- Stage A — unchanged (auto-written resolution + "AI-drafted · review" badge, already shipped).
- Stage C — gap flags on the client wiki **needs-review** list, muted `badge bg-secondary` at
  rest (§8.1 rule 4, "never a nag"), optionally a private ticket note.
- Stage D — draft KB page in the wiki draft surface; promote-to-publish is human.
- Stage E — follow-up suggestions on the source ticket; accept → real ticket.

This dovetails with psa-s5bf (wiki UX) and the deferred **ticket-detail sidebar** (wiki spec §8):
once that sidebar exists, closeout outputs are its most natural content. The pipeline does not
depend on the sidebar.

**Testing** (mirrors wiki spec §11 + triage tests):

- **Behavior-preservation golden test (Phase 1's safety net):** with only the two existing
  stages enabled, the pipeline reproduces today's `GenerateTicketResolution` + `MineTicketKnowledge`
  outcomes fact-for-fact and resolution-for-resolution.
- **Unit (no AI):** per-stage gating (each `closeout_stage_*` on/off), the observer consolidation
  (one dispatch, both legacy behaviors reproduced incl. the resolution-edit re-mine and the
  no-loop invariant), idempotency-hash on retry, merge-closure/system-user skips, budget-pool
  sharing, redaction scan on any new AI output.
- **§6 gate test:** Stage C/D reads go through `WikiRetrieval` (assert delimited records, not raw
  statements); a `client_id=null` read returns zero client-scoped facts.
- **Graded HITL tests:** Stage C writes a flag but no page; Stage E writes a suggestion but no
  ticket until a human accepts; Stage A still auto-writes with the badge.
- **AI-path (mocked `AiClient`):** each new stage's happy path + JSON-parse hardening + one retry
  + `failed`/`quarantined` run recorded.

---

## 8. Configuration

New thin `app/Support/CloseoutConfig.php` (mirrors `WikiConfig`/`TriageConfig`, all static over
`Setting::getValue()`), delegating the master gate to the wiki so existing behavior is preserved:

| Key | Default | Purpose |
|-----|---------|---------|
| *(master)* | — | delegates to `WikiConfig::autoMineEnabled()` — pipeline no-ops when off; gates Stages A & B (unchanged) |
| `closeout_stage_doc_gaps` | off | Stage C |
| `closeout_stage_kb_draft` | off | Stage D |
| `closeout_stage_followups` | off | Stage E |
| `closeout_model` | null | model override; falls back to `WikiConfig::model()` → `AiConfig::model()` |
| `closeout_system_user_id` | null | AI pseudo-user; falls back to triage/wiki system user |
| `closeout_sweep_enabled` | off | `closeout:sweep` cron |

`stageEnabled(string $stage): bool` mirrors `TriageConfig::stageEnabled()` — Stages A/B return
`WikiConfig::autoMineEnabled()`; new stages return their `closeout_stage_*` flag (default off).

**Backwards-compat invariant:** with all `closeout_stage_*` off, enabling closeout reproduces
**exactly today's behavior** (draft-then-mine on resolution). The refactor is observable-
equivalent until an operator opts a new stage in.

---

## 9. Implementation — files

### Create
- `app/Services/Closeout/CloseoutPipeline.php` — orchestrator (clone of `TriagePipeline` shape).
- `app/Services/Closeout/Stages/KnowledgeMiningStage.php` (B, extracted from
  `MineTicketKnowledge::handle()`), `DocGapStage.php` (C), `KbDraftStage.php` (D),
  `FollowupStage.php` (E). *(Stage A stays as `TicketResolutionDrafter`; the orchestrator calls it.)*
- `app/Services/Closeout/CloseoutPrompts.php` — new-stage prompt templates (cf. `Triage/Prompts.php`).
- `app/Support/CloseoutConfig.php`.
- `app/Jobs/RunCloseoutPipeline.php` (cf. `RunTriagePipeline`).
- `app/Console/Commands/CloseoutSweepCommand.php` (`closeout:sweep`).
- `WikiRunType` cases: `Closeout` (orchestration), `DocGap`, `KbDraft`, `Followup`.
- `TicketSource::Closeout` (Stage E), when it lands.

### Modify
- `app/Observers/TicketObserver.php` — replace the two closeout branches (`:75-99`) with one
  `RunCloseoutPipeline::dispatch()`.
- `app/Jobs/MineTicketKnowledge.php` — reduce `handle()` to a thin call into
  `KnowledgeMiningStage` (keep the job for `wiki:backfill`).
- Ticket detail Blade — "Run closeout" button; render Stage C/D/E affordances (§7).
- `routes/console.php` — schedule `closeout:sweep` (gated).
- Settings/Integrations UI — the `CloseoutConfig` toggles with per-stage cost hints.
- `docs/INSTALL.md` — new settings keys + scheduled command (repo living-docs rule).

### Reuse unchanged (do not fork)
- `WikiTicketContext` (gather), `WikiRedactor` (scan), `WikiBudget` (pool), `wiki_runs` +
  `WikiRunStatus`, `WikiRetrieval` (the §6 read boundary for Stages C/D), `WikiFactService`,
  `WikiComposerService`, `WithoutOverlapping`.

---

## 10. Phasing (see companion plan)

| Phase | Delivers | New AI output? | §6 read-back? |
|-------|----------|----------------|---------------|
| 1 | Orchestrator + `CloseoutConfig` + `WikiRunType::Closeout`; Stages A & B folded in behind one dispatch; observer consolidated. **Behavior-preserving.** | none | no |
| 2 | Stage C Doc-Gap Detection (reads via `WikiRetrieval`). | yes | **yes — route via WikiRetrieval** |
| 3 | Stage D KB/Runbook drafting (completes dormant global-pattern design). | yes | yes |
| 4 | Stage E Follow-up suggestions (`TicketSource::Closeout`, suggest-only). | yes | maybe |
| 5 | `closeout:sweep` cron, pipeline-level observability/UX polish (ties to psa-s5bf + the sidebar). | — | — |

Phase 1 is a pure, behavior-preserving consolidation — low risk, high leverage (it is the seam).
Because the §6 boundary already exists on `main`, Stages C/D are **not** blocked on new hardening
— only on *routing their reads through `WikiRetrieval`*, which is a stage-implementation
requirement, not a prerequisite project.

---

## 11. Scope boundaries

- **Phase 1 changes no behavior** — it is structure only. Any observable difference in drafting
  or mining output is a bug, caught by the golden test (§7).
- **Not an approval queue for the wiki.** Tiered autonomy is unchanged; facts still land
  `unverified` and self-verify through the loop. The new HITL surfaces are for *doc-gaps, KB, and
  follow-up tickets*, which are higher-stakes than a single fact.
- **No new auto-actions.** Never auto-creates tickets, never auto-publishes client-facing KB,
  never auto-fills wiki pages, never overwrites a present resolution. Existing auto-writes
  (unverified facts; AI-drafted resolution with review badge) are retained as-is.
- **§6 reads go through `WikiRetrieval`** — never raw `wiki_facts.statement` / `wiki_pages.body_md`.
- **No new infrastructure** — same queue, scheduler, MariaDB, `AiClient`, `wiki_runs`. Degrades
  cleanly with AI or wiki disabled.
- **Portal exposure of client-facing how-tos stays deferred** (wiki spec §12) — Stage D drafts,
  publication is out of scope here.
- Stages D and E are **later** — designed here, scheduled when prioritized.
