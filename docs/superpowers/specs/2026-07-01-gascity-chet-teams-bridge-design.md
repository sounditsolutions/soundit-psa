---
type: plan
title: "GC Chet ↔ Teams — Operator Escalation & Steer Round-Trip (Design)"
created: 2026-07-01
tags: [soundit-dev, gascity, chet, teams, design, review-me]
status: awaiting-review
related:
  - "[[soundit-dev]]"
  - "[[2026-06-30-gascity-chet-bootstrap-kit]]"
  - "[[ai-technician-project]]"
  - "[[teams-bot-live-2026-06-27]]"
---

# GC Chet ↔ Teams — Operator Escalation & Steer Round-Trip

**Purpose.** Wire GC Chet (the always-on agent in the **soundit-office** Gas City) into
Microsoft Teams as its human **escalation / ask-for-a-steer / daily-report** channel,
replacing today's bead-escalation stopgap. Reuse the PSA's *already-live* Teams machinery as
the trust anchor rather than building a second Teams integration.

**Provenance.** Brainstormed by the gascity Mayor with Charlie, 2026-07-01, grounded in the
live PSA source. Supersedes Chet's spec `so-0f5` (add Entra oid to `people`) — see §9 for why
that is not needed.

**Decisions locked in this session (Charlie):**
- Reuse the PSA bot as the trust anchor (not a separate Teams identity, not oid-on-people).
- **Chet owns the escalation chat**; the PSA-native teammate is muted there via a **reversible**
  routing flag (fallback preserved).
- Inbound follows a **three-tier "how people work" model** (direct / ambient / Haiku-filtered),
  event-driven into Chet's session — not Chet-cadence polling.
- Chet gets a read-only MCP tool to **query staff `Users` (incl. IDs) for verification**.
- **Scope = internal round-trip only.** Client-facing Teams sends stay deferred to Spike-2.
- The office-side bridge is a **Gas City pack modeled on the Discord pack** (a `teams` pack:
  gateway-poll → tier → wake → publish) — not an ad-hoc daemon.

---

## 1. Scope

**In scope (trip-critical):** the internal operator round-trip — Chet posts escalations,
asks for a steer, and files a daily report to Charlie/Justin; Charlie/Justin reply and Chet
reads the reply. Audience is authenticated **staff only**.

**Out of scope (deferred to Spike-2, unchanged):** client-facing Teams replies / `send_reply`.
Those remain held/gated and are not part of this work.

**Why this is the right cut:** it closes the trip-readiness gap "agent → Teams proactive
escalation (reach Charlie/Justin when stuck) + receive a steer back" with the smallest,
lowest-risk surface, and it keeps the PSA-native fallback intact.

---

## 2. Background — what already exists (source-verified 2026-07-01)

The Teams round-trip this feature needs is **mostly already built** in the PSA, for the
PSA-native AI Technician (Increments E + H):

- **`POST /api/mcp/staff`** (`McpStaffController`) — the JSON-RPC MCP server Chet already uses.
  Since PR #94 it supports **per-token tool scoping** (`McpStaffToken::allows()`).
- **Inbound sender auth** — `TeamsMessagesController` verifies the *signed Bot Framework
  request*, then `TeamsIdentityResolver` resolves `from.aadObjectId` → an **active PSA `User`**
  via `User.microsoft_id`, with a cross-tenant guard and **no shared-account fallback**, all
  **below the prompt stream**. Today it hands off to `TeamsReplyService` / `TeamsAmbientService`
  (the live `claude-teams-teammate` bot).
- **`User.microsoft_id` IS the Entra object id (oid)** — `AuthController` sets it from Microsoft
  SSO (`$microsoftUser->getId()`). Every staff SSO login populates it.
- **Outbound escalation** — `EscalationNotifier` (Increment H) posts proactively to the Teams
  escalation chat: resolves the recipient **server-side from a fixed enum** (the agent cannot
  redirect delivery), `@mention`s via `microsoft_id`, output-scans (`WikiRedactor`) and escapes
  (`TeamsText`) all untrusted text, with webhook + email fallbacks.

**The gap:** GC Chet lives in a *separate city* and reaches the PSA **only** via `mcp/staff`
(a pull channel). It has no inbound endpoint, and today it escalates by filing a bead in the
office city that a human must notice. Nothing bridges the PSA's live Teams layer to Chet.

---

## 3. Architecture

**Model.** PSA = system of record + trust anchor + gated tool surface. A new **`teams` Gas City
pack** in the office city = the bridge (gateway-poll → tier → wake → publish), modeled directly on
the existing **Discord pack**. GC Chet = the brain.

```
OUTBOUND  (Chet → operators)
  Chet runs `gc teams reply-current --body-file` (pack cmd) → pack calls:
  post_to_operator(category, message, ticket_id?)          [MCP, bearer-authed]
    → PSA resolves recipient SERVER-SIDE from category (Chet can't redirect delivery)
    → WikiRedactor scan + TeamsText escape  (reuse EscalationNotifier's delivery core)
    → TeamsBotClient posts to Chet's chat, @mention via User.microsoft_id
    → audited to McpAuditLog             [replaces the bead-escalation stopgap]

INBOUND   (operators → Chet)
  Human posts in Chet's chat
    → TeamsMessagesController (verifies SIGNED Bot Framework request)
    → TeamsIdentityResolver → active User    (below Chet's prompt stream)
    → routing flag = Chet  → SKIP TeamsReplyService auto-reply
    → append to operator_inbox; allowlisted sender ⇒ authorized_steer=true
  `teams` pack gateway service (office city) polls poll_operator_messages(cursor) ~10s
    → DIRECT @mention/DM  → room-launch wakes Chet's session (pipe message)
    → AMBIENT chatter     → ~5-min batched digest nudge ("(N) new chatter msgs…")
    → HAIKU filter (tunable) → promote a chatter msg to "Potentially actionable…"
    → idempotent: re-pipes unacked msgs next tick (self-heals a dropped wake)

VERIFICATION  (Charlie's requirement)
  Chet.find_staff(query) / get_staff(id)
    → staff Users incl. id, name, email, microsoft_id(oid), active
    → lets Chet know its operators + cross-check a server-vouched steer sender
```

**Load-bearing boundary property.** Every **authorization** decision — who sent it, are they an
allowlisted operator, does the tenant match — is made **server-side by the PSA, below Chet's
prompt stream**. The `teams` pack pulls those verdicts over the authenticated bearer channel; Chet
receives the piped content in its prompt stream and — for any consequential steer — re-confirms the
sender via its own authenticated `find_staff` call, never authorizing off prompt-stream text alone.
**"Push to wake, pull to trust":** the pack's pipe delivers content and wakes Chet fast, but before
Chet acts on a consequential steer it re-confirms the sender through the authenticated MCP channel. Because every real action stays **held/gated in the
cockpit**, even a spoofed pipe cannot cause autonomous harm (worst case: a held proposal a human
approves). This is the direct application of the `so-bmb` lesson (forged-mayor prompt-stream
inject).

---

## 4. Components

### 4.1 New MCP tools (added to `mcp/staff`, per-token scoped like PR #94)

*(The `teams` pack (§4.4) is the primary MCP client for the chat plumbing — its gateway polls
`poll_operator_messages` and its reply/publish commands call `post_to_operator`. Chet uses
`find_staff` directly for verification.)*

1. **`post_to_operator(category, message, ticket_id?)`** — outbound escalation / ask-for-steer /
   daily report. **Reuses `EscalationNotifier`'s delivery core** (server-side recipient routing
   from the category enum, `WikiRedactor` scan, `TeamsText` escape, `TeamsBotClient` post with
   `@mention`) **but without requiring a `TechnicianRun`** — GC Chet has no run record, and the
   `proposed_meta`/escalation-sweep state machine is PSA-native-only. Factor the delivery core out
   of `EscalationNotifier` (or add a sibling) so both callers share the scan/escape/post path.
   Audited to `McpAuditLog`.

2. **`poll_operator_messages(cursor)`** — inbound pull; the "pull-to-trust" half. Returns the
   PSA-validated, **allowlisted** operator messages for Chet's conversation since `cursor`, each
   carrying the **server-resolved sender** (User id + name) and a `direct_mention` flag; advances
   the cursor / acks delivery. Consumed by the `teams` pack gateway (§4.4), not by Chet directly.

3. **`find_staff(query)` / `get_staff(id)`** — read-only staff-`User` lookup returning `id`,
   `name`, `email`, `microsoft_id` (oid), `is_active` (and role if cheap). Distinct from the
   existing `find_persons`/`get_person`, which read the `people` (contacts) table. Supports
   operator awareness + steer-sender cross-checking.

### 4.2 PSA-side inbox queue (`operator_inbox`)

When `TeamsMessagesController` resolves an inbound message for **Chet's conversation** and the
routing flag is on, append a row: `{conversation_id, sender_user_id, text, ts, direct_mention,
authorized_steer, delivered_at:null}`. **`authorized_steer` is true only for allowlisted senders
(Charlie/Justin)** — other resolved staff chatter is context Chet can *see* but must never treat
as an authorized steer. Unresolved, deactivated, or cross-tenant senders are acked to Teams but
not enqueued; the resolver's null result remains a hard "do not act" boundary below Chet's prompt
stream. The native teammate stays uniformly muted in Chet's chat when routing is on (§4.3), so all
resolved messages route here, giving Chet full conversational context from vouched staff.
`poll_operator_messages` reads undelivered rows and marks them delivered on ack. Re-delivering
unacked rows is what makes a dropped wake self-heal.

### 4.3 Reversible conversation-routing flag

In `TeamsMessagesController`: if the conversation is **Chet's chat** *and* routing is enabled,
**skip** `TeamsReplyService` auto-reply and enqueue to `operator_inbox` instead. **Default off**
(exactly today's behavior). This is a guarded, per-conversation switch — **no destructive change
to the live teammate path.** Flipping it off instantly restores the native teammate in that chat
(the trip fallback).

**One bot identity, routed by conversation.** Chet has **no separate Teams registration** — it
reuses the existing PSA bot (App ID). There is a single bot in Teams; this per-conversation flag
decides whether the PSA-native teammate or GC Chet services a given chat. **Chet's chat is a
configured conversation id** (extend `TeamsBotConfig`). A **direct mention** is that bot being
`@`-mentioned within Chet's chat — detected from the Bot Framework mention entities; anything else
in the chat is ambient chatter.

### 4.4 The `teams` Gas City pack (office city — modeled on the Discord pack)

The bridge is a **Gas City pack**, structured like the existing Discord pack (same `pack.toml`
schema-2 `proxy_process` service model, plus `commands/`, `doctor/`, `scripts/`,
`template-fragments/`, `tests/`, and a `config.json` of **bindings + policy**). It is installed in
**soundit-office** and runs there, so all session access stays local to the office city.

**What maps 1:1 from Discord:**
- **Binding** — `config.json` maps Chet's Teams `conversation_id` → `session_names: ["chet"]`
  (Discord's `chat.bindings` model). This is how the pack knows which session to wake.
- **Policy = the tiers** — the direct/ambient tiers *are* Discord's policy knobs:
  `broadcast_mentions_enabled` (direct wake), `ambient_read_enabled` + a digest window (ambient
  tier), and rate-limits. Add one new knob — `relevance_filter` (the Haiku pass) with an enable
  flag + threshold — the same lever used to tune PSA Chet's `SignificanceGate`.
- **Room-launch** — a `teams_room_launch.py` wakes the bound session on a direct mention (mirrors
  `discord_room_launch.py`); the office `boot` re-wake is the backstop for the known wake-submit
  stall. Idempotent: only ack a message after the wake is confirmed; re-pipe unacked ones next tick.
- **Agent-facing reply** — Chet replies with `gc teams reply-current --body-file <path>` (mirrors
  `gc discord reply-current`); the pack's publish script calls `post_to_operator`. A
  `template-fragments/teams-v0.template.md` teaches Chet the channel doctrine (when to respond,
  AI disclosure, push-to-wake/pull-to-trust) exactly as `discord-v0` does.
- **Doctor + tests** — `check-gc` / `check-jq` / `check-python` + a `check-psa-mcp-reachable`;
  pytest over the gateway loop, tiering, room-launch, and publish.

**The one true difference — transport.** Discord's `gateway_service` holds a **websocket** (Discord
*pushes* events). Teams delivers to the **PSA** bot endpoint, not the pack — so the `teams` pack's
`gateway_service` **polls the PSA's `poll_operator_messages` MCP tool (~10s)** instead of holding a
socket, and **publishes via `post_to_operator`** instead of a chat API. The pack stays
**transport-thin**: all Teams-protocol specifics (Bot Framework validation, identity resolution,
the actual post) live in the PSA — the trust anchor. ~10s is effectively immediate for chat, needs
no inbound hole into the office, and reuses the proven office→PSA path. (Sub-second PSA→office push
is a later upgrade, not built now.) The wake carries content for immediacy; it is **not** the
authorization source (§3).

**Pack authentication.** The pack holds its own least-privilege `mcp/staff` token in the pack
`secrets/` dir (analogous to Discord's `secrets/bot-token.txt`), scoped to just the plumbing tools
(`poll_operator_messages`, `post_to_operator`). Chet's *own* token keeps the read set + `find_staff`
(§5).

---

## 5. Security invariants

- **Authorization is server-side only.** `TeamsIdentityResolver` (signed BF request → active User,
  cross-tenant guarded) + the operator allowlist decide who may steer. Chet never authorizes off
  prompt-stream text.
- **Operator allowlist** = an explicit set of staff `User` IDs (Charlie, Justin); enforced
  server-side, it sets `authorized_steer` on each `operator_inbox` row. Only those messages may be
  acted on as steers. Matching *a* user ≠ authorized.
- **All Chet-authored outbound text** is output-scanned (`WikiRedactor`) and Teams-escaped
  (`TeamsText`) before any post — reuse `EscalationNotifier`'s core; never post raw model text.
- **Everything stays held/gated.** No autonomous client-facing action; client sends are out of
  scope. A steer changes Chet's *judgment*, never its *authority to execute*.
- **Least-privilege tokens (two).** The `teams` pack holds its own token scoped to just the
  plumbing (`poll_operator_messages`, `post_to_operator`). **Chet's** own token keeps the read set +
  `find_staff`/`get_staff`. **Neither** carries `send_reply` (Spike-2) or `create_ticket`-class
  writes it does not need.
- **Audit.** Every tool call → `McpAuditLog` (already automatic on the boundary).

---

## 6. Trip-safety & reversibility

- The native teammate reply path is **untouched in code**; the routing flag is **default-off** and
  per-conversation. Flip it off → the native teammate resumes Chet's chat immediately.
- **If GC Chet or the bridge goes dark:** flip routing off (native teammate covers the chat); the
  ticket-working fallback (PSA-native Chet's held proposals in the cockpit) is entirely unaffected.
- Ships **dormant**: PSA tools land behind the token scope + routing flag; nothing changes for live
  users until Chet's token is scoped up and the flag is enabled for its chat.

---

## 7. Testing

- **`post_to_operator`** — recipient resolved server-side from category (caller cannot redirect);
  output-scan strips a violation to the safe placeholder; `TeamsText` escaping neutralizes a
  markdown-link / `<at>` injection; works with no `TechnicianRun`.
- **`poll_operator_messages`** — returns only validated + allowlisted rows; carries the resolved
  sender; cursor/ack advances; unacked rows re-deliver.
- **`find_staff`/`get_staff`** — returns oid; respects active/inactive; does not leak `people`.
- **Routing flag** — on: teammate auto-reply skipped + row enqueued; off: unchanged passthrough
  (regression guard for the live teammate).
- **Allowlist** — a non-allowlisted resolved sender is not enqueued as an actionable steer.
- **`teams` pack (gateway)** — direct vs ambient tiering; Haiku promotion; idempotent re-pipe on
  unacked; digest batching window; publish → `post_to_operator`.
- **End-to-end** — mention → wake → Chet reads via MCP → `post_to_operator` reply appears in the
  chat. Spoofed-pipe → Chet re-verifies via the authenticated channel → no autonomous action.

---

## 8. Build sequencing & dispatch

Naturally phased across two build locations:

1. **PSA-side (dispatch to `developer` on codex; Mayor reviews + merges; ships dormant):**
   1. **`post_to_operator`** first — it alone replaces the bead-escalation stopgap and is the
      lowest-risk piece (outbound, internal, reuses the audited delivery core).
   2. `operator_inbox` + routing flag + `poll_operator_messages`.
   3. `find_staff` / `get_staff`.
2. **Office-side (the `teams` pack):** author the pack on Discord-pack conventions (`pack.toml`
   services, `scripts/` gateway + room-launch + publish, `config.json` bindings+policy,
   `template-fragments/teams-v0`, `doctor`, `tests`), then install it in soundit-office and bind
   Chet's chat. Tiers + Haiku filter. (Owner: office mayor / office developer, or Mayor IC.)
3. **Enable:** scope Chet's token up → turn on the routing flag for Chet's chat → soak.

The `writing-plans` step will turn this into task-level TDD steps; the PSA-side and office-side
pieces may become two implementation plans sharing this spec.

---

## 9. Appendix — why `so-0f5` (oid on `people`) is not needed

`so-0f5` proposed storing the Entra oid on **`people`** (contacts) and exposing it so Chet could
match `oid ↔ oid` itself. It targets the wrong table and the wrong layer for the steer channel:

- **Wrong table.** Steer/approval authority belongs to Charlie/Justin **as staff `Users`**, and
  `User.microsoft_id` already holds their oid. The `people` table is the contacts directory; they
  appear there only as contacts of the "Sound IT" client. `TeamsIdentityResolver` already matches
  the inbound `aadObjectId` → the active `User` via that oid, below the prompt stream.
- **Wrong layer.** Chet must never match identities from its prompt stream (the `so-bmb` lesson).
  Authorization is decided server-side and vouched over the authenticated channel; `find_staff`
  covers Chet's *verification* need without any schema change.
- **No unlock.** Even authenticating a *client contact* would unlock nothing, because
  client-facing actions stay held/gated regardless.

**Recommendation: close `so-0f5`** with a pointer to this spec (`find_staff` is the sanctioned
verification surface).

---

## 10. Open items for the plan

- **`teams` pack home:** author it upstream in `gastownhall/gascity-packs` (general/reusable — the
  PSA endpoint + tool-names become config) vs. a local office-only pack. Lean: build local first,
  upstream once proven.
- Which pack services to declare (likely just a private `teams-gateway`; maybe a `teams-admin`/status).
- Final `find_staff` field set (role? last-login?).
- Poll interval (default ~10s) and Haiku-filter prompt/threshold tuning.
- Daily-report content + cadence (once/day summary via `post_to_operator`).
- Whether the two build locations are one plan or two.
