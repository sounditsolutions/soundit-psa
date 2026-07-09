---
type: design
title: "Client-Facing PSA Teams Agent — Scope & Design (Direction 2)"
created: 2026-07-09
tags: [soundit-dev, psa, teams, mcp, client-portal, agent, design, review-me]
status: awaiting-review
bead: psa-vei
related:
  - "[[2026-07-01-gascity-chet-teams-bridge-design]]"   # Direction 1 (staff-facing round-trip)
  - "psa-hbh"   # Authorization middleware / staff role model (closed)
  - "psa-v9w"   # Private/confidential tickets in portal (open) — hard dependency for confidential scoping
  - "psa-slf"   # CIPP write tools w/ confirmation UI (closed) — the remediation surface we deliberately DON'T expose
---

# Client-Facing PSA Teams Agent — Scope & Design (Direction 2)

**Purpose.** Decide what a **client-facing** PSA Teams agent should and should not do, before any
code is written. A client contact — an end user at one of the MSP's customers — opens Teams in
*their own* M365 tenant and talks to a PSA-aware AI: "what's the status of my ticket?", "my Outlook
won't open", "I need a new laptop". This is **Direction 2** of the M365 agent initiative. Direction
1 (staff-facing: the operator escalation / steer round-trip) is already designed and partly live —
see `2026-07-01-gascity-chet-teams-bridge-design.md`.

**This is a decisions document, not an implementation plan.** It answers the eight questions on
bead `psa-vei` with a recommendation for each, grounded in the PSA source as it stands on
2026-07-09. Where it names a class, method, table, or route, that symbol was read in the tree, not
assumed. A follow-up `writing-plans` pass turns the chosen cut into TDD task steps.

**The one-sentence thesis.** *A client-facing agent is the **client portal expressed as a
conversation** — so it must inherit the portal's single hard-won safety property (a Person sees
exactly one Client's data and nothing else, enforced server-side below the prompt stream) and add
nothing that the portal itself doesn't already let that contact do.*

---

## 1. Scope — the core cut

**In scope (the recommended first cut):** a **read-only, single-client Q&A + ticket-intake** agent.
It can answer questions about *the contact's own* tickets, devices, and service agreements, and it
can **open a new ticket** (the one write a portal user can already perform). Audience: authenticated
**client contacts**, each pinned to exactly one Client.

**Explicitly out of scope (this cut):** any remediation or M365 action (password resets, MFA
changes, license grants — see D1), any cross-client visibility (D3), any ambient/unprompted chatter
(D6), and any autonomous action that a human wouldn't approve if it were a portal button.

**Why this cut.** It maps 1:1 onto capabilities the **client portal already ships and already
authorizes** (`routes/portal.php`, guarded by `PortalAuthenticate` + `PortalClientScope`). Every
tool the agent gets is a capability a logged-in contact could already exercise in the browser — so
the agent introduces a new *interface*, not a new *authority*. That keeps the liability and
blast-radius review small and lets us ship something useful without waiting on the confidential-
ticket work (psa-v9w) or a client-side authorization layer that doesn't exist yet.

---

## 2. Background — trust anchors that already exist (source-verified 2026-07-09)

The client-facing agent should be assembled almost entirely from machinery the PSA already owns.
Inventory:

### 2.1 The client portal is the authorization model, already built
- **`PortalClientScope`** (`app/Http/Middleware/PortalClientScope.php`) is the whole ballgame in 15
  lines: it reads `Auth::guard('portal')->user()`, **aborts 403 if the Person has no `client_id`**,
  and stamps `portal_client_id` / `portal_person` onto the request. Every portal query is scoped by
  that attribute. This is exactly the property a client-facing agent needs — *resolve the client
  server-side, once, and pin every downstream read to it.*
- **`Person`** (`app/Models/Person.php`) belongs to **exactly one** Client (`client_id`,
  `client()` BelongsTo). Portal fields: `portal_enabled`, `company_wide_access`, `canAccessPortal()`,
  `scopePortalEnabled()`. A contact with `company_wide_access` sees the whole company's tickets; one
  without sees only their own. **The agent must honor this same split**, not invent its own.
- **Note visibility** is already solved: `TicketNote::scopePortalVisible()` returns `is_private =
  false` AND excludes system-generated note types (AiTriage, System, StatusChange, Escalation). The
  agent must read notes **through this scope**, never raw.
- **The one existing write:** `TicketService::addPortalReply(Ticket, Person, body)` (author_id
  null, `who_type = EndUser`, `note_type = Reply`) and portal ticket creation
  (`TicketSource::Portal`, urgency → P3/P2). These are the only writes a contact can do today; they
  bound what the agent may do.

### 2.2 The Teams identity + trust plumbing is built (for staff — and generalizes)
- **`TeamsMessagesController`** + **`VerifyBotFrameworkJwt`** verify the *signed* Bot Framework
  request and surface the JWT `aud` as the `teams_bot_app_id` request attribute.
- **`TeamsIdentityResolver::resolve()`** (`app/Services/Teams/TeamsIdentityResolver.php`) is the
  reference implementation of "resolve the human below the prompt stream, fail closed": it asserts
  signed-aud == recipient App ID (routing-spoof guard), enforces a **cross-tenant guard**, then maps
  `from.aadObjectId` → an **active** record with **no shared-account fallback** (unknown /
  deactivated / cross-tenant ⇒ `null` ⇒ hard "do not act"). Today it resolves to a staff `User`;
  Direction 2 needs the **same function shape resolving to a `Person`** (see D2/§Appendix).
- **Multi-tenant is already first-class.** `TeamsBotConfig::appIds()` is a **SET**, and
  `TeamsBotConfig::forAppId()` returns `{app_id, tenant_id, persona_key}` per registered bot, folding
  in every enabled `teams_personas` row (`TeamsPersonaConfig`). Per-tenant bot registration bound to
  a tenant id is a **storage change, not a code change** — this is the seam D4 builds on.

### 2.3 The MCP tool surface + per-token scoping is built
- **`POST /api/mcp/staff`** (`routes/api.php:52` → `McpStaffController`, behind
  `VerifyMcpStaffToken`) is a JSON-RPC MCP server with **per-token tool allowlists**
  (`McpStaffToken::allows()`), an **AI-actor** flag, a **`requireExplicitClientScope`** flag that
  forces a `client_id` argument on designated write tools (`EXPLICIT_CLIENT_SCOPE_WRITE_TOOLS`), and
  **`McpAuditLog`** on every call. This is the pattern to fork — but note the crucial inversion in
  D3: staff tokens let the *caller specify* a client; a client token must have the client **pinned
  from the resolved Person and un-overridable**.

### 2.4 Output-safety primitives are built
- `WikiRedactor` (output scan), `TeamsText` (Teams escaping / `<at>` + markdown-link neutralization),
  `ChetDataSurfaceTextSanitizer` / `OperatorBridgeTextSanitizer` (per-sink redaction). Any
  model-authored text posted back to Teams goes through scan-then-escape — never raw.

**The gap.** There is **no client-facing endpoint** (only `mcp/staff`), the identity resolver
targets `User` not `Person`, and there is no client-scoped, client-pinned MCP token type. Those three
are the net-new pieces; everything else is reuse.

---

## 3. The eight decisions

### D1 — Agent powers: **read-only Q&A + ticket intake. No remediation. Ever, in this direction.**

Recommended capability ladder, and where to stop:

| Level | Capability | Verdict |
|------:|------------|---------|
| 0 | Read the contact's own tickets / devices / agreements; answer questions | **Ship (phase 1)** |
| 1 | **Create** a ticket (`TicketSource::Portal`, urgency→P3/P2) | **Ship (phase 1)** — it's the portal's existing write |
| 2 | **Reply** to a ticket the contact is on (`addPortalReply`) | **Ship (phase 2)** — same authority as the portal reply box |
| 3 | Update ticket fields (title/priority/status) | **Defer** — the portal doesn't let contacts do this; don't grant it via chat |
| 4 | Trigger remediation (CIPP reset password / MFA / license — see psa-slf) | **Never (this direction)** |

**Rationale.** Levels 0–2 are the portal's own authority surface, re-expressed. Level 4 is the
bright line: `psa-slf` (closed, GitHub #151) *does* add CIPP write operations — but deliberately
**behind a staff admin confirmation UI**. Putting a tenant-changing action (reset a user's password)
behind an *end user's* unauthenticated-to-the-target-tenant chat message, mediated by an LLM, is a
liability and security posture we should not take on. If a client wants a password reset, the agent's
job is to **open a ticket** (Level 1), not to perform it. Remediation stays staff-gated with a human
in the loop, exactly as psa-slf built it.

**Corollary:** the client token carries **no** action-class tools (`propose_close`, `send_reply` in
the staff sense, CIPP writes). Its allowlist is `get_*`/`list_*` reads + `create_ticket` +
(phase 2) `reply_to_own_ticket`, and nothing else. Least privilege by construction.

### D2 — Identity model: **`from.aadObjectId` → `Person.cipp_user_id`, verified equal, fail closed.**

**The equality holds and is verifiable (not assumed):**
- Teams delivers the sender's Entra directory object id as `from.aadObjectId` (the staff resolver
  already keys on it).
- `CippContactSyncService` stores each M365 user's Graph `user.id` — the **same** directory object
  id — as `Person.cipp_user_id` (`$userData['id']` at `CippContactSyncService.php:98` → persisted to
  `cipp_user_id`). Graph `user.id` **is** the Entra object id for a member.
- Therefore, for a CIPP-synced contact, `Teams from.aadObjectId == Person.cipp_user_id`. This is the
  contact-side twin of the staff-side `User.microsoft_id == aadObjectId` that E1 already relies on.

**So the resolver is a near-clone of `TeamsIdentityResolver`, with two changes:**
1. Terminal lookup: `Person::where('cipp_user_id', $aad)->active()->portalEnabled()->first()` instead
   of `User::where('microsoft_id', $aad)`. Same "no fallback, null ⇒ do-not-act" contract.
2. Tenant binding: the bot's `tenant_id` (from `forAppId()`) must equal the activity's tenant **and**
   the resolved Person's Client must be the Client mapped to that tenant (D3/D4). Two independent
   checks, both server-side.

**Fallbacks and edge cases (decide now):**
- **Manually-entered contacts have `cipp_user_id = null`.** They will not resolve by object id. Do
  **not** silently fall back to email match for authorization (email is spoofable relative to the
  directory, and a null-cipp person hasn't been proven to live in the mapped tenant). Instead: an
  unresolved-but-in-tenant sender gets a polite "I can't verify your account yet — I've let your IT
  team know" and the event is audited for staff to link the contact. (An explicit, staff-driven
  "link this Teams user to this Person" is the clean path; object-id linking is the enrollment step,
  not a guess.)
- **`portal_enabled = false`** ⇒ does not resolve (mirror `scopePortalEnabled()`); a contact who
  can't use the portal can't use its conversational form either.
- **`is_active = false`** ⇒ does not resolve. Deactivation (including CIPP `accountEnabled=false`
  sync, which already removes contract assignments + disables portal) instantly de-fangs the agent.

**Why not store the oid on `people` separately (à la the retired `so-0f5`)?** No need —
`cipp_user_id` already *is* the oid. The staff-side spec §9 retired `so-0f5` for the same reason on
the `Users` side; the symmetry is intentional.

### D3 — Authorization scope: **client isolation is `PortalClientScope` moved below the tool layer, and the search-leak trap is the thing to get right.**

The rule: the resolved Person's `client_id` is **pinned server-side onto the MCP request** (exactly
as `PortalClientScope` stamps `portal_client_id`) and **every tool** filters by it. The client token
is `requireExplicitClientScope`-style, but **inverted**: the caller may not *supply* a client — the
server *injects* it from the resolved identity, and any `client_id` argument in the model's tool call
is **ignored/rejected**. The agent literally cannot express a cross-client query.

**The subtle leak the bead flags — ticket *subjects in search results* — is the real design work.**
A naive "search_tickets(query)" that the staff MCP exposes is client-scoped by a *caller-supplied*
`client_id`; for the client agent that argument must be server-pinned, and additionally:
- **Search is scoped to the contact's own visibility, not just their Client.** A contact **without**
  `company_wide_access` may only see tickets they are the contact on (or authored notes on) — the
  same split the portal enforces. So the scope is `client_id = pinned AND (company_wide_access ? all
  : contact_id = person.id)`.
- **Forward-compatibility with psa-v9w (confidential tickets, open).** psa-v9w adds a `confidential`
  flag so only the assigned contact sees a ticket even within a company-wide-access company. The
  agent's read scope **must compose with that filter the moment it lands** — meaning the agent should
  read through a **single shared "portal-visible tickets for this Person" query builder**, not
  re-implement scoping. Recommendation: **extract that scope now** (e.g. a `Ticket::scopePortalVisibleTo(Person)`)
  and have both the portal controllers and the agent tools consume it, so psa-v9w only has to change
  one place. This is the highest-leverage refactor this initiative motivates.
- **No free-text leakage in errors.** "Ticket 1234 not found" vs "not yours" must be indistinguish-
  able to the caller — a not-owned ticket is simply *not found*. (The staff controller's
  `ticketBelongsToClient()` is the shape; the client version returns a uniform not-found.)

### D4 — Deployment model: **one multi-tenant bot registration, per-tenant *records*, reusing the persona seam. Not one app per client, not one shared app.**

The infrastructure already chose this for us. `TeamsBotConfig::appIds()`/`forAppId()` and the
`teams_personas` table already support **N registered bots, each bound to a tenant id and a persona
key**, with identity resolution keyed off the *signed* App ID. The clean model:
- **Per client tenant: one bot App ID + Entra app registration, bound to that tenant, mapped to that
  Client.** This gives a **hard trust boundary per client** (a bot credential is scoped to one
  tenant; a compromise doesn't cross tenants) *without* per-client code — it's a
  `teams_personas`-style row plus a `client_id` mapping.
- The alternative — a single shared multi-tenant app installed into every client tenant — collapses
  the trust boundary (one credential reaches all tenants) and makes the "which Client is this?"
  resolution depend entirely on the tenant claim. Given the machinery already supports per-tenant
  registration cheaply, **take the stronger boundary.**
- Concretely: extend the tenant→Client mapping (the CIPP integration already maps
  `clients.cipp_tenant_domain`; reuse/extend that) so `forAppId()`'s `tenant_id` → Client is a
  lookup, and the resolver asserts *Person.client_id == that Client*. Defense in depth: the bot is
  bound to the tenant, and the Person is bound to the Client, and they must agree.

**Ops cost acknowledged:** per-tenant registration is more onboarding steps (D8). That's the price of
the boundary, and it's automatable (the manifest + registration can be scripted per tenant).

### D5 — Branding: **neutral "helpdesk agent" identity by default, per-persona override — carried on the existing `teams_personas` row.**

- Default to a **neutral support identity** ("IT Support Assistant"), not the MSP's brand and not the
  client's — the agent speaks *on behalf of the MSP's helpdesk to that client*, and a neutral name
  ages best across many client tenants.
- Because deployment is per-tenant (D4), the manifest (name, icon, description) is **per registration**
  already — so per-client branding is free if a client wants their own name/logo. Store it alongside
  the persona/tenant row; no schema drama.
- **AI disclosure is mandatory** in the manifest description and in a first-touch message ("You're
  chatting with an AI assistant; a human can take over any time by …"), mirroring the `teams-v0`
  channel-doctrine template the staff bridge uses. This is both good practice and a liability hedge
  (D7).

### D6 — LLM cost model: **hard per-tenant budget + no ambient chime for clients + strict cooldown. Client cost is a first-class control, not an afterthought.**

Staff usage is bounded by headcount; **client usage scales with client headcount × engagement**, so
cost control must be structural:
- **No ambient/unprompted chime-in for client bots — ever.** The staff bridge's `ambientEnabled()` is
  a second dormancy layer defaulting OFF; for client bots it's **not even wired**. The agent responds
  **only** to a direct message / @-mention. This removes the biggest cost-balloon vector the bead
  calls out (chime-in in group chats).
- **Per-tenant token/turn budget** with a daily ceiling (model on `TriageConfig::dailyTokenLimit()` /
  `maxTokensPerRun()`, which already exist for triage). On breach: the agent degrades to "I'm at
  capacity right now, I've opened a ticket for you" (which is a *useful* fallback, not just a wall).
- **Per-conversation cooldown / rate-limit** (reuse the `ambientCooldownSeconds` idea as a hard
  per-user rate limit; the MCP route is already rate-limited — extend that).
- **Pricing pass-through is a business decision, surfaced by data:** every turn is already auditable
  via `McpAuditLog` + the AI client's cumulative token tracking; aggregate per Client for a
  cost-to-serve report so the MSP can bill or cap per client. **Recommendation:** make the budget a
  per-Client setting so the MSP can offer it as a paid tier.

### D7 — Liability / "I don't know": **refuse-and-escalate with a category deny-list. The safe default is always "I'll open a ticket."**

- **Default fallback is a ticket, not a guess.** When the agent lacks an answer, or the request is
  outside its competence, it **creates a `TicketSource::Portal` ticket** summarizing the ask and
  tells the user "I've opened ticket #… and a technician will follow up." This turns "I don't know"
  into the system's happy path (intake), not a dead end.
- **Category deny-list (hard refuse + escalate):** anything security/account-sensitive (password,
  MFA, account lockout, suspected compromise, data-loss, legal/HR) → the agent **must not attempt an
  answer or action**; it opens a ticket and, for security keywords, flags priority. (The triage
  `JunkDetector` already has a security-keyword allowlist that prevents auto-closure; reuse that
  keyword set as the deny-list source of truth so "security-sensitive" means one thing app-wide.)
- **Never fabricate.** Output scanning (`WikiRedactor`) + a system-prompt boundary ("only state facts
  returned by a tool; if a tool didn't return it, say you'll open a ticket"). The agent is grounded
  in tool results only — it is not a general knowledge base about the client's environment.
- **Human handoff is one message away** and disclosed up front (D5).

### D8 — Onboarding: **staff-driven, scripted, per-tenant — with a self-service enrollment tail for the *contact*, not the *tenant*.**

Two different "onboardings" — don't conflate them:
1. **Tenant onboarding (MSP-driven, per client):** register the per-tenant bot/Entra app (D4), map
   `tenant_id → Client`, build + sideload the Teams app package into the client tenant. This is an
   MSP admin task (it touches the client's M365 tenant and needs their admin consent) and should be
   **scripted** (the staff-side `deploy/` + `teams-app/build_package.py` pattern from psa-e2e is the
   starting point) and **documented as a runbook**. Not self-service — it needs tenant-admin consent.
2. **Contact enrollment (per user, lightweight):** once the bot is live in a tenant, an individual
   contact's first interaction resolves via `cipp_user_id` (D2). If they're CIPP-synced and
   portal-enabled, they just work. If not, the "I can't verify you yet, told your IT team" path (D2)
   plus a staff **"link Teams user → Person"** action (one click in the existing client-portal
   management UI at `/clients/{client}/portal`) completes enrollment. Adding/removing a contact is
   then the same toggle that governs portal access today (`portal_enabled`) — **one control plane for
   portal and agent**, which is the whole point of D1's "same authority" framing.

---

## 4. Recommended architecture (synthesis)

```
CLIENT CONTACT (in their own M365 tenant)
   │  DM / @mention  (NO ambient chime — D6)
   ▼
Bot Framework  ──signed JWT──►  PSA: TeamsMessagesController + VerifyBotFrameworkJwt
   │                                     │ (aud == recipient App ID; cross-tenant guard)
   │                                     ▼
   │                         PortalTeamsIdentityResolver  ── from.aadObjectId ──►
   │                                     │   Person::cipp_user_id, active, portalEnabled   [D2]
   │                                     │   null ⇒ hard do-not-act + audit (no fallback)
   │                                     ▼
   │                         client_id PINNED server-side (from Person)  [D3 — PortalClientScope,
   │                                     │                                    moved below the tools]
   │                                     ▼
   │                         Agent turn (AiClient tool loop) with a CLIENT-PINNED MCP token:
   │                            allowlist = get_/list_ reads + create_ticket (+ reply, phase 2)  [D1]
   │                            every read → Ticket::scopePortalVisibleTo(Person)  [D3, psa-v9w-ready]
   │                            client_id arg from model = IGNORED (server injects)  [D3]
   │                            budget/cooldown enforced; McpAuditLog on every call  [D6]
   │                                     ▼
   │                         answer OR "I've opened ticket #… "  (fallback = intake)  [D7]
   ▼                                     │
Bot post  ◄── TeamsText escape ◄── WikiRedactor scan ◄──┘   (never raw model text)  [§2.4]
```

**Net-new components (everything else is reuse):**
1. `POST /api/mcp/portal` (or a client-token mode on the existing surface) — a JSON-RPC MCP server
   whose token type pins `client_id` from the resolved Person and cannot be told otherwise.
2. `PortalTeamsIdentityResolver` — the `Person`-targeting twin of `TeamsIdentityResolver` (D2).
3. `tenant_id → Client` mapping surfaced to `forAppId()` (extend the CIPP tenant mapping) (D4).
4. `Ticket::scopePortalVisibleTo(Person)` — extracted shared read-scope, consumed by portal
   controllers **and** agent tools, so psa-v9w changes one place (D3).
5. A client-facing tool allowlist + a client system prompt (grounded-only, refuse-and-escalate) (D7).
6. Per-Client budget/cooldown settings + a cost-to-serve rollup (D6).

Ships **dormant** (like every integration here): behind config + a per-tenant enable, nothing
changes for anyone until a client tenant is registered and switched on.

---

## 5. Security invariants (non-negotiable)

1. **Authorization is server-side, below the prompt stream.** Who the sender is (`cipp_user_id` →
   active portal-enabled Person) and which Client they may see are decided by PHP, from the *signed*
   request — never from model output or tool arguments. `client_id` in a tool call is ignored.
2. **No shared-account fallback.** Unknown / deactivated / cross-tenant / null-cipp ⇒ resolve to
   `null` ⇒ do-not-act + audit. (Direct inheritance of the E1 resolver's load-bearing guarantee.)
3. **Two-key tenant binding.** The bot is bound to a tenant (signed aud → `forAppId().tenant_id`),
   *and* the Person is bound to a Client; the tenant's mapped Client must equal the Person's Client.
   Both must agree.
4. **Least privilege token.** Client token = reads + `create_ticket` (+ phase-2 reply). No action /
   remediation / cross-client tool exists on it. Not "allowed and unused" — **absent**.
5. **Grounded output only, always scan+escape.** Model states only tool-returned facts; all outbound
   text passes `WikiRedactor` + `TeamsText`. Not-owned records are uniformly "not found".
6. **Confidential-ready.** All ticket reads flow through the shared portal-visibility scope so
   psa-v9w's confidential filter composes automatically.
7. **Audited.** Every tool call → `McpAuditLog`; every refusal (unresolved sender) → warning log.

---

## 6. What we explicitly do NOT build (anti-scope-creep)

- **No remediation / M365 writes** from the client agent (D1). That's psa-slf's staff-gated surface.
- **No ambient/unprompted chatter** for client bots (D6).
- **No cross-client anything** — no global search, no "other companies", no staff tools (D3).
- **No new identity store** — `cipp_user_id` is the oid; don't add a parallel column (D2).
- **No bespoke per-client code** — per-client = data (persona row + tenant→Client map), not code (D4).
- **No confidential-ticket logic here** — this doc makes the agent *ready* for psa-v9w by routing all
  reads through one scope; it does not implement psa-v9w.

---

## 7. Phasing / build sequencing

1. **Refactor first (unblocks safety):** extract `Ticket::scopePortalVisibleTo(Person)`; migrate the
   existing portal ticket controllers onto it (pure refactor, behavior-preserving, tested). This is
   independently valuable and makes psa-v9w a one-place change.
2. **Identity + surface (dormant):** `PortalTeamsIdentityResolver`, the client-pinned MCP token type
   + `mcp/portal` route, the read tool allowlist consuming the shared scope. All behind config; no
   tenant enabled. Test the resolver's fail-closed matrix hard (unknown/deactivated/cross-tenant/
   null-cipp/portal-disabled).
3. **Ticket intake (the first write):** `create_ticket` (Portal source) + the refuse-and-escalate
   fallback + category deny-list wired to the `JunkDetector` security keywords.
4. **Tenant onboarding runbook + first pilot tenant** (per-tenant registration, tenant→Client map),
   enable for one friendly client, soak. Budget/cooldown live.
5. **Phase 2:** `reply_to_own_ticket`; per-Client cost rollup + budget tiering.
6. **Later / dependent:** compose with psa-v9w when it lands (should be near-free after step 1).

Each of steps 1–5 is a shippable, dormant-until-enabled PR. Nothing lights up for a real client until
step 4's explicit per-tenant enable.

---

## 8. Open items for the plan

- **Manual-contact enrollment UX:** exact copy + the staff "link Teams user → Person" one-click flow,
  and whether to auto-suggest a link from a resolved-tenant-but-unmatched oid.
- **Tenant→Client mapping source of truth:** reuse `clients.cipp_tenant_domain`, or a dedicated
  `clients.teams_tenant_id`? (Lean: dedicated column — a client may use Teams without CIPP.)
- **`mcp/portal` vs a client-mode on `mcp/staff`:** separate route is cleaner for audit + rate-limit
  isolation; a mode reuses more. (Lean: separate route.)
- **Company-wide-access default for the agent:** should the agent expose company-wide reads to a
  `company_wide_access` contact by default, or require an extra opt-in? (Portal parity says yes by
  default; confirm with the operator.)
- **Budget policy:** per-Client daily ceiling default, and the exact "at capacity ⇒ open a ticket"
  copy.
- **Whether the client resolver + staff resolver share an extracted core** (a
  `resolveActiveByObjectId(model, column, tenantCheck)`), or stay as deliberately separate twins for
  blast-radius clarity. (Lean: extract the shared shape, keep the terminal lookup distinct.)
- **Manifest/branding storage:** confirm the persona row is the right home for per-tenant name/icon.

---

## 9. Appendix — the identity equality, proven from source

The whole design rests on `Teams from.aadObjectId == Person.cipp_user_id` for a synced contact. The
chain, each link read in the tree on 2026-07-09:

1. **Teams → oid.** `TeamsIdentityResolver::resolve()` keys the sender on `from.aadObjectId` — the
   Entra directory object id of the signed-in Teams user.
2. **Graph → oid.** `CippContactSyncService` reads `$userData['id']` (Graph `user.id`) for each M365
   user (`CippContactSyncService.php:98`) and persists it to `Person.cipp_user_id`. For an Entra
   *member*, `user.id` **is** the directory object id.
3. **Same value.** (1) and (2) are the same Entra object id, so a CIPP-synced, active, portal-enabled
   contact resolves deterministically by `cipp_user_id` — the contact-side mirror of the staff-side
   `User.microsoft_id == aadObjectId` that E1 already ships.

Consequences already handled by existing sync: CIPP deactivation (`accountEnabled=false`) flips
`is_active` and disables the portal, so the same event that removes a contact from the portal removes
them from the agent — **one deactivation, both doors close.** Manually-created contacts (`cipp_user_id
= null`) are the only non-deterministic case, and D2 routes them to explicit staff linking rather than
a spoofable email guess.

**Why the staff resolver isn't reused verbatim:** it resolves to `User` (staff, `microsoft_id`) with
a system-account-free guarantee tuned for operators. The client twin resolves to `Person`
(`cipp_user_id`), adds the portal-enabled + tenant→Client checks, and must never grant staff-surface
tools. Same *shape* (fail-closed, below the prompt stream), different *terminus* — kept as deliberate
twins so a change to one can't silently widen the other.
