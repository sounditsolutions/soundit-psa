# Alerts Control-Surface — MSP-configurable alert-type registry (SCOPE + PROPOSE)

**Bead:** so-3n5d · **Author:** psa/psa-lead · **Date:** 2026-07-14
**Status:** PROPOSAL — for Charlie's review. **Do NOT build until ratified** (same gate as psa-xcyo / so-4nd9).
**Trip-relevant:** Aug 1 — Charlie/Justin need to control *what pings them* while away.

---

## 0. Charlie's ask (verbatim)

> "We need a lot more alert types and each alert type needs a check for immediate or queued delivery. Following a similar shape as the MCP tokens where we wire up a lot of different alert types and let the MSP decide which alerts to enable."

Decoded into three primitives:
1. **More alert types** — expand the catalog (wire the dormant types, add net-new).
2. **Per-type delivery mode** — each type carries an immediate-vs-queued check.
3. **MCP-tokens-shaped control page** — a catalog where the MSP toggles which alerts are on, exactly like the per-tool grant UX on the MCP Tokens page.

---

## 1. The key insight (why this is small, not a rewrite)

The Signals substrate we need already exists and is battle-tested. **"Only 5 types reach Chet today" is not an architectural limit — it is literally one hand-edited JSON array**: `signal_routes.event_filter.types = [5 keys]` on the single production route id=1, pointing at a live Chet MCP destination.

So a **type-first registry is just a friendlier, MSP-facing way to populate that type-set** — plus a second dimension (immediate vs queued) that decides *which* managed delivery path each enabled type takes. We keep 100% of the tested router (matching, cooldown, rate-cap, causal-depth suppression, escalation ladder, the MCP `signal_inbox` pull-queue, delivery audit). The registry sits **above** the substrate as a control layer; it does not replace it.

This is the same shape as MCP Tokens: `mcp_tokens.tools` is a JSON list where **presence = enabled** and a **`:immediate` / `:staged` suffix = per-item mode** (`app/Support/McpToolModes.php:22-33`). We mirror that convention exactly, with `:immediate` / `:queued` as the mode.

---

## 2. Current-state ground-truth (verified, with file:line)

### 2.1 The Signals substrate (reusable as-is)
- **Catalog:** `app/Services/Signals/SignalEventTypes.php` — a static PHP class, `all()` returns 18 types (17 `routable`, 1 not: `system.test`). Each entry: `label`, `core` (unused dead metadata), `routable`. **No category field** (grouping is derived from the key prefix), **no per-type config**.
- **Emit → route → deliver:** `SignalHub::emit($type, $entity, $summary, $context)` (`app/Services/Signals/SignalHub.php:24`) records a `signal_events` row and queues `RouteSignalEvent` → `SignalRouter::route()` (`app/Services/Signals/SignalRouter.php:27`) loads **enabled** `signal_routes`, `matches()` each on `event_filter`, applies suppression (causal-depth>3, rate-limit `MAX_PER_TYPE_PER_HOUR=60`, per-route cooldown), writes `signal_deliveries` → `DeliverSignal` → sink.
- **Route↔type binding:** `event_filter` JSON on `signal_routes`, key `types` = `'all'` or an **exact-match** array (no wildcards, no pivot) — `SignalRouter::matches()` `:125-132`.
  - ⚠️ **Load-bearing trap to preserve:** `matches()` **hard-fails a null context key**, and `SignalHub` persists only 4 context keys (`category`, `priority`, `client_id`, `destination_id`) — so a route carrying `min_priority`/`categories` silently matches *zero* intake events. The registry must not depend on context filtering for its enable/mode gate.
- **Sinks:** exactly three — `webhook`, `email`, `mcp` (`DeliverSignal::handle()` match, `AlertsHubController.php:245` validation). **No `board`/`sweep`/`digest` destination type exists** — those are aspirational.
- **Chet = a pull inbox:** `McpSink` writes a `signal_inbox` row (`app/Services/Signals/Sinks/McpSink.php:32`); Chet drains it via the `poll_signals` MCP tool (`OperatorBridgeToolExecutor::pollSignals()`). Consumability is gated on a live token that holds the `poll_signals` grant (`SignalRouter::wouldReachMcpDestination()` `:103` — already load-bearing and tested by EmailTriageWatch / psa-28j4.3).
- **Everything is already queued** (two job hops). There is **no immediate-vs-queued distinction today** — the only synchronous path is the Alerts Hub "Test" button, which bypasses the router.
- **Admin UI exists:** "Alerts Hub" at `/settings/alerts` (`AlertsHubController`, 713 lines; views `resources/views/settings/alerts/`) — destination + route CRUD, a prefix-grouped type-picker (`eventTypeGroups()` `:606`), test-fire, delivery history, and an **append-only config audit** (`signal_config_log`, immutable via model guard + DB triggers). Routes are created **disabled** by default.

### 2.2 Two orthogonal dormancy axes (do not conflate)
- **Emitted vs dormant:** only **8 of 17 routable types are ever `emit()`ed**: `ticket.created`, `ticket.client_replied`, `intake.email_received`, `intake.email_unresolved`, `intake.call_received`, `intake.call_transcribed`, `agent.flag_attention`, `signal.delivery_failed`. The other **9 are catalog-only, no emitter**: `ticket.sla_breached`, `ticket.sla_approaching`, `operator.message`, `agent.proposal_held`, `agent.proposal_auto_closed`, `agent.run_failed`, `integration.sync_failed`, `tactical.alert_created`, `digest.daily`.
- **Routed vs not-routed:** which types an *enabled* route matches (the prod "5 to Chet"). Independent of whether the type is emitted.

**Consequence:** "a lot more alert types" = **(a)** wire emitters for the dormant 9, **(b)** add net-new types, **(c)** expose all of them in the registry. The registry mechanism is cheap; wiring emitters is the bulk of the per-type work.

### 2.3 The two gaps the manager flagged are pre-carved, emitter-only
- **SLA:** `ticket.sla_breached` / `ticket.sla_approaching` are routable catalog slots with **no emitter**. The breach predicate `Ticket::isSlaBreach()` and the deadline fields (`response_due_at`, `due_at`) already exist; `tickets:recalculate-sla` recomputes deadlines but detects nothing and is **not scheduled**. → needs a scheduled sweep that calls `emit()`.
- **Integration sync failure:** `integration.sync_failed` is a routable catalog slot with **no emitter**. Every sync returns a `SyncResult` accumulating `errors` via `recordError()` (`app/Services/SyncResult.php`) — logged only, never pinged. → needs to bridge `SyncResult` failures into `emit()`.

### 2.4 The "scattered alerters" (evidence for consolidation, but NOT all MVP)
Three parallel alerting systems exist today: the RMM **`Alert`** model + `AlertService` (monitoring alerts, dashboard/`/alerts` page); **`NotificationService`** + `NotificationEventType` (17 email-only, per-user opt-in staff/portal notifications); and **Signals** (the registry-shaped one). Plus hand-rolled `PrepayAlertService`, `EscalationNotifier`, `DailyBriefingService`, `DigestBuilder`. Unifying all three is a *north-star*, **not** the Aug-1 MVP (see §7 scope).

### 2.5 The MCP-Tokens UX to mirror (`resources/views/settings/mcp-tokens/show.blade.php`)
Grouped accordion (integration → sensitivity tier → tool row). Each tool row = a Bootstrap **toggle switch** (`.tool-switch` = enable) + an optional **second checkbox** (`.mcp-mode` `.tool-mode-immediate`) that appears only when the item is granted and mode-capable (`:296-308`). Auto-saves via debounced PATCH → JSON, with a toast. Grant list serialized as `name` or `name:immediate`/`name:staged` (`grantedTools()` `:549`). **This transfers almost verbatim** to an Alert-Types page: switch = per-type enable, second checkbox = immediate-vs-queued.

---

## 3. Proposed design

### 3.1 Mental model (what Charlie sees)
A single **Alert Types** catalog, grouped by domain (Tickets · Intake · AI Technician · Integrations · Billing · Ops · System). Each type is a row with:
- a **master on/off switch** ("alert me about this"), and
- an **Immediate / Queued** selector (only meaningful when on).

**Immediate** = near-real-time ping to Chet (the operator bridge). **Queued** = rolled into a periodic batched digest, not a real-time ping. Off = never pings, regardless of anything else.

That's the whole surface. No routes, no destinations, no filter JSON for the common case.

### 3.2 Data model
A **`signal_type_settings`** table — one row per *configured* type (absent row ⇒ catalog default):

| column | type | notes |
|---|---|---|
| `type_key` | string, **unique** | FK-by-convention to the catalog key |
| `enabled` | bool | master gate |
| `delivery_mode` | string(16) | `immediate` \| `queued` |
| `updated_by` | FK users nullable | audit |
| timestamps | | |

Rationale for a table over a settings-JSON-blob: emit/route-time gate wants an indexed `where type_key=?`; it composes with the existing `signal_config_log` audit; same "row per config object" grain as routes/destinations. (JSON-blob-on-Setting is the lighter alternative — mirrors `mcp_tokens.tools` most literally — but loses the indexed lookup and per-row audit. **Recommend the table.**)

New service **`SignalTypeRegistry`** (`app/Services/Signals/SignalTypeRegistry.php`): `isEnabled($type)`, `deliveryMode($type)`, `all()` (catalog ⊕ settings ⊕ defaults for the UI), `set($type, $enabled, $mode, $user)` (writes the row + a `signal_config_log` entry). Catalog gains `category` + `description` + `default_enabled` + `default_mode` metadata (extend `SignalEventTypes`, repurposing the dead `core` slot).

### 3.3 How it maps onto the existing router (the crux)
The registry gates at the **routing layer**, authoritatively, without suppressing emission (we still record every `signal_event` for history/board/audit):

1. **Master gate in `SignalRouter::route()`** — before route matching: `if (! SignalTypeRegistry::isEnabled($event->type_key)) return;` (record-only, delivers nowhere). *This makes "off" mean off* even against a stray custom route — the property Charlie needs for the trip. (Decision Q1.)
2. **Immediate types** → a **managed immediate route** (this *is* prod route id=1, re-cast): its `event_filter.types` is **derived** from the set of `enabled && immediate` types instead of hand-edited. Toggling a type to immediate adds it to the managed route's type-list; toggling off / to queued removes it. It points at the operator's Chet MCP destination and keeps its cooldown/suppression. Custom Hub routes still work for power users; the registry just owns the managed one.
3. **Queued types** → **not** on the managed route. A new scheduled **sweep** (`signals:sweep-queued`, reusing the DigestBuilder once-a-day-batched pattern) collects `enabled && queued` `signal_events` since the last sweep and delivers **one roll-up** to the same Chet destination (emitted as a `digest.*` roll-up signal that itself routes immediately). Chet receives a single "here are the N queued things" ping on cadence. (Decision Q2 — alternative queued targets below.)

Net: immediate = per-event Chet signal; queued = periodic batched Chet signal; off = recorded but silent. The tested substrate does all the delivery.

### 3.4 The UI
A new **"Alert Types"** panel promoted to the **top of the existing Alerts Hub page** (`/settings/alerts`), with the current Destinations/Routes tables demoted under an **"Advanced"** disclosure. (Decision Q6 — vs a separate settings sub-page.) Structure copied from `mcp-tokens/show.blade.php`: grouped accordion, per-row enable switch, per-row Immediate/Queued selector, debounced auto-save PATCH → `signal_config_log`. New controller actions on `AlertsHubController` (or a thin `AlertTypesController`): `updateType($typeKey)` mirroring `McpTokensController::updateTools()`.

### 3.5 Defaults + migration (backward-compatible day one)
The migration **seeds `signal_type_settings` from current prod state**: the types currently on route id=1 → `enabled + immediate`; everything else → catalog default (proposed mostly **off**, a few security/failure types **queued**). **Day-one behavior is byte-identical** to today; Charlie then flips on what he wants. Proposed default table ships in the doc for Charlie to ratify (Q4).

---

## 4. Held-only safety (rail)

This is an **enable/disable + cadence** control surface. It adds **NO auto-act thresholds** and changes **no** existing one (`propose_close_auto_threshold` etc. stay null). It decides *what pings the operator and how fast* — never what the AI *does*. "Immediate → Chet" only puts a signal in Chet's inbox; Chet's own held-only config governs any action. Fully within the rails.

---

## 5. Scope

**In (MVP, Aug-1):** the registry data model + service; the master gate + managed-immediate-route derivation; the queued sweep (batched roll-up); the MCP-tokens-shaped Alert Types UI; wiring the two flagged gaps (SLA breach/approaching, integration.sync_failed) as enable-able types; backward-compatible seed.

**In (stretch):** expand the catalog with the remaining dormant `agent.*` emitters + a `tactical.alert_created` bridge from `AlertService::upsert()`; fold obvious `NotificationService` failure events (invoice/prepay/voicemail) in as registry types.

**Out (north-star, post-trip):** unifying the RMM `Alert` model and `NotificationService` per-user email prefs into one store; per-recipient/per-staff targeting in the registry; per-client alert config; per-severity conditional routing; a dedicated human-reviewed "board" page (the Activity log can stand in). **No new auto-act thresholds — ever.**

---

## 6. Open decisions for Charlie (SCOPE forks)

- **Q1 — Registry authority.** Should a registry-disabled type be a **master gate** that overrides *any* custom Hub route (rec: **YES** — "off means off," critical for trip control), or only manage the managed route?
- **Q2 — What "queued" delivers to.** (a) **batched roll-up → one Chet signal on a sweep** (rec MVP — stays in the Signals plane, reuses `signal_inbox`); (b) a persistent human-reviewed **Alerts board** page; (c) roll into the existing **daily email digest**. Pick the MVP meaning of "queued."
- **Q3 — Registry scope.** MVP = **Signals catalog only** (rec), with `NotificationService` email-events + RMM `Alert` folding in as Phase 2/3? Or attempt the full three-system unification for Aug-1 (not advised — boils the ocean)?
- **Q4 — Per-type defaults.** Ratify the proposed default on/off + cadence table (seeded to preserve current behavior). Which types must be **on** for the trip beyond SLA-breach + sync-failed?
- **Q5 — Net-new types to prioritize.** Candidate adds: backup-failure, cert/domain expiry, queue-worker-down (already half-detected on the Hub index), Huntress incident/escalation, M365 risky-event. Which make the Aug-1 cut?
- **Q6 — UI placement.** Promote **into the Alerts Hub page** as the primary panel (rec), or a **separate** `/settings/alerts-types` sub-page? (Note: reuse the `settings.alerts.*` route-name space carefully to avoid the active-state glob collision the recon flagged.)

---

## 7. Proposed epic breakdown (beads filed on ratify, not before)

**Parent:** so-3n5d.

- **Phase 0 — Foundation (dormant, backward-compatible):**
  - `.1` `signal_type_settings` model + `SignalTypeRegistry` service + catalog metadata (category/description/defaults); seeded from prod route id=1. TDD.
  - `.2` Master gate in `SignalRouter` + managed-immediate-route derivation. **Own tight review** — touches the shared delivery path for *all* signal types (same care class as psa-28j4.4).
  - `.3` Queued delivery: `signals:sweep-queued` batched roll-up (reuse DigestBuilder pattern).
  - `.4` Alert Types UI (MCP-tokens-shaped) + `updateType` + `signal_config_log` audit.
- **Phase 1 — Close the flagged gaps (each an enable-able type):**
  - `.5` SLA sweep → emit `ticket.sla_breached` + `ticket.sla_approaching` (scheduled; reuses `Ticket::isSlaBreach()` + deadlines).
  - `.6` `integration.sync_failed` emitter bridging `SyncResult` failures into `emit()`.
- **Phase 2 — Expand catalog (stretch):**
  - `.7` `agent.proposal_held` / `agent.proposal_auto_closed` / `agent.run_failed` emitters.
  - `.8` `tactical.alert_created` bridge from `AlertService::upsert()`.
  - `.9` Fold `NotificationService` failure/billing/voicemail events into registry types.
- **Phase 3 — North-star (post-trip):** unify RMM `Alert` + per-user notify prefs; per-severity/per-client filters; dedicated board view.

**Phase 0 + 1 = the trip-critical MVP.** Phase 2 stretch, Phase 3 future.

---

## 8. Risks / calibration

- **The `matches()` null-context trap** (§2.1) must not leak into the registry's gate — the gate keys on `type_key` only, never context. Managed-route derivation writes a clean `types` array; no `min_priority`/`categories` on the managed route.
- **Master-gate-in-router** touches the delivery path for every type — carries the same "make suppression scream, never fail closed silent" obligation as psa-28j4.4 (CLAUDE.md rule 3). Record-only on disable is deliberate (history preserved); a *log line* when a type is gated off keeps it observable.
- **Migration fidelity:** the seed must read the *live* prod route id=1 type-set (not a guess) so day-one is unchanged — the exact 5 types are prod DB data, not in the repo. The `.1` migration reads existing `signal_routes` rather than hardcoding.
- **Two config surfaces** (registry vs raw Hub routes) risks confusion — mitigated by making the registry primary and demoting raw routes to "Advanced," and by the master gate making the registry authoritative.

---

*Awaiting Charlie's review. On ratify: file Phase 0–2 beads, build TDD/SDD, ship dormant + backward-compatible, route through the review-lead gate to merge. Never self-review-gate; never deploy.*
