---
type: plan
tags: [soundit-dev, ai-technician, chet, reactive-pipeline, shadow-set, signals, queue-drainer, spec, review-me]
created: 2026-07-09
related: ["[[2026-06-29-chet-context-gathering-design]]", "[[2026-06-29 chet-queue-drainer-mandate]]"]
---
# Chet reactive pipeline — shadow-set + ticket-event wake (design spec)  `#review-me`

> Bead `psa-rgo5` (DESIGN-FIRST). Sibling of the queue-drainer Increment 1 context-gathering spec
> ([[2026-06-29-chet-context-gathering-design]], `psa-l5ho`). Goal: give the **external office-city
> Chet** (a) a **defined set of tickets to watch** and (b) a **real ticket-event → wake**, so it runs as
> a continuous reactive junior tech instead of ad-hoc scanning the whole board on a coarse timer.
> Grounded in a 2-agent source map + a signal-plane recon of the live PSA code and the `soundit-office`
> Gas City. **The two source-of-truth corrections below rewrite the bead's premise — read them first.**

## 1. Problem — the ground truth today (grounded in source)

The bead's framing ("wakes on session restart, NOT on ticket events"; "PART 2 is solved by the signal
plane, zero code") is **half-right in both directions**. What is actually live:

**a. The current wake is a coarse POLL, not restart-only and not event-driven.** The office-city order
`chet-triage-sweep` (`orders/chet-triage-sweep.toml`, exec `assets/scripts/chet-triage-sweep.sh`) fires
on a **cooldown** — 30m business hours / 90m off-hours, **default-OFF** until armed with
`.gc/chet-triage-sweep.enabled`. On each due tick it `bd create`s a sweep bead and `gc sling chet <id>
--nudge`s it. The bead tells Chet to *"read NEW or CHANGED tickets since your saved cursor via the
mcp/staff READ tools (`list_open_tickets`/`search_all_tickets`), process ≤15, oldest-changed first"* and
record a new cursor. So today's **de-facto shadow-set is "every open ticket changed since a cursor,"** a
whole-board scan bounded only by a cursor + a per-sweep cap. There is no curated subset.

**b. The event → wake infrastructure is fully BUILT — and fully DORMANT.** A real PSA Signals bus exists
(`app/Services/Signals/`), not just internal Laravel events:
- **Live producers:** `TicketObserver::created()` emits `ticket.created` (`app/Observers/TicketObserver.php:30`,
  context `{client_id, priority}`); `TicketNoteObserver` emits `ticket.client_replied` on a **non-private
  `Reply` note authored by `WhoType::EndUser`** (`app/Observers/TicketNoteObserver.php:69`). *Ticket
  updates / status changes emit no signal* (only these two ticket types fire; plus the legacy T2T HTTP
  callback for `HelpdeskButton`).
- **Catalog:** `SignalEventTypes::all()` (`app/Services/Signals/SignalEventTypes.php:9-101`) — `ticket.created`,
  `ticket.client_replied`, `ticket.sla_breached`, `ticket.sla_approaching`, `intake.*`, `agent.flag_attention`, … all `routable`.
- **Pipeline:** `SignalHub::emit()` persists a `SignalEvent` row → `RouteSignalEvent` → `SignalRouter::route()`
  (**enabled routes only**, `SignalRouter.php:23-24`) → matches `event_filter` → `SignalDelivery` →
  `DeliverSignal` → sink (`webhook` / `email` / `mcp`, `app/Jobs/DeliverSignal.php:44-50`), with per-route
  cooldown, 60/type/hr rate-limit, and causal-depth suppression (`SignalRouter.php:106-121`).
- **The MCP sink is exactly the "wake an external agent on a ticket event" primitive.** `McpSink` writes a
  compact `SignalInboxEntry` row (`app/Services/Signals/Sinks/McpSink.php:32-37`) that Chet drains via the
  **`poll_signals`** MCP tool — cursor-based on `inbox_id`, ack-on-cursor, token-scoped by `mcp_token_label`
  (`app/Services/Chet/OperatorBridgeTools.php:78`, `OperatorBridgeToolExecutor.php:260-324`) — **and**
  optionally POSTs an **HMAC-signed doorbell** to the destination's `wake_url`
  (`{destination_id, pending_count}`, `X-SoundPSA-Signature: sha256=hmac(body, wake_secret)`,
  `McpSink.php:41-100`).
- **But nothing is wired:** routes/destinations seed `enabled=false` and the only seeded route is a legacy
  Teams webhook for `agent.flag_attention` (`app/Services/Signals/DefaultSignalRoutes.php:82,101-103`). **No
  Chet `mcp` destination and no ticket route exist.** The config surface to create them already ships —
  the Alerts Hub (`AlertsHubController`) manages routes/destinations and `McpTokensController::linkSignalDestination`
  binds an `mcp` destination to a token by `mcp_token_label` (bead `psa-fn58`).

**c. There is no shadow-set primitive, and `list_my_tickets` can't be Chet's set for free.** No
`shadow`/`watch`/`subscription`/`saved_filter` table or model exists; there is **no tags table** (only the
`ticket_asset` pivot). `list_my_tickets` filters strictly `assignee_id = $this->userId`
(`app/Services/Assistant/AssistantToolExecutor.php:200-210`); over the staff MCP that `userId` resolves to
`TriageConfig::systemUserId()` for **reads** (`McpStaffController.php:1823-1830`), i.e. the *system user*,
not a Chet user. **There is no dedicated Chet `User` row** — Chet's identity is the `label='chet'`
`McpToken` (`ai_actor=true`, no `user_id`; `McpToken.php:24`, seeded
`2026_07_03_000002_add_trust_flags_to_mcp_tokens_table.php:24-29`). So "list_my_tickets is empty" because
nothing is assigned to that actor — making it meaningful requires either assigning tickets to a Chet user
(workflow-loaded) or a different surface entirely.

## 2. Decisions (RECOMMENDED — pending Charlie sign-off; see §7)

- **Shadow-set model = RULES-FILTER (option c), split into two facets — NOT assign-to-Chet, NOT a
  watch-list table.** Assigning tickets to a Chet `User` (option a) makes `list_my_tickets` work with zero
  build but overloads the human assignment/SLA/ownership model (Chet would show as the assignee, distort
  "who's working it," and fight real triage). A net-new `chet_watched_tickets` subscription table (option d)
  is maximum flexibility for a need we haven't proven — YAGNI. Tags (option b) don't exist and are a heavy
  net-new subsystem. Rules-filter reuses what's already there and keeps Chet **non-owning**.
- **The event-reactive facet of the shadow-set IS the Signals route `event_filter`.** "What to watch,
  reactively" = a `SignalRoute` whose `event_filter` picks `{types, min_priority, client_ids, categories}`
  (`SignalRouter::matches()`, `SignalRouter.php:62-93`). This is declarative config, zero code — the
  Mayor's "compose a route" is correct once a Chet destination exists.
- **Transport = POLL (keep) + DOORBELL (add, staged).** Keep the always-on poll as the completeness
  backstop; layer the event doorbell for low latency. Neither replaces the other.
- **Do not make `list_my_tickets` Chet's set.** Leave it a human-assignee tool. Chet's set is the event
  inbox (`poll_signals`) + a new rules query (`list_shadow_tickets`).

## 3. Design — three stages, zero-code → fully reactive

### 3a. Stage A — the event-reactive shadow-set (config-only, zero code)
Wire the dormant Signals bus to Chet and let the **existing sweep drain the event inbox** instead of
blind-scanning the board:
1. Create one `mcp` `SignalDestination` for Chet via the Alerts Hub: `mcp_token_label = 'chet'`,
   `enabled = true`, **no `wake_url` yet**.
2. Create one `SignalRoute` (`enabled = true`) with a **narrow starting `event_filter`** — recommended
   seed `{types: ['ticket.created','ticket.client_replied'], min_priority: 2}` (P1–P2 only; `min_priority`
   matches `priority <= N`, lower = more urgent) → one step → the Chet destination.
3. In the sweep bead's directive, have Chet **call `poll_signals` first** to drain its curated, deduped,
   priority-scoped event feed, then fall back to the cursor scan only for the standing facet (§3c).

This alone converts "scan 90 tickets" into "here are the N tickets that had a routable event since your
last ack, already filtered to what you watch" — **no new endpoint, no schema, no PSA code.** Latency stays
the poll cadence (30–90m).

### 3b. Stage B — the doorbell (low-latency wake; small, isolated, office-side)
Add a small **office-side doorbell receiver** and set the Chet destination's `wake_url`/`wake_secret`.
On a matching event `McpSink` writes the inbox row **and** POSTs the HMAC doorbell; the receiver:
1. verifies `X-SoundPSA-Signature` against `wake_secret` (reject on mismatch — the endpoint is
   internet-reachable),
2. runs `gc session wake chet` (single Chet destination ⇒ any valid doorbell = "wake Chet"),
3. Chet wakes fresh, `whoami`-bootstraps, and drains via `poll_signals`.

This is the **only net-new executable code** in the whole design, and it lives in office infra (a bead for
`gus`/office-infra), not the PSA repo. Gate it behind the same enable marker as the sweep so it ships
dormant. High-priority events now wake Chet in seconds while the poll remains the backstop.

### 3c. Stage C — the standing / aging facet (small PSA-side read tool)
Events cover *new/changed*; they miss tickets that **need attention but produced no recent event** (aging
`waiting_us`, an open P2 untouched for days). Add one client-agnostic, hard-capped MCP read tool
**`list_shadow_tickets`** in `AssistantToolExecutor` (mirroring `list_open_tickets`, `:234`) backed by a
rules query over existing columns/scopes (`Ticket::open()`, `assignee_id`, `priority_order`, `status`,
`source`, `scopeOverdue`/`scopeBreaching`) — e.g. *open ∧ not vendor-blocked (`PendingThirdParty`) ∧
(unassigned ∨ assigned-to-AI-actor) ∧ (aging past a per-priority threshold ∨ SLA approaching/breaching)*,
ordered priority then age, capped ≤20. Wire the schema in `AssistantToolDefinitions::psaTools()` + handler
in `AssistantToolExecutor` (MCP auto-injects scope). The exact predicate is Charlie's call (§7 D2) and
best tuned **after** the Stage-A soak shows how Chet actually picks up work.

### 3d. What this unifies
One `SignalRoute` decides **both** halves of the reactive question: its `event_filter` is the "what to
watch" set **and** its `mcp` destination + `wake_url` is the "how the wake fires." The Mayor's unification
note holds — and it dovetails with `psa-jqar` (derived recipients): a future `event_filter`
`client_ids`/owner scoping is the same mechanism as routing to a ticket's owner, with the AI actor as owner.

## 4. Safety (non-negotiable, established patterns)
- **Doorbell auth** — the `wake_url` receiver is internet-reachable; it must HMAC-verify every POST against
  `wake_secret` (constant-time compare) and do nothing but `gc session wake chet`. No ticket data crosses
  it — only `{destination_id, pending_count}`; the actual signals are pulled by Chet over the token-auth'd
  `poll_signals`. No new secret in the repo (`wake_secret` is an encrypted `SignalDestination` field).
- **Redaction** — `poll_signals` payloads and any `list_shadow_tickets` free-text (subjects) ride the
  existing per-sink `SignalHub` sanitization + the MCP read redaction; keep that posture, no raw bodies.
- **Client scoping** — `list_shadow_tickets` reuses the `AssistantToolExecutor` ownership guard; no
  cross-tenant bleed. Chet's token already carries `require_explicit_client_scope=true`.
- **Held-only / fail-soft** — Chet stays read/propose-only under Spike-0; a poll/doorbell failure must
  degrade to today's behavior (the sweep still fires on its cooldown). Rate-limit + cooldown +
  causal-depth suppression already bound event volume (`SignalRouter.php:106-121`).

## 5. Rollout / calibration
- Everything ships **dormant**: the route/destination are operator-enabled in the Alerts Hub; the doorbell
  reuses the sweep's `.enabled` marker; `list_shadow_tickets` behind the existing agent flag.
- Sequence: **Stage A first** (config only) → soak, read the held proposals, confirm the event feed is the
  right set → tune `event_filter` breadth (types, `min_priority`, `client_ids`) → then Stage C's aging
  predicate from real tickets → Stage B doorbell last, once the set is trusted and latency is the only gap.
- Rollback is instant at every stage (disable the route, remove the marker, null the flag).

## 6. Testing (TDD)
- **Route match:** a `ticket.created` P1 for a watched client creates a pending `mcp` `SignalDelivery`; a
  P3 (below `min_priority`) and an unwatched client do not; `ticket.client_replied` fires only for a
  non-private `EndUser` `Reply` note (not staff/private).
- **`poll_signals`:** cursor ack drops rows `id <= cursor`; token-scoping isolates Chet's inbox from other
  destinations; empty inbox is a clean no-op.
- **`list_shadow_tickets`:** returns the rules set (open ∧ not-vendor-blocked ∧ aging/SLA), excludes
  closed/`PendingThirdParty`, respects the ≤20 cap and priority-then-age order; cross-client id rejected.
- **Doorbell receiver:** valid HMAC → one `gc session wake chet`; bad/absent signature → 401 + no wake;
  disabled marker → no-op.
- **Injection:** a malicious ticket subject in a signal payload / shadow row is redaction-scanned, not executed.

## 7. Decisions needed from Charlie (the bead's explicit ask)
- **D1 — reactive watch criteria (`event_filter`).** Which types wake Chet: `ticket.created` +
  `ticket.client_replied` only, or also `ticket.sla_breached`/`sla_approaching` and `intake.*`? Starting
  `min_priority` (recommend P2)? All clients or a `client_ids` allowlist to start? *Recommendation: seed
  narrow (created + client_replied, P1–P2, all clients), widen after soak.*
- **D2 — standing/aging criteria (`list_shadow_tickets`).** Statuses in scope; unassigned-only vs all-open;
  per-priority aging thresholds; exclude alert-source/vendor-blocked. *Recommendation: decide after the
  Stage-A soak.*
- **D3 — assignment model (the a-vs-c fork).** Confirm we do **not** assign tickets to a Chet `User`
  (keeps `list_my_tickets` a human tool; Chet watches via events + rules). *Recommended.* If you want Chet
  to appear as an assignee, that's the option-(a) build instead (dedicated Chet user + read-actor change).
- **D4 — transport.** Poll+doorbell (recommended) vs poll-only (simplest, skip Stage B) vs doorbell-only.
- **D5 — relationship to the in-process technician loop.** `TicketObserver::created` already dispatches
  `RunTechnicianLoop` (`app/Observers/TicketObserver.php:53-54`, gated `TechnicianConfig::enabled()`) — an
  *in-app* reactive agent, distinct from the external office-city Chet. Does the external Chet's event-wake
  **complement** it (external = judgment/held proposals; in-app = fast enrichment) or **supersede** it?
  *Recommendation: out of scope for this increment — flag and revisit; they can coexist.*

## 8. Out of scope (YAGNI)
A `chet_watched_tickets` subscription table + manual watch UI; a tags subsystem; `ticket.updated`/status
signals (not currently emitted — add only if a decision needs them); replacing the poll; the in-chat action
loop and draft-and-tee-up tooling (later queue-drainer increments). This increment is the reactive
*intake* that those stand on.

## 9. Build path
On approval of this spec (and D1/D3/D4) → split into beads: **(P1)** PSA — `list_shadow_tickets` rules tool
(TDD, behind flag); **(P2)** office-infra (`gus`) — doorbell receiver + enable marker; **(config)** operator
— Chet `mcp` destination + ticket route in the Alerts Hub, `poll_signals`-first sweep directive. Stage A is
pure config and can go live before any code merges. Commit this spec to `docs/superpowers/specs/` at dispatch.
