# Tactical RMM — Phase 3: New remote actions (cmd / shutdown / recover / maintenance)

**Status:** PLAN (pre-persona-review). Epic psa-8sgu, phase **psa-yybu**.
**Builds on:** P1 (harden, merged) + **P2 (action bus + safety, merged & LIVE on dev, c4f1a3d)**.

**Architecture:** Phase 3 of `docs/superpowers/specs/2026-06-15-tactical-rmm-integration-design.md`
(§3 endpoints, §5.1 bus, §5.2 safety, and the binding **§11** amendments). P3 adds the remaining
four single-agent actions to the **existing** bus — no new pipeline, no new safety primitives. Every
action flows through the P2 chokepoint already shipped: resolve → authorize → validateParams →
confirm(destructive, payloadHash-bound token) → execute(bounded, classify offline) → audit(immutable +
redacted) → normalized result. P2 proved the spine end-to-end with `Reboot`; P3 fills in its siblings.

**Tech Stack:** Laravel 12 (PHP 8.3), MariaDB (prod) + SQLite `:memory:` (tests), Guzzle (injected via
the P2 seam), Bootstrap 5.3 (no build step). Secrets via encrypted `Setting`.

**Owner-locked safety posture (do NOT relitigate — P2 decisions carry forward):** single-tier (any
authenticated staff may act) + **confirm-destructive** + **audit-all**. The capability gate is the same
one-line hook. Destructive set per spec §5.2 = **reboot, shutdown, ad-hoc cmd**. recover + maintenance
are state changes but NOT destructive (no irreversible blast radius) → audited, no confirm token.

## The headline risk: ad-hoc `cmd` is arbitrary remote code execution

`RunCommandAction` lets an authenticated tech run an arbitrary command on an endpoint. This is the most
dangerous capability in the whole integration. Its defenses are the spine of this plan and the focus of
the persona/code-review gates:

1. **`shell` is allow-listed** — `cmd` | `powershell` | `shell` only (the exact set Tactical's
   `/agents/<id>/cmd/` accepts). Anything else → audited `rejected`.
2. **The command is a DISCRETE field, never PSA-side shell-concatenated.** PSA hands Tactical the raw
   `cmd` string + `shell`; Tactical runs it in its own interpreter. PSA performs **zero** shell
   interpolation/escaping of its own (no `explode(' ')`, no string-building a command line). The §11.1
   argv rule is about NOT constructing a shell line PSA-side.
3. **The confirm token is bound to `payloadHash` = sha256 of the canonical resolved command** (shell +
   cmd + timeout), via the P2 token's existing `payloadHash` hook. A confirm for `whoami` cannot be
   replayed to run `format C:` — the bus recomputes the hash from the dispatched params and the token
   fails if it differs.
4. **The confirm UI displays the EXACT resolved command** (`summary()`), secret-redacted, and requires
   typing the hostname (mirrors P2 reboot's server-side `strcasecmp` hostname gate).
5. **Bounded timeout; audit-all** — every cmd (allowed, rejected, blocked, offline, error) writes one
   immutable, redacted `tactical_action_logs` row. The redaction backstop shipped in the P2 code-review
   (bare-credential output scrub) already covers cmd stdout/stderr.

**Reference analogs to read first:**
- `app/Services/Tactical/Actions/RebootAction.php` — the destructive single-agent action template (no
  params, scalar "ok" response). Shutdown is a near-clone.
- `app/Services/Tactical/Actions/RunScriptAction.php` — argv tokenization (`tokenize()`, quote-aware,
  NOT `explode(' ')`), `ActionRedactor` usage, the `is_array($raw)` response-shape guard (the live-fix
  lesson — apply it to every new client call).
- `app/Services/Tactical/TacticalActionConfirmToken.php` — the `payloadHash` parameter is already
  plumbed end-to-end (issue/verify); `cmd` is its first real user.
- `app/Services/Tactical/TacticalActionService.php` — `payloadHash()` reads `method_exists($action,
  'payloadHash')`; `dispatch()` signature is stable. No bus change expected.
- `app/Http/Controllers/Web/AssetController.php::rebootTacticalAgent` + the reboot route + the
  `resources/views/assets/show.blade.php` reboot confirm modal + offline state — the endpoint/UI template.
- `app/Services/Tactical/TacticalClient.php` — `reboot()`/`runScript()` are typed `mixed` (scalar-safe);
  new methods follow suit.

**Spec §3 endpoints (primary-source verified, v1.5.0) — exact request/response shapes re-verified live
during build (the P2 reboot/cmd lesson: never trust a mocked shape):**
- Ad-hoc command: `POST /agents/<id>/cmd/` — body `{shell, cmd, timeout, custom_shell:null}`; sync;
  returns command output (string); writes `AgentHistory`.
- Shutdown: `POST /agents/<id>/shutdown/` — sync; expect a scalar "ok"-style body like reboot.
- Recover services: `POST /agents/<id>/recover/` — body `{mode, wgupdate?}`; `mode=mesh` sync /
  `tacagent` async.
- Maintenance: `PUT /agents/<id>/` — toggle `maintenance_mode` (verify whether a partial body is accepted
  or the agent object must be re-sent; bulk `POST /agents/maintenance/bulk/` is OUT of P3 scope).

---

## Scope

**In P3 (single-agent, mirrors P2's single-Reboot discipline):**
- 4 action classes: `RunCommandAction` (destructive, payloadHash), `ShutdownAction` (destructive),
  `RecoverAction` (non-destructive), `SetMaintenanceAction` (non-destructive).
- `TacticalClient` methods: `cmd()`, `shutdown()`, `recover()`, `setMaintenance()` (typed `mixed`).
- Asset-page endpoints + routes + UI: confirm-on-destructive (cmd + shutdown), simple action for
  recover + maintenance; offline state; cmd shows the exact resolved command + typed-hostname.
- Ticket-page surface for `cmd` (the ITIL-relevant "run a diagnostic while working the incident" flow),
  mirroring P2's ticket run-script integration + ticket-note side effect. *(Ticket scope is a
  persona-review question — see Open Questions.)*
- Tests (unit + feature/endpoint-contract) + INSTALL.md docs.

**Deferred OUT of P3 (explicit, tracked):**
- **Bulk / multi-agent** actions (client/site maintenance `bulk/`, multi-agent cmd/reboot) +
  `RunTacticalActionJob` + count-confirmation + soft cap (spec §11 carry-forward). → follow-up bead.
- **Async result surfacing** for `recover mode=tacagent` — P3 ships `mode=mesh` (sync); tacagent-async
  surfaces "accepted" and is fully wired with the bulk/async phase. → same follow-up.
- Remote control / MeshCentral deep-links (P6).

---

## File Structure

| File | Responsibility | Action |
|---|---|---|
| `app/Services/Tactical/TacticalClient.php` | Add `cmd()`, `shutdown()`, `recover()`, `setMaintenance()` (typed `mixed`, scalar-safe) | Modify |
| `app/Services/Tactical/Actions/RunCommandAction.php` | Ad-hoc cmd: shell allowlist, discrete-field, `payloadHash()`, exact-command `summary()` | Create |
| `app/Services/Tactical/Actions/ShutdownAction.php` | Shutdown (destructive) — Reboot clone, scalar-safe | Create |
| `app/Services/Tactical/Actions/RecoverAction.php` | Recover services (`mode=mesh` sync; non-destructive) | Create |
| `app/Services/Tactical/Actions/SetMaintenanceAction.php` | Toggle maintenance (non-destructive) | Create |
| `app/Http/Controllers/Web/AssetController.php` | 4 endpoints (cmd/shutdown/recover/maintenance) via the bus; cmd+shutdown mint+verify confirm tokens, typed-hostname | Modify |
| `app/Http/Controllers/Web/TicketController.php` | `cmd` from a ticket (audited w/ ticket_id, ticket-note side effect) | Modify |
| `routes/web.php` | 4 asset routes (+ 1 ticket route) | Modify |
| `resources/views/assets/show.blade.php` | Action buttons + confirm modals (cmd shows resolved command + typed hostname; shutdown extra-loud); online/offline gating | Modify |
| `resources/views/tickets/…` (the run-script card) | `cmd` control on the ticket asset card | Modify |
| `docs/INSTALL.md` | New actions, confirm flow, the cmd-RCE caveat, least-priv role note | Modify |
| `tests/Unit/Tactical/Actions/*`, `tests/Feature/Tactical/Actions/*` | Per action + endpoint contracts | Create |

---

### Task 1: `TacticalClient` — `cmd` / `shutdown` / `recover` / `setMaintenance`

The transport seam. Every method typed `mixed` (post/put may decode to a scalar — the reboot lesson);
callers normalize. Use the P2 Guzzle injection seam for tests (no reflection).

**Files:** Modify `TacticalClient.php`. Test: `tests/Unit/Tactical/TacticalClientRemoteActionsTest.php`.

- [ ] **Step 1: Failing tests** (MockHandler transport): `cmd($id,$cmd,$shell,$timeout)` POSTs
      `/agents/{id}/cmd/` with `{shell,cmd,timeout,custom_shell:null}` and returns the body;
      `shutdown($id)` POSTs `/agents/{id}/shutdown/` and tolerates a scalar `"ok"`;
      `recover($id,$mode)` POSTs `/agents/{id}/recover/` `{mode}`; `setMaintenance($id,bool)` PUTs
      `/agents/{id}/` toggling `maintenance_mode`. A `ConnectException` surfaces as
      `TacticalClientException`.
- [ ] **Step 2: Run → fail.**
- [ ] **Step 3: Implement.** Mirror `reboot()`/`runScript()` (return `mixed`; `X-API-KEY`/base_uri via
      the existing client). **Re-verify each exact path + body + response shape against the live box /
      Tactical v1.5.0 source during build** — mocked until then; flag any mismatch like the P2 reboot fix.
- [ ] **Step 4: Run → pass** (full `--filter=Tactical`).
- [ ] **Step 5: Commit** `feat(tactical): client methods for cmd/shutdown/recover/maintenance (P3)`.

### Task 2: `RunCommandAction` — ad-hoc command (destructive, payloadHash-bound)

The headline action. See "The headline risk" above — every defense lands here.

**Files:** Create `RunCommandAction.php`. Test: `tests/Unit/Tactical/Actions/RunCommandActionTest.php`.

- [ ] **Step 1: Failing tests** — `key()='tactical.run_command'`, `isDestructive()===true`;
      `validateParams`: empty/whitespace `cmd` → `InvalidActionParams`; `shell` not in
      {cmd,powershell,shell} → `InvalidActionParams`; timeout bounded (10..600). `payloadHash($params)`
      is a stable sha256 over the **canonical resolved** {shell,cmd,timeout} and **changes** if the
      command changes (so a token can't be reused for a different command). `summary()` shows the exact
      command and **redacts** an inline secret. `execute()` calls `TacticalClient::cmd` with the discrete
      `cmd` field (no PSA-side concatenation) and normalizes a non-array/scalar response.
- [ ] **Step 2: Run → fail.**
- [ ] **Step 3: Implement** — `validateParams` returns canonical `{cmd, shell, timeout}`;
      `payloadHash` = `hash('sha256', json_encode([$shell,$cmd,$timeout]))`; `summary` =
      `"[{shell}] {cmd}"` through `ActionRedactor`; `execute` maps to the client + normalizes shape.
- [ ] **Step 4: Run → pass.**
- [ ] **Step 5: Commit** `feat(tactical): ad-hoc command action — shell-allowlisted, payload-bound (P3)`.

### Task 3: `ShutdownAction` (destructive)

A Reboot near-clone, but the box stays **off** (manual/IPMI power-on) → the confirm must say so.

**Files:** Create `ShutdownAction.php`. Test: `tests/Unit/Tactical/Actions/ShutdownActionTest.php`.

- [ ] **Step 1: Failing tests** — `key()='tactical.shutdown'`, `isDestructive()===true`, no params;
      `summary()` warns the device stays off until powered on manually; `execute()` calls
      `TacticalClient::shutdown` and tolerates a scalar response (mirror `RebootActionTest`'s scalar test).
- [ ] **Step 2–4: Implement + green.**
- [ ] **Step 5: Commit** `feat(tactical): shutdown action through the bus (P3)`.

### Task 4: `RecoverAction` (non-destructive, services recovery)

**Files:** Create `RecoverAction.php`. Test: `tests/Unit/Tactical/Actions/RecoverActionTest.php`.

- [ ] **Step 1: Failing tests** — `key()='tactical.recover'`, `isDestructive()===false`;
      `validateParams`: `mode` defaults to `mesh`, accepts {mesh, tacagent}, rejects others;
      `summary()` names the mode; `execute()` calls `TacticalClient::recover($id,$mode)` and normalizes.
      (Note: `tacagent` is async upstream — P3 surfaces the upstream "accepted" message; true async
      tracking is deferred with the bulk phase.)
- [ ] **Step 2–4: Implement + green.**
- [ ] **Step 5: Commit** `feat(tactical): agent services recovery action (P3)`.

### Task 5: `SetMaintenanceAction` (non-destructive toggle)

**Files:** Create `SetMaintenanceAction.php`. Test: `tests/Unit/Tactical/Actions/SetMaintenanceActionTest.php`.

- [ ] **Step 1: Failing tests** — `key()='tactical.set_maintenance'`, `isDestructive()===false`;
      `validateParams`: `enabled` is required and coerced to bool; `summary()` reads "Enable/Disable
      maintenance mode"; `execute()` calls `TacticalClient::setMaintenance($id,$enabled)`.
- [ ] **Step 2–4: Implement + green.**
- [ ] **Step 5: Commit** `feat(tactical): maintenance-mode toggle action (P3)`.

### Task 6: Asset endpoints + routes + UI

Mirror `rebootTacticalAgent` + its modal. cmd + shutdown are confirm-gated (mint+verify a
`TacticalActionConfirmToken`, require a server-side typed-hostname match); recover + maintenance are
single-click (no token). cmd's confirm shows the **resolved** command and binds the token to its
`payloadHash`. All four render the explicit offline state (no vanishing controls).

**Files:** Modify `AssetController.php`, `routes/web.php`, `resources/views/assets/show.blade.php`.
Tests: `tests/Feature/Tactical/Actions/RemoteActionEndpointsTest.php`.

- [ ] **Step 1: Failing tests** — for each action: online happy path → bus dispatch + audit row + the
      P2 JSON contract (ok→200, not-linked→422, offline→422, error→500); cmd/shutdown WITHOUT a valid
      token → blocked + audited; cmd/shutdown with a wrong-hostname → 422 (no dispatch); cmd's token is
      payload-bound (a token minted for command A rejects command B). Destructive controls are
      CSRF-protected (web group). The card renders an offline state when the agent is offline.
- [ ] **Step 2: Run → fail.**
- [ ] **Step 3: Implement** the 4 endpoints + routes + Blade (buttons, cmd modal with the resolved
      command + typed-hostname, shutdown extra-loud modal, recover/maintenance confirms-lite). Bootstrap-only.
- [ ] **Step 4: Run → pass** (full suite).
- [ ] **Step 5: Commit** `feat(tactical): asset-page remote actions UI + endpoints (P3)`.

### Task 7: Ticket-page `cmd` surface

Run an ad-hoc command on a ticket's asset while working the incident; audited with `ticket_id` + a
ticket-note side effect (mirror P2's ticket run-script). *(Final ticket scope pending persona review.)*

**Files:** Modify `TicketController.php`, ticket asset-card view, `routes/web.php`. Test:
`tests/Feature/Tactical/Actions/TicketCommandContractTest.php`.

- [ ] **Step 1: Failing tests** — cmd from a ticket whose asset is attached → dispatch + audit row with
      `ticket_id` + a ticket note; asset not attached to the ticket → 422, no dispatch; confirm-token +
      typed-hostname enforced as on the asset page.
- [ ] **Step 2–4: Implement + green.**
- [ ] **Step 5: Commit** `feat(tactical): run ad-hoc command from a ticket (audited) (P3)`.

### Task 8: Docs

**Files:** Modify `docs/INSTALL.md`.

- [ ] Document the four new actions, the destructive-confirm + typed-hostname flow, the **cmd
      arbitrary-RCE caveat** (and that the audit output redaction is keyword + the P2 backstop, not a
      guarantee curated commands won't print secrets), and reinforce the least-privilege Tactical service
      role (the cmd capability makes an over-privileged key far worse).
- [ ] **Commit** `docs(tactical): remote actions, confirm flow, cmd RCE + least-priv (P3)`.

---

## Testing strategy

- Unit per action (validate/payloadHash/summary/execute via the MockHandler seam).
- Feature/endpoint contracts for every bus exit (ok/not-linked/offline/error/blocked/rejected) + one
  audit row each, mirroring `RunScriptEndpointContractTest`.
- Security-specific: payload-bound token (command-A token rejects command-B), shell allowlist, typed-
  hostname server-side, CSRF, secret redaction of cmd output (bare + keyword), no PSA-side shell
  concatenation.
- Full `php artisan test` + Pint + the real-data/secret-guard green before push (build AWS-shaped /
  AKIA fixtures by concatenation — the known guard gotcha).

## Live-verify (VM 105, the persistent Windows agent kzTt…)

- `cmd`: run a benign command (`whoami` / `ipconfig`) — confirm the exact-command display + payloadHash
  binding + audit row; verify the real response shape vs the mock.
- `maintenance`: toggle on/off — verify the PUT shape and that alerts suppress.
- `recover`: `mode=mesh` — verify the sync response.
- `shutdown`: **time-sensitive** — it leaves VM 105 **off**, and powering it back on needs the Proxmox
  token (**EXPIRES 2026-06-16**). Verify shutdown **last**, immediately power VM 105 back on via Proxmox
  while the token is valid; if the token has expired, defer the live shutdown verify (mock-verified +
  noted) rather than stranding the box.

## Open questions for the persona review

1. **Ticket UI scope** — spec §6 says "asset + ticket UI." Is ticket-side limited to `cmd` (proposed),
   or do shutdown/recover/maintenance belong on the ticket too? Running a *destructive* shutdown from a
   ticket — desirable ITIL action, or asset-page-only?
2. **cmd confirm — single-request vs two-step preview.** P2 reboot used a single typed-hostname request.
   For arbitrary cmd, is a server-side **preview** of the resolved command (then a separate confirm)
   worth the extra round-trip, or does the client-shown command + payloadHash binding suffice?
3. **recover/maintenance destructive classification** — confirmed non-destructive (no confirm token)?
   recover restarts agent services; maintenance suppresses alerting. Both reversible — proposed
   non-destructive but audited. Agree?
4. **shell allowlist** — {cmd, powershell, shell} matches Tactical; do we want to further restrict (e.g.
   powershell-only on Windows) or is parity with Tactical correct?
5. **Bulk deferral** — is shipping single-agent-only (no client/site maintenance bulk, no multi-agent)
   acceptable for P3, with bulk as a dedicated follow-up? (Matches P2's single-Reboot discipline.)

---

## Persona-review amendments (binding) — 2026-06-15

5-reviewer persona panel (Senior Dev+Critic, Security, ITIL+MSP-Ops, Staff+Solo-Owner, PM+Docs) per
`docs/REVIEW_PERSONAS.md`. **Architecture + scope APPROVED**; all five returned REVISE on specifics. The
panel verified every load-bearing P2 claim against the shipped code (the bus, the HMAC confirm-token +
`payloadHash` hook, the redactor, the `setAgentCustomField` partial-PUT precedent) — the foundation is
sound; these amendments tighten the four new failure paths to the precision arbitrary RCE demands. All
are **binding** before/at build unless marked "owner decision."

### A. cmd integrity — the confirm/payloadHash binding (Security BLOCKERs #1, #2; Dev/Critic)

- **A1 — one canonical params source.** The cmd endpoint MUST: call `RunCommandAction::validateParams($request)`
  → ONE canonical `{shell:string, cmd:string, timeout:int}` array → compute `payloadHash` from THAT array
  → mint the token from it → `dispatch()` THAT SAME array. The controller MUST NOT read `cmd`/`shell`/
  `timeout` a second time from the request for execution. The bus already re-derives the verify-side hash
  from the dispatched (validated) params, so issue-side and verify-side hash identical bytes only if this
  holds. Tests (both directions): a token minted for canonical command A → dispatch command B → `blocked`,
  no client call, audited; the exact canonical command the confirm displayed → `ok` (no spurious block).
- **A2 — `validateParams` must NOT alter the command content.** cmd is a discrete opaque string (NOT
  tokenized). Canonicalization is limited to an outer `trim()` for the empty-check; the trimmed string is
  what is hashed, displayed, AND executed — so displayed == hashed == executed. `payloadHash =
  hash('sha256', json_encode([$shell, $cmd, $timeout]))` over the typed canonical array (timeout int, not
  string).
- **A3 — confirm display (resolving Security #2 vs the majority).** Keep the **single-request** typed-
  hostname flow (Dev/Critic, ITIL, PM, Staff all concur; payloadHash binding closes "confirmed A ran B").
  The confirm modal MUST render the **full command in a multi-line `<pre>`** (nothing truncated/scrolled
  out of view). Documented residual (accepted): client-side display is NOT secret-redacted (the tech sees
  their own input on their own screen); the **audit row + ticket note ARE redacted** (see B). We do NOT
  add a server-side preview round-trip — the verbatim-canonical command (A2) makes the displayed command
  authoritative without it.

### B. Redaction completeness for free-text cmd (Security #3, #6; Dev/Critic)

- **B1 — command/summary redaction uses the FULL stack, not just `WikiRedactor`.** `RunScriptAction::summary`
  gets the argv-flag layer; `RunCommandAction`'s command is one opaque string, so `summary()`/`params`
  redaction MUST additionally run the bare-credential backstop (`ActionRedactor::OUTPUT_SECRET_PATTERNS`),
  not only `WikiRedactor::redact()`. Add `ActionRedactor::redactCommandString()` (Wiki + bare-credential
  patterns) and route cmd `summary`/`params` through it. `RunCommandAction` injects `ActionRedactor` via the
  same constructor-default seam as `RunScriptAction`.
- **B2 — §11.2 cmd-specific secret test (binding).** Plant `mysql -pSuperSecret123`, `net user x P@ssw0rdLong /add`,
  and a bare 40-char positional token → none appears in `params`, `summary`, the audit `message`, OR the
  ticket note. Document the residual honestly: short/low-entropy positional secrets (e.g. `Hunter2`) may
  still pass — args + output are best-effort redacted; techs must avoid inline secrets.
- **B3 — ticket-note body MUST be redacted (Security #6, Dev/Critic).** `TicketController`'s note path
  currently embeds raw `$stdout`/`$stderr`. For cmd, the note MUST be written from redacted values
  (`redactCommandString` for the command, `redactOutput` for stdout/stderr) — the bus redacts the audit
  row, but the note is written outside the bus. Test: a secret in cmd stdout never appears in the created
  ticket note. The note records the **redacted `summary()` command + retcode + redacted/elided output** —
  never raw request input.

### C. cmd body hardening — fence the dangerous fields (Security #5)

- **C1 — pin the cmd request body** to exactly `{shell, cmd, timeout, custom_shell: null, run_as_user: false}`
  with `env_vars` omitted or `[]`, ALL hardcoded server-side. `custom_shell` MUST be `null` and MUST NEVER
  be derived from any input (it specifies an arbitrary interpreter path → bypasses the shell allowlist).
- **C2 — shell allowlist + timeout fail-closed, server-side.** `validateParams` enforces `shell ∈
  {cmd, powershell, shell}` (absent/empty → reject, never default-permissive) and `timeout ∈ 10..600`
  (reject `0`/huge — it ties up a web worker on the NATS round-trip). Test: a request carrying
  `custom_shell`/`env_vars`/`run_as_user` keys has them ignored/overridden by the server.

### D. Failure-mode honesty (Dev/Critic MAJORs)

- **D1 — cmd response shape: the bare STRING is the PRIMARY path** (spec §3: cmd "returns command output
  (string)"), not a defensive fallback. `execute` normalizes a non-array result; the **primary** asserted
  test is "bare string → `ok` with that string as stdout", and **empty-string output → still `ok`, not a
  falsy-triggered error". The array-response test is the secondary/defensive case.
- **D2 — shutdown irreversibility (Dev/Critic, ITIL).** `ShutdownAction::summary()` states the device-
  specific consequence verbatim — "this device powers off and **cannot be powered back on remotely;
  recovery requires physical/IPMI access**" — and that text lands in BOTH the confirm modal AND the
  persisted audit `message`/ticket note (the person reading the log 3 days later isn't the clicker).
  **Decision (not deferred):** PSA does not reliably know an asset's out-of-band recovery method, so the
  modal shows the generic "no remote power-on" warning for every shutdown (we do NOT gate on IPMI data PSA
  lacks).
- **D3 — maintenance PUT: partial body, pinned now.** Use `PUT /agents/<id>/` with the partial body
  `{maintenance_mode: bool}` (precedent: the live `setAgentCustomField` partial PUT). **Reject** the
  read-modify-write full-object approach (concurrency-clobber risk). "Verify against live" becomes a
  confirmation, not an open fork.
- **D4 — RecoverAction = `mode=mesh` only in P3.** `validateParams` accepts `mesh` (sync) and **rejects
  `tacagent`** with "async recover ships with the bulk/async phase ([[psa-d76b]])" — never fire an
  untrackable async call the UI might present as completed. UI copy: sync mesh reports the real outcome;
  button label is plain-English "Recover agent". (Async tacagent + result surfacing → psa-d76b.)
- **D5 — live-verify fallback policy (all four).** A mock-vs-live mismatch is a tracked follow-up
  correction (the P2 reboot precedent); shipping mock-verified-with-a-noted-TODO is acceptable for the
  non-destructive actions. **cmd's exact request body is merge-blocking-verify** (a wrong body on an RCE
  endpoint silently sends a malformed command). shutdown live-verify is time-boxed by the Proxmox token
  (expires 2026-06-16) — verify last + power VM 105 back on immediately, else mock-verified + noted (an
  accepted, pre-agreed outcome).
- **D6 — no shared base class.** ShutdownAction duplicates RebootAction's ~6 trivial lines; do NOT extract
  a `ScalarResponseAction`/`NoParamDestructiveAction` parent — the bus is the only shared spine.

### E. ITIL change-record + UX/ops (ITIL+Ops, Staff+Owner)

- **E1 — reason on destructive audit rows — DROPPED from P3 (owner decision H2).** Deferred to
  [[psa-jke6]]; P3 stays schema-free. **E2 (optional ticket-linkage) is P3's traceability mechanism.**
- **E2 — optional ticket-linkage for asset-page destructive actions (ITIL/Ops).** The cmd/shutdown confirm
  may optionally link an open ticket (captures `ticketId` on the audit row); ticket-originated dispatch
  passes `ticketId` non-optionally. This gives incident traceability without putting destructive actions on
  a second (multi-asset-ticket misfire-prone) surface — see OQ1.
- **E3 — maintenance state must be VISIBLE + well-placed (Staff/Owner MAJORs).** Render the current
  maintenance state prominently on the Tactical card header (a warning badge "Maintenance — alerts muted"
  when ON), read from `tactical_assets.maintenance_mode`. The toggle lives as an always-visible control near
  the device status, NOT buried in the script/power card. Document the "forgotten-on / silent muted device"
  risk in INSTALL.md + the §9 risk table. Optional auto-expiry ("maintenance for 2h", PSA-scheduled
  un-toggle) is noted as a follow-up, not P3.
- **E4 — mobile (Staff MAJOR).** The cmd/shutdown modals MUST be usable at ~375px — the multi-line resolved
  command, the hostname input, AND the confirm button all reachable without the button scrolling off. Add
  to the QA pass (spec §8 manual QA), which currently lists only desktop contract tests.
- **E5 — confirm ergonomics (Staff).** Uniform typed-FULL-hostname across reboot/shutdown/cmd; NO lighter
  tier (last-4 is a false economy on near-identical hostnames like DC01/DC02). cmd UI: pre-select `shell`
  by device OS (`monitoring_type`/`os`) — Windows → `cmd`/`powershell`, else `shell` — still changeable
  (parity allowlist, smart default; resolves OQ4); add an optional HTML `<datalist>` of common diagnostic
  commands (no build step). Editing the command after the modal opens re-mints/clears the confirm (or the
  command field is read-only once the resolved command shows) with a clear "command changed — re-confirm"
  message (mirror the reboot expiry-message pattern).

### F. Documentation (Docs BLOCKERs/MAJORs)

- **F1 — Task 8 = named edits to the EXISTING `INSTALL.md` §9 `### Tactical RMM` subsection (~lines
  540–564), not greenfield.** Specifically: reword `:553`/`:547` ("shutdown/ad-hoc command **later**",
  "**future** commands") → shipped (reboot, shutdown, cmd); **repoint `:558` and `:560`** (the now-false
  "bulk/`RunTacticalActionJob` → P3" and "psa-nfqd needs the P3 async path" promises) → **[[psa-d76b]]**;
  extend the `:549` bare-credential caveat to cover ad-hoc cmd stdout.
- **F2 — prominent cmd-as-RCE callout.** Add a `>`-blockquote in §9: "Ad-hoc command = arbitrary remote
  code execution … any authenticated staff user … a leaked API key is now an RCE foothold across the fleet"
  + reinforce the least-priv service role with cmd-specific language. Confirm the existing ALLOW/DENY role
  matrix (`:540–543`) covers `POST /agents/<id>/cmd/|/shutdown/|/recover/` + `PUT /agents/<id>/`.
- **F3 — assert the negatives.** No `.env.example` change (Tactical config is DB-backed via `Setting`/
  `TacticalConfig`); no cron-table change (actions are synchronous; bulk job deferred to psa-d76b); no new
  PHP extensions; **no README route-table change** (it's resource-level only — house style omits all POST
  sub-actions incl. the shipped reboot/run-script; adding action routes would break consistency).

### G. Open Questions — resolved (binding)

1. **Ticket UI scope → cmd-only.** Ticket surface = ad-hoc cmd (the ITIL "diagnostic during incident"
   flow). shutdown/recover/maintenance are asset-page-only; destructive actions gain traceability via E2
   (optional ticket-link + reason), not a second destructive surface. (Reboot/shutdown-on-ticket = an
   operator-driven follow-up if requested.)
2. **cmd confirm → single-request typed-full-hostname** (no two-step preview, no lighter tier) — see A3/E5.
3. **recover/maintenance → non-destructive + audited, no confirm token** — confirmed (spec §5.2 fixes the
   destructive set = reboot/shutdown/cmd). Maintenance-enable needs visible state (E3).
4. **shell allowlist → parity {cmd, powershell, shell}**, no PSA-side OS restriction; smart UI default by
   OS (E5).
5. **bulk → defer destructive bulk to [[psa-d76b]]** (named, tracked). Client/site **bulk maintenance** is
   low-risk + operationally urgent (alert fatigue) → **owner decision** on timing (see H3).

### H. Owner decisions — RESOLVED (Charlie, 2026-06-15)

- **H1 — capability tier → KEEP SINGLE-TIER.** Any authenticated staff may run cmd/shutdown; do NOT gate
  behind `psa-hbh` in P3. Binding follow-through: (a) re-record the single-tier acceptance as **informed of
  arbitrary-RCE scope (2026-06-15)**; (b) **update the spec §9 risk row** from "any staff can reboot prod"
  to "any staff can run arbitrary code as SYSTEM/root on every linked endpoint via the 2FA-bypassing key" —
  accepted, mitigated by confirm-token + typed-hostname + immutable audit + least-priv role; (c) the
  capability hook remains the one-line seam for a future psa-hbh.
- **H2 — reason field → DEFER (P3 stays SCHEMA-FREE).** No `reason` column, no migration in P3. Destructive
  audit rows record who/what/when/result; the "why" is deferred to **[[psa-jke6]]**. In the meantime,
  **E2 optional ticket-linkage is the traceability mechanism for P3** (keep it). **E1 below is dropped from
  P3.**
- **H3 — bulk maintenance → NEAR-TERM FOLLOW-UP, before P4.** P3 ships the four single-agent actions only;
  client/site bulk maintenance is **[[psa-88oe]]** (low-risk single `POST /agents/maintenance/bulk/` +
  client/site picker; reuses the P3 `SetMaintenanceAction` + bus audit; does NOT need the
  `RunTacticalActionJob`/count-confirm rig that bulk-destructive [[psa-d76b]] needs). Sequenced ahead of P4.

### Task-list deltas from this review

- **No new schema** (H2 — reason deferred to [[psa-jke6]]); P3 remains migration-free.
- **Task 1** gains: C1 pinned cmd body (`custom_shell:null`, `run_as_user:false`, `env_vars:[]` hardcoded);
  D3 maintenance partial-PUT; D4 recover `mesh`-only.
- **Task 2** gains: A1/A2 canonical-params + payloadHash discipline, B1 `redactCommandString`, C2 shell/
  timeout fail-closed validation, D1 string-primary tests.
- **Task 6** gains: A3 multi-line confirm, E2 optional ticket-link, E3 maintenance state/placement, E4
  mobile QA, E5 ergonomics; PM-noted optional split (non-destructive PR, then destructive PR).
- **Task 7** gains: B3 redacted ticket note, DC1 success-gated post-dispatch note (write only on `isOk()`).
- **Task 8** rewritten per F1/F2/F3 (named INSTALL.md §9 edits + cmd-RCE callout + repoint deferred bullets
  to [[psa-d76b]]; assert the doc-negatives).
- **Spec edit (H1):** update the §9 risk row to the accurate RCE blast-radius wording.
