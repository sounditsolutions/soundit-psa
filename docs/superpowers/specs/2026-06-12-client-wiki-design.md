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
| is_archived | bool default false | deliberate non-`SoftDeletes`: archived pages stay queryable and linkable (history, backlinks), they are only excluded from indexes/search by default |
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
| disputed_with_fact_id | FK wiki_facts, nullable, indexed | pairs the two sides of a dispute |
| superseded_by_fact_id | FK wiki_facts, nullable | set on retire-by-supersession |
| dismissed_evidence | json, nullable | source refs a human dismissed; AI must not re-raise a challenge from only these |
| timestamps | | |

Index `(client_id, status)`, `(page_id, section_anchor)`, `(client_id, subject_key)`. FULLTEXT
`(statement)`.

**Merge concurrency:** dedup-by-subject_key cannot be a unique constraint (disputes require two
rows for one subject_key), so the merge stage enforces it transactionally: within one DB
transaction it takes `SELECT … FOR UPDATE` on the existing rows for `(client_id, subject_key)`
and then reaffirms / disputes / inserts. Two concurrent mining jobs extracting the same subject
serialize on that lock instead of double-inserting.

**`wiki_page_revisions`** — id, page_id FK, body_md, meta, author_type (`ai`/`human`/`system`),
author_id nullable, change_summary string, source_refs json nullable, created_at. Every page
write creates one. Diffable history in the UI.

**`wiki_links`** — id, from_page_id FK, to_page_id FK nullable (null = dead link),
target_slug string, anchor_text nullable, created_at. Unique `(from_page_id, target_slug)`.
Rebuilt on each page save; powers backlinks, orphan and dead-link lint.

**`wiki_runs`** — observability ledger mirroring `triage_runs`: id, run_type (`mine_ticket`,
`sync_facts`, `maintain`, `backfill`), subject_type/subject_id, **source_content_hash**
(string:64, nullable; unique `(subject_type, subject_id, source_content_hash)` — the
idempotency key from §5.3, enforced at the DB layer, not just in PHP), status (`pending`,
`running`, `completed`, `failed`, `quarantined`), stages_completed json, stage_results json,
errors json, ai_tokens_used json, triggered_by, timestamps.

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
  dispute mechanism cover human claims uniformly. This extraction path runs the same
  three-layer redaction cycle as mining (§5.2) — a credential pasted into a human note is
  never stored as a fact, even a pinned one.
- "AI never rewrites human content" is a code-level guard in the compose stage (human-authored
  sections are skipped; only addenda may be attached), not a prompt instruction.
- When AI evidence contradicts a human note, it attaches a dated, source-linked **addendum**
  beneath the note and pairs the claims as `disputed`. The human resolves: **accept** (AI
  version becomes current; the human note is marked superseded, preserved in history),
  **dismiss** (note becomes `pinned`; the dismissed evidence refs are recorded and may not be
  re-raised alone), or **edit**. Dismissal semantics are a subset check: a new challenge is
  suppressed only if its evidence refs are a subset of `dismissed_evidence`; any genuinely new
  evidence may re-raise.
- Reader-safety escalation: when the contradicting source is structured sync (machine ground
  truth — RMM reports 32 GB, note says 16 GB), the stale human claim renders dimmed/struck
  with "superseded by sync, pending review." Words are never destroyed; the page just stops
  actively misleading while it waits for resolution.

### 4.5 Cascade semantics (standard runbooks + client deviations)

- Global runbooks live at `global:runbooks/<procedure>`.
- A client deviation is a `kind=deviation` page in client scope with `parent_page_id` set to
  the global page. It contains **only the delta** ("follows standard onboarding except…").
  Depth is exactly one: a model-layer validator enforces that `parent_page_id` always points
  to a `scope=global` page that itself has no parent — no deviation chains.
- Merged view, most specific wins, **section-level granularity**: the unit of override is the
  `##` section, joined by section anchor. A deviation section whose anchor matches a global
  section replaces that section wholesale (rendered with a "client deviation" marker); a
  deviation section with a new anchor is appended under a marked deviations area. Global
  sections without a matching deviation render unchanged. Requesting a client slug that
  doesn't exist falls back to the global page.
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
   **Known gaps, documented for operators:** conversationally phrased low-entropy passwords
   ("set the WiFi to Summer2026!"), secrets dictated character-by-character in call
   transcripts, and base32 TOTP seeds may evade pattern and entropy detection. The corpus
   includes context-aware patterns (`password … is`, `credentials are`) to narrow this, and
   the limits are stated in the settings UI so MSPs mining call transcripts know them.
3. **Extract.** One `AiClient::completeJson` call returns candidate facts — each with
   `subject_key`, statement, target page/section, volatility, confidence — plus optional
   runbook-deviation, cross-client pattern, or known-issue candidates. The prompt instructs a
   documentation-worthiness filter: most tickets yield zero facts, and that is the correct
   output for routine work. Candidates below a confidence floor are discarded.
4. **Merge.** Before anything is stored, every candidate `statement` passes two deterministic
   write-time filters: the redaction corpus + entropy scan (per-statement, not only on
   composed pages), and an **injection-scaffolding filter** (patterns like "ignore previous
   instructions", "system:", role-play markers) — either hit quarantines the run. Ticket text
   is untrusted input, and the wiki is a persistence layer that would otherwise let one ticket
   poison every future triage run for that client; this filter is a hard control, not prompt
   guidance. Then, per candidate, by `subject_key`: subject keys are normalized
   deterministically before matching (lowercased, canonical entity resolution against known
   clients/assets) so extractor wording drift cannot defeat dedup. Consistent with an existing
   fact → reaffirm
   (bump `last_affirmed_at`); inconsistent → create + pair as `disputed` (§4.2); new → insert
   `unverified`. Pinned facts are never auto-superseded; challenges to them go through the
   addendum path (§4.4) and respect `dismissed_evidence`.
5. **Compose.** Recompose only the affected page sections. Composition is template-first:
   fact-backed sections render deterministically (structured lists/tables from their facts,
   no AI call). A small `AiClient` prose-glue call is used only for narrative sections
   (known-issues, history, patterns) when mining produces them. Write the revision and rebuild
   `wiki_links` synchronously in the same DB transaction as the page write (no dispatched job —
   backlink counts are never stale between saves), and mark the client's hot summary stale.
   Compose + revision write is transactional — a page is never left half-written.

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
   quirks, open disputes. Because it is auto-injected into **every** triage run, its
   composition is trust-tiered: guidance-bearing sections (active quirks, "how we work with
   this client") are composed only from `confirmed` and sync-sourced facts; `unverified`
   facts may appear only as clearly-marked informational bullets. Triage's `ContextBuilder`
   injects it where `site_notes` is injected today; the Assistant receives it for any
   client-scoped conversation. **Transition rules (no regression window):** wiki disabled →
   `site_notes` injected exactly as today; wiki enabled but the client's overview is empty →
   `site_notes` still injected as fallback; overview exists → it replaces `site_notes` for
   that client. The `clients.site_notes` column is retained and deprecated; dropping it is a
   follow-up decision after Phase 5, not part of this feature.
2. **Index.** `wiki_list_pages(client_id?)` — titles, kinds, freshness. Cheap orientation.
3. **Deep read.**
   - `wiki_search(query, client_id?)` — MariaDB FULLTEXT over facts and pages, client scope
     plus global.
   - `wiki_get_page(slug, client_id?)` — returns the merged cascade view (§4.5).

**Tool surface:** all three tools are added to `AssistantToolExecutor`, which means the
Assistant chat, the MCP server (and through it Teams), and the technical-triage agentic loop
get them in one move. Two hard rules on this surface:

- **Structured serving.** Facts are serialized to AI consumers as delimited structured
  records — e.g. `WIKI_FACT | subject: asset:DC01:ram | status: unverified | claim: "…"` —
  never as prose woven into the prompt. The syntactic boundary between fact content (data)
  and the surrounding prompt (instructions) stays explicit, which is the second half of the
  injection defense alongside the write-time filter (§5.2).
- **Cross-client isolation at the query layer.** `client_id = null` returns global scope
  only — never any client-scoped fact. A session scoped to client X can reach global content
  plus client-X content and nothing else. This is a `WHERE` clause
  (`client_id = ? OR scope = 'global'`), enforced in the tool implementation exactly as the
  existing executor tools enforce client scoping — never a prompt instruction.

Results annotate every fact with status (`verified` / `unverified` / `disputed` — disputed
facts are served with both sides), so AI consumers can weigh claims.

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

The client wiki is reachable from the client detail page (tab or sidebar entry) — no context
switch to a separate URL tree.

Deferred to v1.x: ticket-detail sidebar showing the client overview and facts matching the
ticket (high value, but separable).

### 8.1 Binding interaction-design requirements (from UX review)

These bind the implementer; they are requirements, not suggestions.

1. **Provenance is progressive disclosure.** The default page view renders clean markdown. A
   section-level summary line ("3 unverified · 1 disputed") is the ambient signal; per-fact
   badges appear on hover or via an explicit "Show provenance" toggle. Every visible badge
   pairs color with a text or icon label — never color alone.
2. **Superseded treatment holds WCAG AA.** "Dimmed" means muted-ink (#6b7280) or darker on
   white — ≥4.5:1 at body size. Strikethrough is always paired with the textual
   "(superseded by sync, pending review)" label, never used alone.
3. **Fact actions are right-sized.** Confirm = one click, no modal. Retire = inline
   mini-confirm ("Retire? Yes / Cancel"). Correct = inline edit, in place. All three use
   secondary/outline styling (outline-danger for retire) — never the accent fill; these are
   hygiene actions, not the page's call to action.
4. **Health counters are secondary, never a nag.** The needs-review list sits below the
   content index in muted/neutral styling (`badge bg-secondary` at rest), zero-states are
   silent, and no page leads with "you have N items to review."
5. **AI addenda are distinct without reading as broken.** Flat tonal container (1px #e5e7eb
   border, #f8fafc background, 8px radius, no shadow), small "AI note" label with robot icon
   matching the existing AiTriage note treatment, inline source attribution, small
   outline-styled accept/dismiss/edit inside the block. Never a full-width alert-warning/
   alert-danger — this is a system of record, not an error state.

Advisory (carry into implementation, non-binding): fact actions must be keyboard-reachable
(consider a "review mode" rather than inline tab-stops at every fact); revision diffs pair
color with +/− markers; deviation blocks use a left-border + "Client deviation" label; search
is the primary affordance at the top of `/wiki`.

## 9. Configuration and open-source posture

Settings follow the established `Setting::settingOrConfig()` pattern via a new
`app/Support/WikiConfig.php` (cf. `TriageConfig`):

| Key | Default | Purpose |
|-----|---------|---------|
| `wiki_enabled` | off | master switch; everything no-ops when off |
| `wiki_auto_mine` | off | mine on ticket close — explicit opt-in so enabling the wiki never starts AI spend silently; the settings UI shows an estimated daily cost range next to the toggle |
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
  Search degrades to a `LIKE`-based path when FULLTEXT is unavailable (SQLite local dev),
  selected by connection driver, so the wiki stays testable without MariaDB.
- **`wiki:export`** — one-way Obsidian-compatible vault dump: folders by scope/kind,
  frontmatter carrying provenance summary, `[[wikilinks]]` intact. Doubles as the plain-text
  egress/backup story (data ownership for OSS users). Default output path is non-web-accessible
  (`storage/app/wiki-exports/`, never under `storage/app/public/`). Frontmatter provenance is
  identifiers only (fact status, source ticket/run IDs, timestamps) — no source ticket content
  is reproduced in frontmatter.
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

- **Unit (no AI):** redaction corpus (secret patterns, high-entropy strings, near-misses,
  context-aware conversational forms); injection-scaffolding filter corpus; cascade merge
  resolution (section-level override, depth-1 validation); subject-key dedup including the
  concurrent-merge locking path; contradiction pairing; staleness computation;
  dismissed-evidence subset semantics; LIKE-fallback search parity on SQLite.
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

Phases 1 and 2 ship as a single delivery: Phase 1 alone is a modest standalone (a better
`site_notes`), Phase 2 is the unlock, and they share the schema. The split remains useful as
plan structure, not as separate releases.

v1.x candidates (explicitly out of v1): ticket sidebar, auto-promote facts after K independent
reaffirmations, vault import / two-way sync, embeddings rerank, encrypted credentials module,
portal exposure, per-deployment prompt customization.

## 13. Risks and mitigations

- **Prompt injection via ticket content.** Ticket text is untrusted input, and the wiki is a
  persistence layer — one ticket could otherwise poison every future triage run for a client.
  Hard controls, both deterministic: the write-time injection-scaffolding filter over fact
  statements (§5.2, quarantine on hit) and structured fact serving at retrieval (§6 — facts
  are delimited data records, never prose woven into the prompt). Layered with: schema-
  validated extraction output, facts born `unverified`, the trust-tiered hot summary
  (guidance sections composed from `confirmed`/sync facts only), and per-statement redaction
  scanning at merge time.
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
