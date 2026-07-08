---
type: plan
tags: [soundit-dev, ai-technician, chet, context-gathering, queue-drainer, spec, review-me]
created: 2026-06-29
related: ["[[2026-06-29 chet-queue-drainer-mandate]]", "[[2026-06-28-trip-readiness-tracker]]"]
---
# Chet context-gathering — design spec (queue-drainer Increment 1)  `#review-me`

> **Increment 1 of the Chet queue-drainer initiative** (mandate: [[2026-06-29 chet-queue-drainer-mandate]]).
> Goal: give Chet the **full client/situation picture before it acts**, so its judgment, drafts, and plans
> are grounded — the binding quality constraint today (your #88 correction: *"review other tickets,
> appointments, calls for this client — you're missing context"*). Bead `psa-l5ho`. Grounded in a 4-agent
> source map of the live code.

## 1. Problem (grounded in source)
Chet reasons over a **single ticket** through one chokepoint — `ContextBuilder::buildForTicket()`
(`app/Services/Triage/ContextBuilder.php:55`), the same builder the agent uses at `TechnicianAgent.php:76`.
That gives it rich **static** client reference (profile + `securitySnapshot()`, the wiki Overview / site-notes,
active contracts + prepay, the contact's M365 enrichment, the ticket's own linked assets + call + last-10
notes). It does **not** assemble the client's **live situation**: the client's other/open tickets, call
history, billing/invoices, full asset fleet, or people roster. Its autonomous toolbelt is only **5 reads**
(`search_tickets` [keyword-required], `get_ticket_notes`, `wiki_*` — `TriageToolDefinitions::readTools():27`)
— there isn't even a tool to *list a client's open tickets*. So it judges in a vacuum and, as the correction
showed, usually doesn't go looking. The data all exists on `Client` (`tickets/phoneCalls/invoices/assets/
people/contracts`, `Client.php:121-159`), client-scoped — it's simply never aggregated.

## 2. Decisions (confirmed with Charlie 2026-06-29)
- **Approach = HYBRID.** Always-inject a bounded client-situation digest (guarantees awareness — fixes "it
  didn't look") **and** add drill-down tools for depth (serves the "do the legwork" mandate). Mirrors the
  codebase's own proven pattern (tiny eager digest + lazy, hard-capped client-scoped tools — how
  `AssistantService` already works).
- **Appointments = PROXY.** No calendar/appointment model exists in the PSA. Use **ticket SLA dates
  (`due_at`/`response_due_at`) + call `followed_up_at`** as the "time-sensitive / scheduled" signal. A real
  M365 calendar source is deferred to its own increment.
- **Depth over cost** (mandate): make the digest generous; don't be shy with the tools. Optimize for Charlie's
  cognitive load + client CX, not token frugality.

## 3. Design

### 3a. The always-inject "Client Situation" digest
Add an **opt-in parameter** `bool $includeClientSituation = false` to `ContextBuilder::buildForTicket()` and a
private `buildClientSituationSection(Ticket $ticket): string`. **Only `TechnicianAgent::run()` passes `true`**
(`TechnicianAgent.php:76`) — the other 6 callers (triage classifier, technical triager, conversation reviewer,
assistant, reply drafters, lesson capture) are unchanged and un-re-costed. This mirrors the existing `$skipNotes`
flag idiom. The new section (bounded, digested — counts and one-liners, **not** bodies/transcripts):

1. **Client header + risk flags** — name, `is_active`/`stage`, primary tech; `securitySnapshot()` one-liner;
   contacts with no-MFA / external-forward (`Person::hasExternalForward`) / inactive; asset `activeAlerts` count.
2. **Open-ticket digest** — `Ticket::forClient()->open()` → counts by status/priority + the **top N (≈8) by
   priority then age**: id, subject, status, age, last-activity. (Not descriptions.) This is the core "what
   else is going on" the correction asked for.
3. **Recent calls** — last ≈5 `PhoneCall::forClient()->recent()`: date, direction, `call_summary` + `next_steps`
   (capped), `charge_classification`. **Summaries only — never transcripts** in the digest.
4. **Billing one-liner** — active-contract prepay balance(s) (`Contract::prepayBalanceFormatted`) + unpaid-invoice
   count/sum (`Invoice::forClient()->unpaid()`).
5. **Time-sensitive line (appointments proxy)** — any open tickets with `due_at`/`response_due_at` approaching or
   breaching (`Ticket::scopeOverdue/scopeBreaching`) + calls awaiting follow-up (`followed_up_at` null).
6. **(Keep) Wiki Overview** — already injected via `clientEnvironmentSection()`; the highest-signal digested block.

Each item reuses the established **`MAX_*` truncation budgets** + `clip()` + `strip_tags`; the whole section is
length-logged like the rest. Default bounding: **open-only** for "current state," **recency window** for history,
**top-N** for lists. Reuse existing indexes (`tickets.client_id/status/opened_at`, `phone_calls.started_at`, etc.).

### 3b. The drill-down tools (lazy depth)
Two new **client-scoped, hard-capped** read tools so Chet can go deep on what the digest surfaces:
- **`list_client_tickets`** — the client's tickets with a **status filter** and **no required keyword** (closes
  the central gap; `search_tickets` demands a keyword). `Ticket::where('client_id',…)->whereIn('status',$open)`,
  capped ≤20, mirroring `listOpenTickets()` but client-bounded.
- **`list_client_calls`** — the client's recent calls (summaries, capped count), mapping onto
  `PhoneCall::forClient()`. Transcripts stay strictly on-request via the existing per-ticket call tool, ≤10k chars.

**Wiring (the 3 places that give the *agent* a tool — all required):** schema in
`TriageToolDefinitions::readTools()` (`:27`) + name in `TechnicianAgentToolExecutor::READ_TOOLS` (`:27`) +
handler in `TriageToolExecutor::execute()` (`:52`), client-scoped via the existing `$this->clientId` guard.
Also add the schemas to `AssistantToolDefinitions::psaTools()` (`:185`) + handlers in `AssistantToolExecutor`
so the interactive assistant + MCP reuse them (MCP auto-injects `client_id`).

## 4. Safety (non-negotiable, all established patterns)
- **Cross-tenant isolation** — every query filters `client_id`; re-verify ownership on any passed id (the
  `AssistantToolExecutor` guard pattern); **all wiki reads go through `WikiRetrieval`** (null client ⇒ global-only).
- **Prompt-injection** — the digest pulls free-text (ticket subjects, call summaries, notes). Keep the
  always-injected-surface posture: `WikiRedactor::scan()` the free-text, fall back to safe text on a hit; trusted
  operator directives stay outside the fence via `PromptFence::operatorDirective`.
- **Fail-soft** — a situation-gather failure must **never** break the agent run: wrap `buildClientSituationSection`
  so any error logs and returns empty, degrading gracefully to today's per-ticket context. (The agent already
  runs held-only on prod, so worst case = today's behavior.)

## 5. Rollout / calibration
- Gate behind a flag **`agent_situation_context_enabled`** (null/false default) so we can turn the richer context
  on, **observe the held proposals improve**, and roll back instantly if a digest is noisy/wrong. The agent is
  already live held-only on prod — this immediately lifts the quality of the proposals already in the cockpit,
  with zero new action risk.
- Calibrate by reading the held proposals before/after; tune the digest's N / recency windows from real tickets.

## 6. Testing (TDD)
- `buildClientSituationSection`: assembles the right sections; respects `MAX_*` caps; **open-only / top-N / recency**
  bounding; **fail-soft** returns empty on a query error (agent run still completes).
- Opt-in: only `TechnicianAgent` gets the section; the other 6 `buildForTicket` callers are byte-unchanged.
- Tools: `list_client_tickets`/`list_client_calls` are **client-scoped** — a cross-client id is rejected; caps hold.
- Injection: a malicious ticket subject / call summary in the digest is redaction-scanned, not executed.
- Cross-client: two clients' data never bleeds into one digest.

## 7. Out of scope (YAGNI — later increments of the queue-drainer)
Real calendar/appointments (proxied here); **draft-and-tee-up** tooling (draft invoices/replies/plans → 1-tap
approve); the **in-chat action loop** (reply in Teams → Chet executes); **structural-noise dedup** (group recurring
integration faults). Those are the next increments; this one is the **foundation they all stand on**.

## 8. Build path
On your approval of this spec → `writing-plans` (implementation plan) → dispatch the build to `developer`
(TDD/SDD, behind the flag, dormant-until-enabled) → Mayor reviews + merges + deploys → enable + calibrate on the
held soak. Commit the canonical spec to the repo (`docs/superpowers/specs/`) at dispatch.
