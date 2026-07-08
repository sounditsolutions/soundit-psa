---
type: plan
title: "SoundPSA AI Control Surface — Settings Page (Scoping Brief)"
tags: [soundit-dev, psa, mcp, alerts, scoping, review-me]
created: 2026-07-01
status: awaiting-review
related:
  - "[[2026-07-01-gascity-chet-teams-bridge-design]]"
  - "[[soundit-dev]]"
---

# SoundPSA AI Control Surface — Settings Page (Scoping Brief)

> **This is a PRE-SPEC scoping brief, not a spec.** It maps the primitives that already
> exist, frames the load-bearing decisions with options + a recommendation, and lists the
> open questions for Charlie. Its job is to *kick off a proper brainstorm* and shape the
> eventual spec — it deliberately does **not** contain task-by-task steps or final code.
> Tracking bead: **psa-fn58**.

---

## 0. Decisions locked (Charlie, 2026-07-01, via Discord)
- **Tenancy = per-install, single-tenant.** Not shared multi-tenant. The feature operates on each
  MSP's own SoundPSA install — "same for any MSP" = the identical feature in every install. → No
  `tenant_id` / multi-tenancy machinery in v1 (drop the Option-B tenant-ready hedge). First-class
  tables still stand (for list/revoke/audit), just single-tenant. **(Resolves Q1.)**
- **Admin-gating = deferred ("a later lift").** v1 sits behind the existing `auth` like the other
  settings pages; no new RBAC/admin gate now. Accepted consequence: any staff login can
  mint/scope tokens until RBAC lands — acceptable for a trusted per-install team. **(Resolves Q3;
  the §3e / §4 admin-gate work moves OUT of v1.)**

Everything below is the original scoping analysis; §3(a) and §3(e) are now settled per the above.

---

## 1. Purpose + the two subsystems

**Purpose.** Charlie's framing: *"the control surface that makes SoundPSA truly AI-friendly."*
Today the two things that make the PSA usable by AI agents — **who gets which tools** and
**where alerts go** — are set from the CLI and from a handful of fixed, hard-coded config
keys. This feature is the **management UI over primitives we already shipped**: it becomes how
Sound IT (and, later, any MSP) mints & scopes agent tokens and routes agent alerts, without
SSH access.

**Subsystem 1 — MCP token / key management.** A UI over `App\Support\McpConfig` +
`App\Support\McpStaffToken`. Today `McpConfig::rotateStaffToken(allowedTools, label)` mints a
`psa-mcp-…` bearer, stores only its **sha256 hash** in the encrypted `mcp_staff_scoped_tokens`
Setting record (`{label, hash, tools[], created_at}`), and the plaintext is shown once by the
`mcp:rotate-staff-token` CLI. At request time `McpStaffController` (`POST /api/mcp/staff`) reads
the token's `McpStaffToken::allows($tool)` to gate `tools/list` + `tools/call`, and audits every
call to `mcp_audit_logs`. The UI replaces/augments the CLI: create a token, pick its tool set,
show the secret once, list/rotate/revoke existing tokens, and view their audit trail.

**Subsystem 2 — alert-destination management.** A UI + registry over the escalation delivery
core. Today `EscalationNotifier` → `OperatorDelivery::send()` fans an agent alert out to a
**single** operator Teams webhook (`TeamsNotifier`, one encrypted `technician_teams_webhook_url`)
**+** an @mention/email to **one of two role users** resolved server-side from the event
`FlagAttentionCategory` (`TechnicianConfig::escalationRecipientFor`), and the **Plan-A poll**
(`OperatorBridgeToolExecutor::pollOperatorMessages` over the `operator_inbox` table) lets Chet
*pull* messages. The UI generalises this into a **per-destination registry**: each destination
has a delivery **type** (`webhook | poll | email`), an **address**, and an **event/category
filter** deciding which alert types route to it. Poll = how Chet consumes; webhook = instant
push for other integrations; email = fallback.

---

## 2. Current state — what exists vs. what's missing

### Tokens (minted/scoped today via CLI)
- **`McpStaffToken`** (`app/Support/McpStaffToken.php`) — value object: `allowedTools` (null ⇒
  legacy full-surface token), `label`; `allows($tool)`, `actorLabel()` (`mcp-staff:{label}`).
- **`McpConfig`** (`app/Support/McpConfig.php`) — `rotateStaffToken()` mints `psa-mcp-`+48 random,
  returns plaintext **once**; scoped records live as a JSON array in the encrypted Setting
  `mcp_staff_scoped_tokens` (**hash only**, sha256); rotating a label **replaces** the prior
  record; `resolveStaffToken()` matches by `hash_equals`. The **legacy** single token
  (`mcp_staff_token`) is stored *reversibly encrypted* (weaker than the scoped hash-only path).
- **`McpRotateStaffToken`** (`app/Console/Commands/…`) — `mcp:rotate-staff-token --tool=* --tools=
  --label= --force`; prints URL + token + tools + label, warns "will not be shown again."
- **`McpStaffController`** — per-token gating in `toolAllowed()`; bridge tools require a scoped
  token that explicitly allows them; audits to **`McpAuditLog`** / `mcp_audit_logs`
  (server_name, method, tool_name, redacted args, status, duration, `actor_label`, source_ip).

### The tool surface a token can be scoped to
- **`AssistantToolDefinitions::getTools()`** — general set (search_all_tickets, list_open_tickets,
  get_ticket_detail, propose_close, get_ticket_calls, get_queue_stats, find_clients, …),
  client-scoped set (search_tickets, create_ticket, add_ticket_note, get_client/person/asset,
  find_persons/assets), plus conditional integration tools (ninja/level/mesh/cipp) + DNS + wiki.
- **`OperatorBridgeTools::definitions()`** — bridge-only: `find_staff`, `get_staff`,
  `post_to_operator`, `poll_operator_messages` (never leak into the in-app assistant/teammate).
- Registries are **static code arrays** — they are the source of truth the UI's checkbox set
  must enumerate so it can never drift from what the boundary actually allows.

### Alerts (delivered today)
- **`EscalationNotifier::notify()`** — resolves recipient **server-side** from
  `FlagAttentionCategory` (agent supplies only a category, never a person), records state in
  `TechnicianRun->proposed_meta`, delegates to `OperatorDelivery`.
- **`OperatorDelivery::send()`** — three sinks, fail-soft per channel: (1) live bot proactive
  post w/ @mention to the configured escalation chat; (2) fallback **`TeamsNotifier`** webhook
  (single `technician_teams_webhook_url`); (3) **always also email** the recipient. `sanitize()`
  = cap 500 + `WikiRedactor::scan` + `TeamsText::escape`.
- **Plan-A poll** — `OperatorBridgeToolExecutor::pollOperatorMessages` over **`operator_inbox`**
  (conversation_id, sender_user_id, text, ts, direct_mention, authorized_steer, delivered_at) with
  a cursor/ack that self-heals a dropped wake; `post_to_operator` writes outbound.
- Event/alert types: **`FlagAttentionCategory`** (needs_decision / needs_hands_onsite /
  needs_overflow / uncertain / other) and **`OperatorMessageCategory`** (escalation /
  steer_request / daily_report / reply).

### What's MISSING (= what this feature adds)
- Token minting is **CLI-only**; no UI to **list / identify / rotate / revoke** scoped tokens,
  and no read method exposes the records for display (`scopedStaffTokenRecords()` is private).
- `McpAuditLog` has **no UI**.
- Alert delivery is **hard-coded**: exactly one webhook, a fixed category→2-role recipient map,
  and a single Chet poll table. There is **no destinations registry** — no way to add N webhooks,
  route "event-type X → destination Y", or register another poll/webhook consumer.
- **No RBAC anywhere.** Settings are gated by bare `auth` only; the `User` model has no
  role/`is_admin` (only `is_active`, `is_contractor`); no `Gate::define`/policies on settings.

### Conventions the new page must fit
- Pattern = dedicated `App\Http\Controllers\Web\*Controller` + Blade under `resources/views/
  settings/` + routes inside the `Route::middleware('auth')->group()` in `routes/web.php` + a nav
  link in the **Settings** group of `resources/views/components/sidebar.blade.php` (peers today:
  General, Staff, Integrations).
- Config storage: **`Setting`** model (`getValue/setValue`, `getEncrypted/setEncrypted` via
  `Crypt`, `settingOrConfig` fallback).
- **Secret-masking** convention (`IntegrationsController`): `SECRET_MASK = '••••••••'`; a blank or
  masked submit means "keep existing"; only a freshly typed value is validated + saved.
- **SSRF hardening** for operator-set webhooks: `SafeWebhookUrl` rule (save-time — https-only, no
  private/reserved/link-local/metadata, NXDOMAIN fails closed, via `SafeUrlInspector::reject`) +
  request-time peer-IP pin middleware (`TacticalClient::ssrfPinMiddleware`, reused by
  `TeamsNotifier`). **Reuse both** for every new webhook destination.

---

## 3. Design space + options (the load-bearing decisions)

### (a) Tenancy — per-MSP multi-tenant vs Sound IT admin-console-first (tenant-ready)
- **Reality:** the PSA is **single-tenant today** — one global `Setting` k/v store, one token
  set, one `operator_inbox`, no `tenant_id` anywhere, no RBAC.
- **Option A — full multi-tenant now:** every token/destination row carries an MSP scope; UI is
  tenant-aware. Large speculative lift; there is no tenancy primitive to hang it on.
- **Option B — admin-console-first, tenant-ready (RECOMMEND):** build for the single Sound IT
  install now, but make the data model *not hostile* to future tenancy — promote tokens &
  destinations to **first-class tables** (like `mcp_audit_logs`/`operator_inbox` already are)
  with a reserved, nullable `owner_scope`/`tenant_id` column, rather than JSON-blob-in-Setting.
- **Recommendation: B.** Cheap hedge, unblocks list/revoke/audit-join for free, no premature
  tenancy machinery. *Data-model impact:* new `mcp_tokens` + `alert_destinations` tables.

### (b) Token secret handling
- **Today:** scoped = **hash-only** (sha256, good); legacy = reversibly encrypted (weaker);
  plaintext returned once at mint.
- **Recommend: GitHub-PAT model** — one-time display at mint, **hash-only** at rest (adopt the
  scoped path universally), store a non-secret **display prefix** (e.g. `psa-mcp-abcd…`) +
  label + created_at + last_used_at for identification in the list. Never render a secret again;
  "lost it → rotate." Steer new tokens **off** the reversible legacy path.

### (c) Tool-scoping UI model
- **Recommend:** a **checkbox set generated from the live registries**
  (`AssistantToolDefinitions` + `OperatorBridgeTools`), grouped (general / client-scoped /
  integration-conditional / **bridge = sensitive**), each row showing the tool description; the
  selected names map directly to `McpStaffToken.allowedTools`. A "full-surface (legacy, no
  allowlist)" escape hatch maps to `null` but is discouraged/flagged. Registry stays the single
  source of truth so the UI can't drift from the boundary.

### (d) Alert-destination data model + how the core gets refactored
- **Recommend a `alert_destinations` table:** `id, label, type(webhook|poll|email), address,
  event_filter(JSON: category list | "all"), secret(nullable, masked/encrypted — webhook
  signing), enabled, created_at, last_delivery_at`.
- **Refactor:** `EscalationNotifier`/`OperatorDelivery` resolve the **set** of enabled
  destinations whose `event_filter` matches the event's category and **fan out** to them, instead
  of the hard-coded single-webhook path. The **poll** becomes a destination of `type=poll`
  (Chet's `operator_inbox`, keyed by the destination). **Invariant preserved:** category remains
  the only agent-supplied signal; destination matching stays 100% server-side.
- **Design tension to resolve (open Q):** the existing **category→role-user** recipient routing
  (who gets the @mention/personal email) is *semantically different* from "route event-type →
  destination." Does the destinations registry **replace** role-recipient routing, or **sit
  alongside** it (destinations = channels; role routing = the human to @mention)? Leaning
  *alongside* for v1 to avoid regressing the live escalation behaviour.

### (e) Admin-gating + audit
- **Today:** every authenticated staff user can open all Settings — but a token = programmatic
  PSA access + actions, and a webhook destination = a data-exfil surface. This page is **strictly
  higher-privilege** than the rest of Settings.
- **Recommend:** introduce a **minimal admin gate for this page** (likely the app's *first* RBAC
  — a `Setting`-based admin allowlist, or a new `is_admin`/policy). **Audit:** reuse
  `McpAuditLog` for token lifecycle (mint/rotate/revoke) + record destination changes; expose a
  read-only audit view.

---

## 4. Security considerations
- **Token exposure:** one-time display, hash-only at rest (§3b); never log or echo plaintext;
  CSRF on all mutations; rate-limit mint. Rotation invalidates the old secret immediately
  (already true via label-replace).
- **Who can access:** gate behind the new admin check (§3e), not bare `auth`. This is the single
  biggest *new* exposure — a self-serve token minter reachable by any staff login.
- **Webhook SSRF/exfil:** **reuse `SafeWebhookUrl` (save-time) + `ssrfPinMiddleware`
  (request-time)** for every webhook destination — non-negotiable; the "operator-set URL is
  attacker-influenceable" threat already drove `psa-ncl1`. Consider outbound **HMAC signing** so
  receivers can verify authenticity (open Q).
- **Injection / content safety:** keep the existing `WikiRedactor::scan` + `TeamsText::escape`
  output hygiene on anything an agent authored before it hits a destination.
- **Least privilege:** default new tokens to the *narrowest* useful tool set; make "full surface"
  a deliberate, flagged choice. Bridge tools (`post_to_operator`/`poll_operator_messages`) shown
  as sensitive.
- **Audit everything:** token mint/rotate/revoke + destination create/edit/disable, with actor +
  timestamp, via `McpAuditLog`.

---

## 5. Recommended shape + build sequence

**MCP token management FIRST, alert destinations SECOND** — and here's why:
- MCP mgmt is the **tighter core**: it's a UI over one class (`McpConfig`) with a clean existing
  hash-only path; the main new work is a table + list/mint/revoke + the registry-driven checkbox
  UI + the admin gate. Lower blast radius.
- It directly **unblocks minting the two least-privilege tokens** the Chet↔Teams "teams" pack
  needs (per `[[2026-07-01-gascity-chet-teams-bridge-design]]`): a **Chet token**
  (`find_staff`, `get_staff`, `post_to_operator`) and an **office-teams-pack token**
  (`poll_operator_messages`) — from the UI instead of SSH.
- Alert-destination mgmt is a **bigger refactor** (generalising `EscalationNotifier` +
  `OperatorDelivery` + the poll into a registry, without regressing the live escalation path), so
  it should ride on the settled token-mgmt patterns (table, masked secret, SSRF reuse, admin gate).

**Hard dependency vs. nice-to-have**
- *Hard (v1 core):* the admin gate; a real `mcp_tokens` table with list/mint(one-time)/revoke; the
  registry-driven tool checkbox; SSRF reuse for any webhook.
- *Nice-to-have (can defer):* in-UI audit viewer; `last_used_at`/usage stats; HMAC webhook
  signing; destination test-send button; multi-tenant scoping.

**NOT a blocker for the Chet↔Teams tokens.** `mcp:rotate-staff-token` works today, so the bridge
work is unblocked regardless — this UI is the durable, no-SSH replacement, not a gate on the trip.

---

## 6. OPEN QUESTIONS FOR CHARLIE
1. **Tenancy for v1** — confirm *admin-console-first, tenant-ready* (Option B), i.e. build
   single-tenant now but as real tables with a reserved scope column? Or is genuine multi-tenant
   in scope sooner than expected?
2. **Which alert/event types are configurable** — just `FlagAttentionCategory` +
   `OperatorMessageCategory`, or should other PSA events (new-ticket, intake, SLA breach, digest)
   become routable destinations too?
3. **Who can manage tokens/alerts** — this is likely the app's *first* RBAC. Owner-only? A
   `Setting`-based admin allowlist? A new `is_admin` flag on `User`? What's the minimum you want?
4. **Webhook signing/secrets** — do we sign outbound webhooks (HMAC) so receivers can verify, or
   is the secret-URL + SSRF-pin sufficient for v1?
5. **Deprecate the CLI or keep both** — retire `mcp:rotate-staff-token`, or keep it as the
   break-glass/automation path alongside the UI?
6. **Destinations vs. role-recipient routing (§3d tension)** — should the destinations registry
   *replace* the category→role-user @mention/email routing, or *complement* it (destinations =
   channels, role routing = the human to ping)?
7. **v1 scope line** — minimum lovable v1 = {admin gate + token list/mint/revoke + tool
   checkbox}? Is the in-UI audit viewer and destination editor v1 or v2?
8. **Poll destination auth** — does each poll consumer (e.g. the office pack) get its own
   token **and** its own destination/channel row, and should the UI mint the token + create the
   matching poll destination as one flow?

---

*Next step: run this through a brainstorm/panel review, fold Charlie's answers, then write the
implementation spec (MCP token mgmt first).*
