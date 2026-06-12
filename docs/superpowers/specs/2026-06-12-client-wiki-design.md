# Client Wiki — AI-Maintained Environment Documentation

**Date:** 2026-06-12
**Status:** Approved design, pending implementation planning
**Scope:** New module (working name: Wiki) for Sound PSA

## 1. Purpose

Mine the ticket lifecycle — AI triage output, resolutions, notes, call transcripts — plus
integration sync data into wiki-style documentation of client environments that maintains
itself. The wiki serves two audiences:

1. **AI consumers (primary).** Triage, the Assistant, and MCP clients read the wiki as
   context. Every resolved ticket makes future triage smarter — a compounding loop.
2. **Staff (strong second).** Techs browse and search per-client environment pages and
   cross-client knowledge instead of re-discovering facts ticket by ticket.

Inspired by the claude-obsidian pattern (ingest → cross-linked wiki pages → lint/maintain →
cheap tiered retrieval). We adopt the concept, not the implementation: pages live in MariaDB
and render in the PSA UI. Obsidian is supported only as an optional one-way export target.

### Goals

- Client environment pages that stay current without anyone curating them.
- Cross-client knowledge (vendor quirks, recurring patterns, standard runbooks) mined from
  resolutions and reusable on the next affected client.
- Standard runbooks with per-client deviation pages — cascade semantics, most specific wins.
- Tiered trust: machine-verified facts and AI inferences are visibly different things.
- Feed wiki context into triage/Assistant/MCP with token costs that stay flat as the wiki grows.
- Deployable by any MSP running PSA open-source: no new infrastructure, provider-agnostic AI.

### Non-goals (v1)

- No client portal exposure. Staff only.
- No credentials/secrets storage. The pipeline actively redacts secret values (§5.2). A future
  encrypted credentials module may link from pages; v1 pages may only say *where* a credential
  lives, never the value.
- No vector database or embeddings. Retrieval is MariaDB FULLTEXT + structured queries.
- No two-way Obsidian/vault sync. Export is one-way; import does not exist.
- No human approval gate on AI writes. Tiered autonomy (§4.3) replaces a review queue.

## 2. Decisions made during design

| Question | Decision |
|----------|----------|
| Primary consumer | Both AI and humans, AI-first. The triage feedback loop is the core value. |
| Knowledge scope | Three layers: per-client environment facts, global knowledge base (vendors, patterns, standard runbooks), per-client runbook deviations with cascading override by specificity. |
| Trust model | Tiered autonomy. AI writes directly; every claim carries provenance and status. Sync-derived facts are born verified; text-inferred facts are born unverified. No approval queue. |
| Secrets | Hard redaction in the pipeline (deterministic pre-filter, prompt rule, post-write scanner with quarantine). Never store secret values. |
| Storage | DB-native (MariaDB), Blade-rendered. Obsidian-compatible markdown via one-way `wiki:export`. |
| Human edits vs AI | Humans own their text; AI never rewrites it. AI challenges human claims via dated, source-linked addenda and the dispute mechanism (§4.4). |

## 3. Architecture overview

```
                 ┌─────────────────────────────────────────────┐
   sources       │                 EXTRACTION                  │       storage
                 │                                             │
 integration ───►│ deterministic sync-fact writer (no AI)      │──► wiki_facts (verified)
 sync deltas     │                                             │
                 │ ticket-close mining job:                    │
 ticket close ──►│  gather → redact → extract → merge → compose│──► wiki_facts (unverified)
 (resolution,    │  (AiClient, budgeted, wiki_runs ledger)     │    wiki_pages + revisions
 notes, calls,   └─────────────────────────────────────────────┘    wiki_links
 triage runs)                                                            │
                 ┌─────────────────────────────────────────────┐         │
   consumers     │                 RETRIEVAL                   │◄────────┘
                 │ tier 1: client overview (hot summary,       │
 triage ────────►│         always injected)                    │
 assistant ─────►│ tier 2: wiki_list_pages (index)             │
 MCP clients ───►│ tier 3: wiki_search, wiki_get_page          │
 staff UI ──────►│         (FULLTEXT + cascade merge)          │
                 └─────────────────────────────────────────────┘
                 ┌─────────────────────────────────────────────┐
   maintenance   │ nightly: staleness sweep, contradiction     │
                 │ sweep, link lint, hot-summary regen         │
                 └─────────────────────────────────────────────┘
```

The loop closes through normal work: triage reads wiki facts → ticket gets worked → close
mining reaffirms or contradicts those facts. Ticket flow is the verification engine.

## 4. Data model

Facts are atoms; pages are views. AI-managed page sections are composed from facts. Human
prose lives in human-owned sections that AI annotates but never rewrites. Verification,
contradiction detection, staleness, and provenance all operate on facts.

Status columns are strings backed by PHP enums, following codebase convention
(cf. `TicketStatus`).

### 4.1 Tables

**`wiki_pages`**

| Column | Type | Notes |
|--------|------|-------|
| id | bigint pk | |
| scope | string:10 | `global` \| `client` |
| client_id | FK clients, nullable | null iff scope=global |
| slug | string:255 | path-style, e.g. `runbooks/user-onboarding` |
| title | string:255 | |
| kind | string:20 | `overview`, `environment`, `runbook`, `deviation`, `vendor`, `pattern`, `note` |
| parent_page_id | FK wiki_pages, nullable | deviation → the global page it overrides |
| body_md | longtext | markdown with `[[wikilink]]` syntax |
| meta | json, nullable | composition metadata (section anchors, etc.) |
| is_archived | bool default false | |
| created_by_type | string:10 | `ai` \| `human` \| `system` |
| timestamps | | |

Unique `(scope, client_id, slug)`. Index `(client_id, kind)`. FULLTEXT `(title, body_md)`.

**`wiki_facts`**

| Column | Type | Notes |
|--------|------|-------|
| id | bigint pk | |
| scope / client_id | as pages | denormalized for retrieval queries |
| page_id | FK wiki_pages | page the fact renders into |
| section_anchor | string:100 | which `##` section |
| subject_key | string:255, indexed | stable identity for dedup/contradiction, e.g. `asset:DC01:ram`, `client:acme:onboarding-deviation` |
| statement | text | the claim, one atomic fact |
| status | string:15 | `unverified`, `confirmed`, `disputed`, `retired` |
| pinned | bool default false | human-asserted; AI may cite, never auto-supersede |
| volatility | string:10 | `durable` \| `volatile` — extractor-assigned; volatile facts are subject to staleness flagging |
| source_type | string:10 | `sync` \| `ticket` \| `triage` \| `human` |
| source_refs | json | array of `{type, id}` — tickets, triage_runs, sync identifiers |
| confidence | decimal(3,2), nullable | extractor confidence; null for sync/human |
| last_affirmed_at | datetime | bumped on reaffirmation |
| confirmed_by | FK users, nullable | |
| disputed_with_fact_id | FK wiki_facts, nullable | pairs the two sides of a dispute |
| superseded_by_fact_id | FK wiki_facts, nullable | set on retire-by-supersession |
| dismissed_evidence | json, nullable | source refs a human dismissed; AI must not re-raise a challenge from only these |
| timestamps | | |

Index `(client_id, status)`, `(page_id, section_anchor)`. FULLTEXT `(statement)`.

**`wiki_page_revisions`** — id, page_id FK, body_md, meta, author_type (`ai`/`human`/`system`),
author_id nullable, change_summary string, source_refs json nullable, created_at. Every page
write creates one. Diffable history in the UI.

**`wiki_links`** — id, from_page_id FK, to_page_id FK nullable (null = dead link),
target_slug string, anchor_text nullable, created_at. Unique `(from_page_id, target_slug)`.
Rebuilt on each page save; powers backlinks, orphan and dead-link lint.

**`wiki_runs`** — observability ledger mirroring `triage_runs`: id, run_type (`mine_ticket`,
`sync_facts`, `maintain`, `backfill`), subject_type/subject_id, status (`pending`, `running`,
`completed`, `failed`, `quarantined`), stages_completed json, stage_results json, errors json,
ai_tokens_used json, triggered_by, timestamps.

### 4.2 Fact lifecycle

```
            sync/human source            ticket/triage source
                   │                             │
                   ▼                             ▼
               confirmed ◄── human confirm ── unverified
                   │ ▲                           │
                   │ └── human resolves ──┐      │ contradicting fact arrives
                   │                      │      ▼
   human correct/retire,                disputed (paired via disputed_with_fact_id)
   or supersession                        │
                   ▼                      │ human resolves: confirm one,
                retired ◄─────────────────┘ retire the other
```

- **Born verified:** facts written by the deterministic sync writer or asserted by a human.
- **Born unverified:** facts inferred from ticket/triage text. Visible badge until confirmed.
- **Reaffirmation:** an extraction producing an existing `subject_key` with a consistent
  statement bumps `last_affirmed_at` (no duplicate row).
- **Contradiction:** an extraction producing an existing `subject_key` with an inconsistent
  statement creates the new fact and pairs both as `disputed`. Neither is destroyed.
- **Correction:** a human edit creates a new `confirmed` + `pinned` fact (source `human`) and
  retires the old one via `superseded_by_fact_id`.
- **Staleness** is a computed flag, not a status: `volatile` facts whose `last_affirmed_at`
  exceeds the configured window get surfaced in maintenance and on page badges. Sync-backed
  facts never go stale; they refresh at the source.

### 4.3 Tiered autonomy

AI writes pages directly — no approval queue (a one-person MSP will never keep up with one,
and an ignored queue means no documentation at all). Trust is carried per-claim instead:

- Every fact renders with a status badge and source links (the ticket, triage run, or sync
  that produced it).
- `unverified` and `disputed` counts surface on wiki indexes as hygiene affordances, never
  blocking gates. Confirm / correct / retire are one-click actions on the page.
- Retrieval (§6) annotates every fact it serves with status, so AI consumers weigh claims
  rather than swallowing them.

### 4.4 Human/AI coexistence

- Humans own their text. AI never rewrites or deletes human-authored content.
- Human-authored content is also fact-indexed: when a human writes or edits wiki content, a
  lightweight extraction derives human-sourced facts from it (born `confirmed`). The prose
  remains the canonical rendering; the derived facts are the claim index that lets the
  dispute mechanism cover human claims uniformly.
- When AI evidence contradicts a human note, it attaches a dated, source-linked **addendum**
  beneath the note and pairs the claims as `disputed`. The human resolves: **accept** (AI
  version becomes current; the human note is marked superseded, preserved in history),
  **dismiss** (note becomes `pinned`; the dismissed evidence refs are recorded and may not be
  re-raised alone), or **edit**.
- Reader-safety escalation: when the contradicting source is structured sync (machine ground
  truth — RMM reports 32 GB, note says 16 GB), the stale human claim renders dimmed/struck
  with "superseded by sync, pending review." Words are never destroyed; the page just stops
  actively misleading while it waits for resolution.

### 4.5 Cascade semantics (standard runbooks + client deviations)

- Global runbooks live at `global:runbooks/<procedure>`.
- A client deviation is a `kind=deviation` page in client scope with `parent_page_id` set to
  the global page. It contains **only the delta** ("follows standard onboarding except…").
- Merged view, most specific wins: rendering or retrieving a runbook in a client context
  returns the global content with the client's deviations applied inline and visibly marked.
  Requesting a client slug that doesn't exist falls back to the global page.
- Wikilink resolution is scoped the same way: client pages resolve `[[slug]]` within client
  scope first, then global.

### 4.6 Taxonomy (seeded skeleton)

- **Global:** `vendors/<vendor>`, `products/<product>`, `runbooks/<procedure>`,
  `patterns/<recurring-issue>`.
- **Per-client:** `overview` (doubles as the hot summary, §6), `network`, `infrastructure`,
  `m365`, `security`, `backup`, `applications`, `known-issues`, `runbooks/<deviation>`,
  `history` (decision log), `notes` (human-owned; existing `clients.site_notes` content is
  imported here as seed material, and the triage context injection point moves from
  `site_notes` to the wiki overview).

The skeleton is seeded per client on first activation; the AI may create additional pages
within these kinds but does not invent new kinds.

## 5. Extraction pipeline

### 5.1 Triggers, cheapest first

1. **Integration sync deltas (no AI).** Asset/CIPP/backup sync changes upsert structured
   facts directly — deterministic, free, born `confirmed`. This alone keeps environment pages
   current. Implemented as a deterministic writer hooked into existing sync completion.
2. **Ticket close (the gold path).** Closing a ticket with a resolution enqueues
   `MineTicketKnowledge` (queued job; pattern mirrors `RunTriagePipeline`).
3. **Nightly maintenance** (§7).
4. **`wiki:backfill`** artisan command — mines historical closed tickets in configurable
   batches under the daily budget, in chronological order (oldest first) so the freshest
   knowledge lands last and wins reaffirmations and disputes.
   Supports `--dry-run` (reports what would be mined/written, writes nothing).

### 5.2 Mining job stages

Each run is recorded in `wiki_runs` (status, per-stage results, errors, tokens).

1. **Gather.** Bounded context: ticket, notes, resolution, call transcripts, triage run
   stage results. Truncation limits mirror triage's `ContextBuilder`.
2. **Redact — before the AI sees anything.** Defense in depth, in three layers:
   - *Pre-filter (deterministic):* regex corpus for secret shapes — `password=`/`pwd:` forms,
     API key/token patterns, PEM blocks, connection strings — plus a contextual high-entropy
     string detector. Matches are replaced with `[REDACTED:<class>]` before prompt assembly.
   - *Prompt rule:* the extraction prompt forbids emitting secret values even if present.
   - *Post-write scanner:* the same corpus + entropy scan runs over composed output. Any hit
     **quarantines the run** (`status=quarantined`, nothing published, surfaced in health).
   Pages may state where a credential lives ("M365 GA creds in Keeper"), never the value.
3. **Extract.** One `AiClient::completeJson` call returns candidate facts — each with
   `subject_key`, statement, target page/section, volatility, confidence — plus optional
   runbook-deviation, cross-client pattern, or known-issue candidates. The prompt instructs a
   documentation-worthiness filter: most tickets yield zero facts, and that is the correct
   output for routine work. Candidates below a confidence floor are discarded.
4. **Merge.** Per candidate, by `subject_key`: subject keys are normalized deterministically
   before matching (lowercased, canonical entity resolution against known clients/assets) so
   extractor wording drift cannot defeat dedup. Consistent with an existing fact → reaffirm
   (bump `last_affirmed_at`); inconsistent → create + pair as `disputed` (§4.2); new → insert
   `unverified`. Pinned facts are never auto-superseded; challenges to them go through the
   addendum path (§4.4) and respect `dismissed_evidence`.
5. **Compose.** Recompose only the affected page sections. Composition is template-first:
   fact-backed sections render deterministically (structured lists/tables from their facts,
   no AI call). A small `AiClient` prose-glue call is used only for narrative sections
   (known-issues, history, patterns) when mining produces them. Write the revision, rebuild
   `wiki_links`, mark the client's hot summary stale. Compose + revision write is
   transactional — a page is never left half-written.

### 5.3 Budgets and idempotency

- Settings-gated budgets, separate pools from triage so mining can never starve it:
  `wiki_max_tokens_per_run`, `wiki_daily_token_limit`.
- On budget exhaustion, jobs release back to the queue with a delay rather than failing.
- Mining is keyed by ticket + content hash: re-running a ticket (retry, backfill overlap)
  reaffirms/upserts instead of duplicating.

## 6. Retrieval and the feedback loop

Three tiers, so token cost stays flat as the wiki grows:

1. **Hot summary (always injected, zero retrieval cost).** Each client's `overview` page is
   AI-maintained under a token budget (~500–800 tokens): environment one-liner, stack, active
   quirks, open disputes. Triage's `ContextBuilder` injects it where `site_notes` is injected
   today; the Assistant receives it for any client-scoped conversation.
2. **Index.** `wiki_list_pages(client_id?)` — titles, kinds, freshness. Cheap orientation.
3. **Deep read.**
   - `wiki_search(query, client_id?)` — MariaDB FULLTEXT over facts and pages, client scope
     plus global.
   - `wiki_get_page(slug, client_id?)` — returns the merged cascade view (§4.5).

**Tool surface:** all three tools are added to `AssistantToolExecutor`, which means the
Assistant chat, the MCP server (and through it Teams), and the technical-triage agentic loop
get them in one move. Results annotate every fact with status (`verified` / `unverified` /
`disputed` — disputed facts are served with both sides), so AI consumers can weigh claims.

**The loop closes itself:** triage reads wiki facts → the ticket gets worked → close mining
reaffirms or contradicts what was read. Day-to-day ticket flow is the wiki's verification
engine; no separate curation labor exists or is needed.

## 7. Maintenance loop

One nightly queued job (`wiki:maintain`, budgeted, recorded in `wiki_runs`):

- **Staleness sweep** — flag `volatile` facts past `wiki_staleness_days_volatile` without
  reaffirmation. Sync-backed facts are exempt.
- **Contradiction sweep** — cross-check facts that arrived independently on the same
  `subject_key`; file disputes the merge stage didn't see (e.g., sync vs ticket-derived).
- **Link lint** — dead wikilinks, orphan pages (no backlinks), archived-page references.
- **Hot summary regeneration** — only for clients marked stale since the last run.

Health surfaces as counters (unverified / disputed / stale) on wiki indexes and page badges.
Affordances, not gates.

## 8. Human surface

Blade + Bootstrap, server-rendered markdown, no build step. Staff auth only (existing
middleware); no new roles — PSA's users are generalists.

- **`/wiki`** — global index: search, pages by kind, health counters.
- **`/clients/{client}/wiki`** — client index: skeleton pages, health counters, needs-review
  list (unverified/disputed/stale).
- **Page view** — rendered markdown with scoped wikilink resolution; backlinks panel; per-fact
  provenance badges linking to sources (ticket/run/sync); addendum blocks under disputed
  notes; inline one-click confirm / correct / retire; deviation pages render with their
  global parent merged and deltas marked.
- **History** — revision list, diff view, AI/human authorship attributed.
- **Edit** — markdown editor for human-owned content; humans may create pages of any kind.
- **Search** — global + client scope, fact- and page-level results.

Deferred to v1.x: ticket-detail sidebar showing the client overview and facts matching the
ticket (high value, but separable).

## 9. Configuration and open-source posture

Settings follow the established `Setting::settingOrConfig()` pattern via a new
`app/Support/WikiConfig.php` (cf. `TriageConfig`):

| Key | Default | Purpose |
|-----|---------|---------|
| `wiki_enabled` | off | master switch; everything no-ops when off |
| `wiki_auto_mine` | on (when enabled) | mine on ticket close |
| `wiki_model` | null | model override; falls back to `AiConfig::model()` |
| `wiki_max_tokens_per_run` | 50k | per mining run |
| `wiki_daily_token_limit` | 500k | all wiki AI usage, separate from triage's pool |
| `wiki_staleness_days_volatile` | 90 | staleness window for volatile facts |
| `wiki_maintenance_enabled` | on (when enabled) | nightly job |
| `wiki_backfill_batch_size` | 25 | tickets per backfill batch |

- **Provider-agnostic** via existing `AiClient` (Anthropic/OpenAI). Mining and composition
  are single-shot JSON calls and work identically on both providers; the only tool-dependent
  surface is consumption by the triage/Assistant agentic loop, which carries the provider
  constraints it already has today.
- **Zero new infrastructure:** MariaDB FULLTEXT, existing queue and scheduler. No vector DB,
  no desktop app, no file sync, nothing beyond the stack an OSS adopter already deploys.
- **`wiki:export`** — one-way Obsidian-compatible vault dump: folders by scope/kind,
  frontmatter carrying provenance summary, `[[wikilinks]]` intact. Doubles as the plain-text
  egress/backup story (data ownership for OSS users).
- Single-tenant-per-deployment, consistent with PSA's model. Skeleton and prompts ship as
  sane defaults; per-deployment prompt customization is a v1.x candidate.

## 10. Error handling

- **Observability:** every run in `wiki_runs` with per-stage results, errors, token usage —
  same operational affordances as `triage_runs`.
- **Transactional writes:** compose + revision commit together or not at all.
- **Quarantine:** redaction scanner hits mark the run `quarantined`; nothing publishes;
  surfaced in health counters.
- **AI failures:** JSON parse hardening as in `AiClient::completeJson` (fence stripping),
  one retry, then `failed` run with errors recorded. Queue-level retries with backoff.
- **Budget exhaustion:** jobs defer (release with delay), never silently drop work.
- **Sync-fact writer** is deterministic upserts — no AI failure modes on the always-on path.

## 11. Testing strategy

- **Unit (no AI):** redaction corpus (secret patterns, high-entropy strings, near-misses);
  cascade merge resolution; subject-key dedup; contradiction pairing; staleness computation;
  dismissed-evidence suppression.
- **Pipeline (fake `AiClient`, canned JSON fixtures):** mine → merge → compose end-to-end;
  idempotent re-runs (same ticket twice → no duplicates); dispute creation; pinned-fact
  protection; quarantine path; budget deferral.
- **Golden files** for composed page output.
- **Feature/HTTP:** routes, permissions, fact actions (confirm/correct/retire), revision
  diffs, export command output shape, settings gating (everything off when `wiki_enabled`
  is off).
- **Backfill rehearsal:** `--dry-run` over seeded demo data.

## 12. Phasing

| Phase | Delivers | Value when it lands |
|-------|----------|---------------------|
| 1 | Schema, models, manual wiki: pages CRUD, render, links, revisions, search, skeleton seeding, `site_notes` import | A usable hand-edited wiki |
| 2 | Deterministic sync-fact writer + template composition | Environment pages that update themselves, no AI cost |
| 3 | Ticket-close mining: redaction, extraction, merge, disputes, `wiki_runs`, quarantine | The learning loop begins |
| 4 | Retrieval tools + triage/Assistant/MCP integration, hot-summary injection | The compounding payoff: triage gets smarter |
| 5 | Maintenance loop, health surfacing, verification UX polish, `wiki:export`, `wiki:backfill` | Self-maintaining at steady state, populated history |

v1.x candidates (explicitly out of v1): ticket sidebar, auto-promote facts after K independent
reaffirmations, vault import / two-way sync, embeddings rerank, encrypted credentials module,
portal exposure, per-deployment prompt customization.

## 13. Risks and mitigations

- **Prompt injection via ticket content.** Ticket text is untrusted input. Mitigations:
  extraction output is schema-validated structured JSON; facts born `unverified`; redaction
  scanner on output; wiki content is served to AI consumers as data-with-status, not
  instructions — the same posture triage already takes toward ticket bodies.
- **Hallucinated facts compounding through the loop.** Tiered autonomy: unverified badges,
  per-claim provenance, dispute mechanics, confidence floor at extraction, and the
  reaffirm/contradict cycle from real ticket flow. Disputed facts are always served two-sided.
- **Token cost runaway.** Per-run caps, daily ceiling separate from triage, deferral on
  exhaustion, batch-bounded backfill, documentation-worthiness filter (most tickets produce
  zero facts), compose only affected sections, hot summary regenerated only when stale.
- **Wiki noise/bloat.** Subject-key dedup, atomic-fact granularity in the extraction prompt,
  maintenance lint (orphans, staleness), archive rather than delete.
- **FULLTEXT relevance limits.** Acceptable for v1 scale (facts are short, scoped per client);
  embeddings rerank is the designated v1.x upgrade path if needed.
- **Open-source deployments without AI keys.** `wiki_enabled` off by default; with AI off,
  Phase 1+2 functionality (manual wiki + sync facts) still works fully — the module degrades
  gracefully to a structured documentation tool.
