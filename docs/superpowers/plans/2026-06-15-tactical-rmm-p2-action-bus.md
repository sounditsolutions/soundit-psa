# Tactical RMM — Phase 2 (Action Bus + Safety Layer) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the single audited, confirm-gated pipeline (`TacticalActionService`) that EVERY endpoint-affecting action flows through, migrate the existing run-script onto it, and ship the first new destructive action — **`Reboot`** — end-to-end through that pipeline.

**Architecture:** Phase 2 of `docs/superpowers/specs/2026-06-15-tactical-rmm-integration-design.md` (§5.1, §5.2, and the binding **§11** amendments). One chokepoint for blast-radius control: resolve → authorize → validateParams → confirm (destructive) → execute (NATS-bounded, classify offline) → audit (immutable + redacted) → normalized result. P1 (durable webhooks, DI singleton) is merged and live.

**Tech Stack:** Laravel 12 (PHP 8.3), MariaDB (prod) + SQLite `:memory:` (tests), Guzzle, queued jobs, Bootstrap 5.3 (no build step). Secrets via `Setting` (encrypted).

**Owner-locked safety posture (do not relitigate):** single-tier (any authenticated staff may act) + **confirm-destructive** + **audit-all**. The capability gate is a one-line hook so `psa-hbh` can add tiering later.

**Reference analogs to read first:**
- `app/Models/McpAuditLog.php` + its migration (audit precedent — but make immutability REAL here, see Task 2)
- `app/Services/Wiki/Mining/WikiRedactor.php` (`redact()` — the input-scrub primitive; reuse for audit redaction)
- `app/Services/Tactical/TacticalClient.php` (existing `runScript`/`runScriptAsync`; add the Guzzle injection seam in Task 1; add `reboot()` in Task 8)
- `app/Http/Controllers/Web/AssetController.php::runTacticalScript` (~712) + `Web/TicketController.php::runTacticalScript` (~551) + `app/Services/Triage/TriageToolExecutor.php` (the run-script call sites to migrate onto the bus, Task 6)
- `app/Services/Ai/AiClient.php` / `NinjaClient` constructors (constructor-injected dependency pattern for Task 1)

---

## File Structure

| File | Responsibility | Action |
|---|---|---|
| `app/Services/Tactical/TacticalClient.php` | Add optional injected Guzzle client (test seam); add `reboot()` | Modify |
| `database/migrations/2026_06_15_000002_create_tactical_action_logs_table.php` | Immutable audit table (+ MariaDB triggers) | Create |
| `app/Models/TacticalActionLog.php` | Append-only model (guards update/delete) | Create |
| `app/Services/Tactical/Actions/TacticalAction.php` | Interface: key/isDestructive/validateParams/summary/execute | Create |
| `app/Services/Tactical/Actions/TacticalActionResult.php` | Normalized result (ok\|offline\|error, stdout, retcode, message) | Create |
| `app/Services/Tactical/Actions/RunScriptAction.php` | Run-script as an action (argv-safe) | Create |
| `app/Services/Tactical/Actions/RebootAction.php` | Reboot (destructive) | Create |
| `app/Services/Tactical/TacticalActionService.php` | The bus (resolve→authorize→validate→confirm→execute→audit) | Create |
| `app/Services/Tactical/TacticalActionConfirmToken.php` | Signed, scoped, short-TTL confirm token | Create |
| `app/Support/TacticalConfig.php` + `IntegrationsController::updateTactical` | SSRF guard on `tactical_api_url` | Modify |
| `app/Http/Controllers/Web/AssetController.php`, `Web/TicketController.php`, `app/Services/Triage/TriageToolExecutor.php` | Route run-script through the bus | Modify |
| `resources/views/assets/show.blade.php` | Reboot button + confirm modal; offline state | Modify |
| `app/Http/Controllers/Web/AssetController.php` | `rebootTacticalAgent` endpoint + route | Modify |
| `docs/INSTALL.md` | Action audit, confirm flow, SSRF, service-role | Modify |
| `tests/Feature/Tactical/Actions/*`, `tests/Unit/Tactical/Actions/*` | Bus, actions, audit, confirm, SSRF | Create |

---

### Task 1: Add a Guzzle injection seam to `TacticalClient`

The bus must be testable against a fault-injecting transport (offline/timeout) without reflection (the P1 code-review deferred this here). Preserve the zero-arg singleton.

**Files:** Modify `app/Services/Tactical/TacticalClient.php`. Test: `tests/Unit/Tactical/TacticalClientInjectionTest.php`.

- [ ] **Step 1: Failing test** — construct `new TacticalClient($mockGuzzle)` with a `MockHandler` returning 200 `[]`; assert `getAgents()` uses it; assert a `ConnectException`/timeout surfaces as `TacticalClientException`.
- [ ] **Step 2: Run → fail.**
- [ ] **Step 3: Implement** — `__construct(?\GuzzleHttp\Client $http = null)`; when null, build the config-driven client exactly as today (X-API-KEY, base_uri, timeout). When provided, use it. Singleton binding stays zero-arg (`AppServiceProvider` unchanged). The reflection-based `TacticalClientHttpTest` from P1 can be simplified to use the seam (optional; do it if quick).
- [ ] **Step 4: Run → pass.** Run full `--filter=Tactical` to confirm no regression.
- [ ] **Step 5: Commit** `feat(tactical): constructor-injectable Guzzle client for testability (P2)`.

---

### Task 2: `tactical_action_logs` — immutable, append-only audit

Spec §11: immutability enforced at the **DB layer**, not just the model. MariaDB triggers block UPDATE/DELETE; SQLite (tests) relies on the model guard (triggers are skipped on non-MariaDB).

**Files:** Create migration + `app/Models/TacticalActionLog.php`. Test: `tests/Feature/Tactical/TacticalActionLogTest.php`.

- [ ] **Step 1: Failing tests** — a log row can be created; calling `->update([...])` or `->delete()` throws; (MariaDB-only, skipped on SQLite) a raw `DB::table('tactical_action_logs')->update()` is blocked by the trigger.
- [ ] **Step 2: Run → fail.**
- [ ] **Step 3: Migration** — columns: `id`, `actor_id` (FK users, nullable), `actor_label` (string), `action_key` (string, indexed), `agent_id` (string, nullable, indexed), `asset_id` (FK assets, nullable), `target_label` (string), `params` (json, **redacted before write**), `result_status` (string: ok\|offline\|error\|denied), `retcode` (int nullable), `output` (text nullable, redacted+truncated), `message` (string nullable), `correlation_id` (uuid, indexed), `created_at`. NO `updated_at` (append-only). After table creation, **if** `DB::connection()->getDriverName() === 'mariadb'`, run raw `CREATE TRIGGER` statements that `SIGNAL SQLSTATE '45000'` on `BEFORE UPDATE`/`BEFORE DELETE`. `down()` drops triggers then table.
- [ ] **Step 4: Model** — `TacticalActionLog`: `$fillable` for create only; `casts` `params=>array`, `created_at=>datetime`; `const UPDATED_AT = null`; override `performUpdate`/`performDeleteOnModel` (or boot `updating`/`deleting` events) to `throw new \LogicException('tactical_action_logs is append-only')`.
- [ ] **Step 5: Run → pass.**
- [ ] **Step 6: Commit** `feat(tactical): immutable tactical_action_logs audit table (P2)`.

---

### Task 3: `TacticalAction` interface + `TacticalActionResult`

**Files:** Create `app/Services/Tactical/Actions/TacticalAction.php`, `TacticalActionResult.php`. Test: `tests/Unit/Tactical/Actions/TacticalActionResultTest.php`.

- [ ] **Step 1: Failing test** — `TacticalActionResult::ok($stdout,$retcode)`, `::offline($msg)`, `::error($msg)` produce the right `status` and helpers (`isOk()`, `isOffline()`).
- [ ] **Step 2–4: Implement + green.**
  - `interface TacticalAction { public function key(): string; public function isDestructive(): bool; public function validateParams(array $params): array; /* normalized, throws InvalidActionParams */ public function summary(array $params): string; public function execute(TacticalClient $client, string $agentId, array $params): TacticalActionResult; }`
  - `TacticalActionResult` value object with `status`, `stdout`, `retcode`, `message`, factory methods, and an `audit()` array shape.
- [ ] **Step 5: Commit** `feat(tactical): action contract + normalized result (P2)`.

---

### Task 4: `TacticalActionConfirmToken` — signed, scoped, short-TTL

Spec §11: destructive confirmation is bound to `{action_key, agent_id, actor}`, not a bare boolean.

**Files:** Create `app/Services/Tactical/TacticalActionConfirmToken.php`. Test: `tests/Unit/Tactical/TacticalActionConfirmTokenTest.php`.

- [ ] **Step 1: Failing tests** — `issue(actionKey, agentId, actorId)` → opaque token; `verify(token, actionKey, agentId, actorId)` true within TTL; false if any field differs (wrong agent/action/actor) or expired/tampered.
- [ ] **Step 2–4: Implement** using Laravel's signed payloads (`encrypt()`/`Crypt` with an embedded `expires_at`, or `hash_hmac` over the tuple + timestamp with `APP_KEY`). TTL ~5 min. Green.
- [ ] **Step 5: Commit** `feat(tactical): scoped confirm tokens for destructive actions (P2)`.

---

### Task 5: `TacticalActionService` — the bus

**Files:** Create `app/Services/Tactical/TacticalActionService.php`. Test: `tests/Feature/Tactical/TacticalActionServiceTest.php`.

- [ ] **Step 1: Failing tests** (mocked `TacticalClient` via Task 1 seam, fake actions):
  - resolves an `Asset` → its `tactical_assets.agent_id`; throws a clear error if unlinked/no agent.
  - **authorize**: an unauthenticated/incapable actor → `denied` result + an audit row with `result_status=denied` (capability gate is "authenticated" for now).
  - **validateParams**: invalid params → `error`/`rejected` + audit, action NOT executed.
  - **confirm**: a destructive action without a valid confirm token → blocked + audit; with a valid token → proceeds.
  - **execute + classify**: client returns ok → `ok` result; client throws `TacticalClientException` (simulating natsdown/offline) → **`offline`** result (caught + classified), NOT an unhandled exception.
  - **audit**: every path writes exactly one `tactical_action_logs` row with a `correlation_id`; **a secret in params/output is redacted** in the stored row (assert a known secret string is absent).
- [ ] **Step 2: Run → fail.**
- [ ] **Step 3: Implement** `dispatch(TacticalAction $action, Asset $target, User $actor, array $params, ?string $confirmToken = null): TacticalActionResult`:
  1. resolve agentId from `$target->tacticalAsset?->agent_id` (fail → `error('not linked to a Tactical agent')`, audited).
  2. authorize (capability hook; single-tier = `$actor !== null`) → else `denied` (audited).
  3. `$params = $action->validateParams($params)` (catch `InvalidActionParams` → `error`, audited).
  4. if `$action->isDestructive()`: require `TacticalActionConfirmToken::verify($confirmToken, $action->key(), $agentId, $actor->id)` → else blocked (audited).
  5. execute within try/catch: `$action->execute($client, $agentId, $params)`; catch `TacticalClientException` → classify (`offline` if connect/timeout/natsdown markers, else `error`).
  6. audit: write a redacted `TacticalActionLog` (run `params`, `stdout`, `summary` through `WikiRedactor::redact()`; truncate output) with a generated `correlation_id`.
  7. return the result.
- [ ] **Step 4: Run → pass.**
- [ ] **Step 5: Commit** `feat(tactical): action bus (authorize/confirm/execute/audit) (P2)`.

---

### Task 6: Migrate run-script onto the bus

**Files:** Create `app/Services/Tactical/Actions/RunScriptAction.php`; modify `AssetController::runTacticalScript`, `TicketController::runTacticalScript`, `TriageToolExecutor`. Tests: `tests/Feature/Tactical/Actions/RunScriptActionTest.php` + update existing run-script feature coverage.

- [ ] **Step 1: Failing tests** — `RunScriptAction::validateParams` tokenizes args safely (quoted args preserved; no `explode(' ')`); `execute` calls `TacticalClient::runScript` with the exact mapped body; running a script through the bus writes an audit row; the asset/ticket endpoints still return the same stdout to the UI (behavior preserved) AND now produce an audit row.
- [ ] **Step 2: Run → fail.**
- [ ] **Step 3: Implement** — move the arg-parsing + `runScript` call into `RunScriptAction` (proper tokenization, e.g. `str_getcsv` with space delimiter or a small argv parser — NOT raw `explode(' ')`); `isDestructive()` = false (curated library scripts → no confirm). Repoint the three call sites to `app(TacticalActionService::class)->dispatch(new RunScriptAction, $asset, $user, $params)`. Keep the ticket-note side effect.
- [ ] **Step 4: Run → pass** (full `--filter=Tactical` + the asset/ticket tests).
- [ ] **Step 5: Commit** `refactor(tactical): run-script flows through the action bus (audited) (P2)`.

---

### Task 7: SSRF guard on `tactical_api_url`

Spec §11: a staff user repointing the base URL could exfiltrate the 2FA-bypassing key / SSRF the VPS.

**Files:** Modify `IntegrationsController::updateTactical` (validation) + a small helper in `TacticalConfig` or a `Rules\SafeHttpUrl`. Test: `tests/Feature/Tactical/TacticalApiUrlSsrfTest.php`.

- [ ] **Step 1: Failing tests** — saving `tactical_api_url` rejects: non-https, `http://`, hosts resolving to private/link-local/metadata ranges (127.0.0.1, 169.254.169.254, 10/8, 172.16/12, 192.168/16, `::1`, `localhost`); accepts a normal `https://rmm.example.com`.
- [ ] **Step 2: Run → fail.**
- [ ] **Step 3: Implement** a validation rule: require scheme `https`, parse host, resolve (`gethostbynamel`) and reject any IP in a private/reserved/link-local block (use `filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)`), reject literal `localhost`. Apply in `updateTactical`.
- [ ] **Step 4: Run → pass.**
- [ ] **Step 5: Commit** `feat(tactical): SSRF guard on Tactical API URL (P2)`.

---

### Task 8: `RebootAction` + UI — the end-to-end destructive action

**Files:** Add `TacticalClient::reboot($agentId)`; create `app/Services/Tactical/Actions/RebootAction.php`; add `AssetController::rebootTacticalAgent` + route; modify `resources/views/assets/show.blade.php`. Tests: `tests/Feature/Tactical/Actions/RebootActionTest.php` + an endpoint test.

- [ ] **Step 1: Failing tests** — `RebootAction::isDestructive()` true; reboot through the bus WITHOUT a confirm token is blocked + audited; WITH a valid token calls `TacticalClient::reboot` (`POST /agents/{id}/reboot/`) and writes an `ok`/`offline` audit row; the endpoint requires the typed-hostname confirm and a valid token; an offline agent yields a clear "agent offline" response, not a 500.
- [ ] **Step 2: Run → fail.**
- [ ] **Step 3: Implement** — `TacticalClient::reboot($agentId)` → `POST agents/{id}/reboot/` (verify exact path/response against the **live Vultr box** when available; until then, mocked). `RebootAction` (destructive). `AssetController::rebootTacticalAgent`: issue/verify a `TacticalActionConfirmToken`, require the posted hostname to match the asset, dispatch via the bus. Blade: a "Reboot" button (only when agent online) opening a confirm modal that requires typing the hostname; renders the normalized result incl. offline state (replace the P1-flagged "card vanishes when offline" pattern with an explicit disabled/offline state). Bootstrap-only, no build step.
- [ ] **Step 4: Run → pass** (full suite).
- [ ] **Step 5: Commit** `feat(tactical): Reboot action end-to-end through the bus (P2)`.

> **LIVE VERIFICATION (gated on the Vultr/Tactical box):** before P2 is called done, run a real reboot against a throwaway test agent and confirm: the confirm flow, the audit row (`ok` + retcode), and the **offline classification** (reboot an already-offline agent → `offline` result, not error). Confirm the exact `/agents/{id}/reboot/` response shape and adjust `reboot()`/classification if it differs from the mocked assumption.

---

### Task 9: Docs

**Files:** Modify `docs/INSTALL.md`.

- [ ] **Step 1:** Document in §9 Tactical: the action audit trail (`tactical_action_logs`, immutable), the destructive-action confirm flow, the SSRF-validated API URL, and the **least-privilege service-user role** (the ALLOW/DENY list from the P2 kickoff). Note the confirm-token + audit are the compensating controls for single-tier.
- [ ] **Step 2: Commit** `docs(tactical): action bus, audit, confirm, SSRF, service-role (P2)`.

---

## Self-Review

**Spec coverage (§5.1, §5.2, §11):** action bus ✅ (T5); immutable+redacted audit ✅ (T2,T5); param-validation stage ✅ (T3,T5,T6); confirm-token bound to {action,agent,actor} ✅ (T4,T5,T8); natsdown/offline classification ✅ (T1 seam,T5); SSRF guard ✅ (T7); least-priv role documented ✅ (T9); run-script migrated ✅ (T6); ends with Reboot ✅ (T8). Bulk/multi-agent + count-confirm is **P3** (single-target only here). Connection-test role surfacing is light/deferred to when the live key exists.

**Test isolation:** all tests SQLite `:memory:`; MariaDB-trigger immutability test is guarded to run only on MariaDB (skipped otherwise) — the model guard covers SQLite.

**Live dependency:** Task 8's reboot path is built + unit-tested against mocks; the exact `/agents/{id}/reboot/` response + the offline classification MUST be verified against the live Vultr/Tactical box before P2 is marked done (see the gated note).

**Reversibility:** new tables `tactical_action_logs` (+ triggers) — `down()` drops triggers then table. No changes to existing tables. MariaDB + SQLite compatible (triggers driver-guarded).

**Verification before done:** every task green on `php artisan test --filter=Tactical`; full `php artisan test` before the PR; CI green; **+ the live reboot verification**.

---

## Execution Handoff

Mayor-orchestrated supervised subagent in the `tactical-rmm-p2` worktree branch, task-by-task with review (the bus + audit + confirm tasks — T2/T4/T5/T8 — are the security-critical checkpoints). Persona-gate this PLAN before building; persona code-review the PR before merge; **live reboot verification on the Vultr box before done**; merge/deploy is Charlie's call (no auto-deploy).
