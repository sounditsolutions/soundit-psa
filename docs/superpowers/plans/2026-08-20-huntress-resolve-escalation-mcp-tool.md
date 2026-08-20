# Huntress staged escalation-resolve MCP tool

Staged, **held-only** resolution of ONE Huntress SOC escalation by id — the
record-level "this has been handled" acknowledgement that closes the loop after
a technician works an escalation. One capability (`huntress_resolve_escalation`)
with a staged twin (`huntress_stage_resolve_escalation`); the capability is
**structurally held-only** — every non-held path is refused, so no grant mode
can execute the upstream call without a cockpit approval. Mirrors the
mailbox-rule-removal pattern (2026-08-19 plan, psa-491) end-to-end.

## Source-verified upstream shape

Verified against the anonymously-served Huntress API spec
(`https://api.huntress.io/v1/swagger_doc.json`, fetched 2026-08-20, 68 paths):

- **Write — `POST /v1/escalations/{id}/resolution`**. The body parameter is
  `required: true` and its schema (`EscalationResolutionParameters`) carries
  `determination`, `scope`, `revoke_and_disable_identities` (**boolean,
  `default: true`** — valid only with `determination: unauthorized`; the
  parameterised form's default REVOKES SESSIONS AND DISABLES IDENTITIES), and
  `expiration_date`. Sending the parameterised form can create account-wide
  access rules (`scope: account` applies to every identity on the account).
  **This tool refuses the whole parameterised body, not a param allow-list**:
  an allow-list is a prose inventory of a schema we do not control and drifts
  the day the vendor adds a field. The client sends the literal empty JSON
  object `{}` by construction — no caller input can reach the HTTP body.
- **Post-condition — the 201 response's `EscalationResolution` carries
  `resolution_method` ∈ {`direct`, `dismiss`, `rule`}, required** — documented
  as the code path the server took: `direct` = simple resolve, `dismiss` =
  the temporary resolve from omitting both params, **`rule` = a bulk
  resolution that CREATED attribute rules**. The tool asserts on the reply and
  treats `rule` — or any value it does not recognise — as a HARD FAULT: the
  server-measured blast radius, strictly stronger evidence than "we didn't
  send those params". (Jeeves's ruling 2026-08-20 16:24Z: id-only, whole body
  refused, `resolution_method` post-condition as a hard fault.)
- **`409` "Escalation has already been resolved"** — a clean idempotency
  signal: the approved intent is satisfied without a state change.
- **`422` "Escalation cannot be resolved through the API"** — some escalation
  types are console-only; the approval declines with the vendor's reason.

## Credential: a NEW key class, not a scope widening

The spec documents the default account API key as read-only; resolution
requires a **user-based API key**. The write client therefore reads a separate
credential pair (`huntress_user_api_key` / `huntress_user_api_secret`,
encrypted Settings) and **never falls back to the read key** — a deliberate
fail-closed split so granting the tool cannot silently ride the read
credential. The key does not exist yet; minting it is Charlie's decision and
the ask follows this build. Until then the tool ships dormant twice over:
no token grants it AND `HuntressConfig::isWriteConfigured()` is false, so it
is not even published as live (`unavailable_config`).

## Scope: mapped-org escalations only, client-scoped

The Huntress account can be shared across MSPs (the P1 reads' data-boundary
rule). The staged twin is CLIENT-SCOPED and v1 resolves only escalations whose
`organizations[]` include the calling client's `huntress_organization_id`:

- Stage time: a LIVE `GET /escalations/{id}` (read client) must show the
  escalation exists, is not already resolved, and touches the calling client's
  mapped organization. Already-resolved short-circuits idempotently with
  nothing staged.
- **Account-level escalations (no organization association — e.g.
  integration-health) are refused in v1**: no PSA client owns them, so there
  is no cockpit lane or ticket to hold them against; they are resolved in the
  Huntress console. Under-acting is the correct failure direction here.
- The held payload stores only safe local scalars (`escalation_id`,
  `client_id`, `ticket_id`); approval re-reads the escalation LIVE and
  re-verifies existence, unresolved state, and org↔client scope before the
  POST. Vanished → declined; resolved meanwhile → executed-idempotent without
  an upstream call.

## Held-only invariants

- HELD-ONLY, no auto-act ever: a call reaching the executor under the
  canonical name (i.e. any immediate-execution attempt, whatever mode was
  granted) is refused with guidance (message carries the literal `held-only`
  and `staged=true`); `:staged` grants auto-downgrade `staged=false` calls
  upstream of the executor as usual.
- **Whole-body refusal**: the only accepted keys are `escalation_id`,
  `ticket_id`, `reason` (+ the harness's `staged`/`client_id`). ANY other key
  — `determination`, `scope`, `revoke_and_disable_identities`,
  `expiration_date`, `resolution_method`, or anything unknown — is refused by
  name before any other processing. Unknown keys fail closed.
- Explicit-grant-only (never inherited by the legacy full-surface token),
  kill-switch at stage and approval, 300 s per-escalation cooldown on both
  names, 24 h executed dedup, live-run idempotency, `TechnicianActionLog`
  audit for every outcome, encrypted held payload, fenced escalation subject
  on the approval card (`PromptFence` — vendor-relayed text is untrusted).
- Post-condition fault handling: on `resolution_method === 'rule'` (or any
  unrecognised value) the run still advances to Done — the upstream state DID
  change — but the audit row is `error` naming the server-reported method and
  the approval result carries an operator-facing fault instructing inspection
  of the Huntress console for created attribute rules. A fault that executed
  must never be reported as declined.

## Files

- `app/Support/HuntressConfig.php` — `user_api_key` / `user_api_secret` keys +
  `isWriteConfigured()`.
- `app/Services/Huntress/HuntressWriteClient.php` — user-key Basic auth,
  `resolveEscalation()` POSTing the literal `{}`; 409/422 mapped to typed
  exceptions; bounded 429 retry (the endpoint is retry-safe: a re-resolve is
  a 409).
- `app/Services/Huntress/HuntressEscalationAlreadyResolvedException.php`,
  `HuntressEscalationNotApiResolvableException.php`,
  `HuntressWriteScopeException.php`.
- `app/Services/Mcp/StaffHuntressActionToolExecutor.php` — definitions, staged
  map, whole-body refusal, stage flow (live read + scope + idempotency +
  cooldown), approval replay (revalidation + live re-read + POST +
  post-condition), audit.
- `app/Providers/AppServiceProvider.php` — `HuntressWriteClient` singleton.
- `app/Support/McpToolModes.php` — staged map merge.
- `app/Support/McpToolRegistry.php` — `huntressActionTools()` + sensitive
  group `huntress_action` + integration tier mapping.
- `app/Support/McpToolSurface.php` — client-scoped publication gated on
  `HuntressConfig::isEnabled() && isConfigured() && isWriteConfigured()`
  (publish and dispatch answering one question, psa-wzjzz).
- `app/Http/Controllers/Api/McpStaffController.php` — explicit-grant-only
  branch, client_id-required arm, dispatch arm.
- `app/Services/Technician/TechnicianApprovalService.php` +
  `app/Http/Controllers/Web/TechnicianCockpitController.php` — approval arm.
- `app/Support/StagedActionLabels.php` — label + `huntress_stage_` vendor
  side-effect prefix (the cockpit lane; registering a label without the
  prefix is the psa-lulgh drift).
- `resources/views/cockpit/index.blade.php` — badge (byte-identical label,
  `bi-shield-check`).
- Tests: `tests/Unit/Huntress/HuntressWriteClientTest.php` (literal-`{}` body,
  user-key-only auth, 409/422 mapping, fail-closed unconfigured),
  `tests/Feature/Mcp/HuntressResolveEscalationTest.php` (schema safety,
  whole-body refusal, held-only refusal, scope guards, staged→approve→execute,
  `rule` fault, idempotency, cooldown, kill-switch, dormant-until-configured).

Ships DORMANT: the verb arrives in nobody's `allowed_tools` and its
integration predicate is false until the user-based key is minted; execution
is held-only regardless of mode.
