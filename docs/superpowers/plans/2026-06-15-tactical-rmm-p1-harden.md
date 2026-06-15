# Tactical RMM — Phase 1 (Harden) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the existing Tactical RMM integration *trustworthy and legible* — durable + replay-safe alert webhooks, consistent DI, a visible webhook-health signal, version-drift protection, real test coverage, and accurate operator docs.

**Architecture:** Phase 1 of the flagship plan in `docs/superpowers/specs/2026-06-15-tactical-rmm-integration-design.md`. Hardens the foundation before the Action Bus (P2) and remote actions (P3+). Mirrors the proven **Ninja** durability pattern — but improves on it where the persona review found gaps (webhook replay/idempotency, doc debt).

**Tech Stack:** Laravel (PHP 8.3), MariaDB, queued jobs (database driver), Guzzle, PHPUnit/Pest via `php artisan test`. No frontend build step (Bootstrap 5.3 + Icons CDN). Secrets via `Setting` (encrypted).

> **Persona-review corrections folded in (2026-06-15):** (1) `ram_gb`/`os_version` population **moved to P4** — the daily sync calls `getAgents()` (the *list* endpoint), which does not carry full hardware; populating it correctly needs per-agent `getAgent()` detail reads, which belong with the P4 insight/refresh layer. (2) P1's *visible* trust signal is now a **webhook-health indicator** (Task 3). (3) Webhook gains **replay/idempotency** protection and full **Ninja-fidelity job lifecycle** (Task 1). (4) **Documentation** tasks added (Task 6). (5) Call-site inventory corrected (Task 2).

**Reference analogs to read first:**
- `app/Http/Controllers/Api/NinjaWebhookController.php`, `app/Jobs/ProcessNinjaWebhook.php`, `app/Models/NinjaWebhook.php` (the `isPending/markProcessed/markSkipped/markFailed` lifecycle + `failed()` hook), `database/migrations/2026_02_18_000002_create_ninja_webhooks_table.php`
- `app/Providers/AppServiceProvider.php` (singleton bindings)
- `app/Services/Tactical/TacticalAlertService.php`, `TacticalClient.php`, `app/Http/Middleware/VerifyTacticalWebhookKey.php`

---

## File Structure

| File | Responsibility | Action |
|---|---|---|
| `database/migrations/2026_06_15_000001_create_tactical_webhooks_table.php` | Persisted inbound webhooks + dedup key | Create |
| `app/Models/TacticalWebhook.php` | Model w/ status lifecycle helpers | Create (mirror `NinjaWebhook`) |
| `app/Jobs/ProcessTacticalWebhook.php` | Async processing via `TacticalAlertService` | Create (mirror `ProcessNinjaWebhook`) |
| `app/Http/Controllers/Api/TacticalWebhookController.php` | Validate + persist (deduped) + dispatch + 200 | Modify |
| `app/Providers/AppServiceProvider.php` | `TacticalClient` singleton | Modify |
| `resources/views/settings/integrations.blade.php` | Webhook-health indicator on the Tactical card | Modify |
| `app/Http/Controllers/Web/IntegrationsController.php` | Supply webhook-health stats to the view | Modify |
| `docs/INSTALL.md` | Tactical §9 entry, cron rows, queue-worker note | Modify |
| `tests/Feature/Tactical/TacticalWebhookTest.php` | auth/persist/dispatch/dedup/skip/poll-backstop | Create |
| `tests/Unit/Tactical/TacticalClientTest.php` | singleton, auth header, error mapping | Create |
| `tests/Feature/Tactical/TacticalAlertServiceTest.php` | upsert/resolve + severity/noise filters | Create |
| `tests/Feature/Tactical/TacticalSchemaDriftTest.php` | pinned-contract guard | Create |
| `tests/Fixtures/tactical/{alert_failure,alert_resolved,api_schema}.json` | payloads | Create |

---

### Task 1: Durable + replay-safe inbound webhook

Tactical's outbound `URLAction` has an 8 s timeout and **no retry**, so PSA must ack fast and
process async. It can also **double-deliver** (proxy/retry), and the static webhook key is
replayable — so unlike the Ninja analog, we add an **idempotency key** and validate the payload.

**Files:** Create migration/model/job; modify `TacticalWebhookController`. Test: `tests/Feature/Tactical/TacticalWebhookTest.php`.

- [ ] **Step 1: Write the failing tests**

```php
public function test_valid_webhook_persists_queues_and_acks_fast(): void {
    Queue::fake();
    $payload = $this->fixture('alert_failure.json');
    $this->withHeaders(['X-Webhook-Key' => $this->validWebhookKey()])
         ->postJson('/api/webhooks/tactical', $payload)->assertOk();
    $this->assertDatabaseHas('tactical_webhooks', ['event' => 'alert_failure', 'status' => 'pending']);
    Queue::assertPushed(ProcessTacticalWebhook::class);
}
public function test_invalid_key_rejected_and_not_persisted(): void {
    Queue::fake();
    $this->withHeaders(['X-Webhook-Key' => 'wrong'])
         ->postJson('/api/webhooks/tactical', ['event' => 'alert_failure'])->assertStatus(401);
    $this->assertDatabaseCount('tactical_webhooks', 0);
    Queue::assertNotPushed(ProcessTacticalWebhook::class);
}
public function test_duplicate_delivery_is_deduped(): void {
    Queue::fake();
    $p = $this->fixture('alert_failure.json'); // carries a tactical alert id
    $h = ['X-Webhook-Key' => $this->validWebhookKey()];
    $this->withHeaders($h)->postJson('/api/webhooks/tactical', $p)->assertOk();
    $this->withHeaders($h)->postJson('/api/webhooks/tactical', $p)->assertOk(); // replay
    $this->assertDatabaseCount('tactical_webhooks', 1);
    Queue::assertPushed(ProcessTacticalWebhook::class, 1);
}
public function test_malformed_payload_is_rejected(): void {
    $this->withHeaders(['X-Webhook-Key' => $this->validWebhookKey()])
         ->postJson('/api/webhooks/tactical', ['garbage' => true])->assertStatus(422);
}
public function test_unknown_event_is_skipped_not_failed(): void {
    $row = TacticalWebhook::factory()->create(['event' => 'something_else', 'status' => 'pending']);
    (new ProcessTacticalWebhook($row->id))->handle(app(TacticalAlertService::class));
    $this->assertEquals('skipped', $row->fresh()->status);
}
public function test_below_threshold_alert_persists_as_skipped_with_payload_retained(): void {
    // severity below tactical_alert_min_severity → handled, but row retained as skipped, payload intact
}
public function test_hourly_poll_reconciles_a_webhook_the_job_dropped(): void {
    // a failed/never-processed alert is still resolved by tactical:reconcile-alerts (the backstop)
}
```

- [ ] **Step 2: Run → confirm fail.** `php artisan test --filter=TacticalWebhookTest` → FAIL.

- [ ] **Step 3: Migration + model.** Mirror `ninja_webhooks` columns (`event`, `agent_id` indexed,
  `payload` json, `status` default `pending`, `attempts` unsignedSmallInteger default 0,
  `processed_at` nullable, `error` text nullable, timestamps) **plus** a nullable
  `dedup_key` string with a **unique index**. `TacticalWebhook` model: `payload` cast array;
  helpers copied from `NinjaWebhook`: `isPending()`, `markProcessed()`, `markSkipped(string $reason)`,
  `markFailed(string $error)` (attempts-aware: stay `pending` until `attempts >= 3`, then `failed`).
  Add a model factory.

- [ ] **Step 4: Job.** Mirror `ProcessNinjaWebhook`: `public $tries = 3; public $backoff = [60, 300];`
  ctor takes the webhook id; `handle(TacticalAlertService $svc)` guards `! $row->isPending()` (idempotent
  retry), routes `alert_resolved` → `handleAlertResolved`, `alert_failure` → `handleAlertFailure`,
  **anything else → `markSkipped('unhandled event')`** (not failure); set `markProcessed`/`markSkipped`
  per outcome; implement `failed(Throwable $e)` → `markFailed`.

- [ ] **Step 5: Controller.** In `handle()`: after `VerifyTacticalWebhookKey`, **validate** the JSON
  (required `event`; bounded size), compute `dedup_key` (`event` + Tactical alert id when present,
  else a hash of the canonical payload), `firstOrCreate` on `dedup_key` to drop replays, dispatch
  `ProcessTacticalWebhook` only for newly-created rows, and `return response()->noContent()`. Remove
  the inline synchronous processing. **Do not log full payloads** on error — log `event`+`agent_id` only.

- [ ] **Step 6: Run → confirm pass.** `php artisan test --filter=TacticalWebhookTest` → PASS.

- [ ] **Step 7: Commit.** `git add ...; git commit -m "feat(tactical): durable, replay-safe inbound webhooks (P1)"`

---

### Task 2: Bind `TacticalClient` as a DI singleton

**Files:** Modify `AppServiceProvider`; replace direct instantiation. Test: `tests/Unit/Tactical/TacticalClientTest.php`.

- [ ] **Step 1: Failing test.**
```php
public function test_tactical_client_is_a_singleton(): void {
    $this->assertSame(app(TacticalClient::class), app(TacticalClient::class));
}
```
- [ ] **Step 2: Run → fail.**
- [ ] **Step 3: Add binding** next to `NinjaClient`/`MeshClient` in `AppServiceProvider::register`:
  `$this->app->singleton(\App\Services\Tactical\TacticalClient::class);`
- [ ] **Step 4: Run → pass.**
- [ ] **Step 5: Replace direct instantiation.** Use the grep as source of truth — `grep -rnE 'new \\?\\\\?App\\\\Services\\\\Tactical\\\\TacticalClient|new TacticalClient' app/` (catches fully-qualified usages). Verified current sites: `app/Console/Commands/TacticalSyncDevices.php:38`, `app/Console/Commands/TacticalSyncScripts.php:24`, `app/Services/Servosity/ServosityDeploymentService.php:339`, and the FQ `new \App\Services\Tactical\TacticalClient` in `app/Http/Controllers/Web/AssetController.php` (~712) and `app/Http/Controllers/Web/TicketController.php` (~551). Swap each to constructor injection or `app(TacticalClient::class)`. Run `php artisan test --filter=Tactical` after each file.
- [ ] **Step 6: Commit.**

---

### Task 3: Webhook-health indicator (the visible trust signal)

P1's user-visible win: surface that alerts are flowing, using the `tactical_webhooks` table Task 1
created. (Replaces the removed `ram_gb`/`os_version` task, now P4.)

**Files:** Modify `IntegrationsController` + `resources/views/settings/integrations.blade.php`. Test: extend `TacticalWebhookTest` or a small controller test.

- [ ] **Step 1: Failing test.** Assert the Tactical settings card shows last-received timestamp,
  processed count, and a **failed-count warning** when `tactical_webhooks` has `status=failed` rows.
```php
public function test_tactical_settings_shows_webhook_health(): void {
    TacticalWebhook::factory()->create(['status' => 'processed', 'processed_at' => now()->subMinutes(5)]);
    TacticalWebhook::factory()->create(['status' => 'failed']);
    $this->actingAsStaff()->get(route('settings.integrations'))
         ->assertSee('Last alert received')->assertSee('1 failed');
}
```
- [ ] **Step 2: Run → fail.**
- [ ] **Step 3: Implement.** In `IntegrationsController`, compute `{last_received_at, processed_24h, failed_open}` from `tactical_webhooks`; render a small health row on the Tactical card (Bootstrap, no JS needed). Failed-count links to a filtered view or simply warns.
- [ ] **Step 4: Run → pass.**
- [ ] **Step 5: Commit.**

---

### Task 4: Schema-drift guard (pinned-contract)

A **pinned-contract** test — it catches our assumptions diverging from the last-validated schema (and forces a conscious refresh); it does not auto-detect upstream drift (that would require hitting a live `/api/schema/` in CI, which we don't want).

**Files:** Create `tests/Feature/Tactical/TacticalSchemaDriftTest.php` + `tests/Fixtures/tactical/api_schema.json`. Modify `docs/INSTALL.md`.

- [ ] **Step 1: Test.** Maintain `EXPECTED_AGENT_FIELDS`/`EXPECTED_ALERT_FIELDS` arrays; assert each exists in the checked-in trimmed `/api/schema/` snapshot; failure message names the field + the refresh command.
- [ ] **Step 2: Run → pass.**
- [ ] **Step 3: Document** in `INSTALL.md` (Tactical §9): how to dump `/api/schema/` (`SWAGGER_ENABLED`), where the snapshot lives, the pinned Tactical version, and that this is a periodic manual refresh.
- [ ] **Step 4: Commit.**

---

### Task 5: Characterization tests for untested core

Lock in **current** behaviour of the previously-untested client + alert service (no behaviour change here — distinct from Tasks 1/3 which add behaviour).

**Files:** `tests/Unit/Tactical/TacticalClientTest.php`, `tests/Feature/Tactical/TacticalAlertServiceTest.php`, fixtures.

- [ ] **Step 1: Client tests** — mocked Guzzle handler: assert `X-API-KEY` header sent; verb helpers hit expected paths; non-2xx → `TacticalClientException`.
- [ ] **Step 2: Alert service tests** — feed `alert_failure.json`: assert `alerts` upsert with mapped severity; below-threshold dropped; transient/noise ("retry should be performed") dropped; `handleAlertResolved` resolves the matching open alert.
- [ ] **Step 3: Run `php artisan test --filter=Tactical` → PASS.**
- [ ] **Step 4: Commit.**

---

### Task 6: Operator documentation (Documentation Manager findings)

P1 is the "trust + document" phase; close the existing Tactical doc debt.

**Files:** Modify `docs/INSTALL.md`.

- [ ] **Step 1: Add INSTALL.md §9 → "Tactical RMM"** (mirror the Level/Comet entries): Settings → Integrations → Tactical (API URL + key), Test Connection, site mapping (`settings.tactical-sites`), **webhook setup** (generate webhook key; point a Tactical AlertTemplate `URLAction(REST)` at `https://<psa-host>/api/webhooks/tactical` with the `X-Webhook-Key` header), least-privilege service-user note (spec §3 constraint 5), and the schema-refresh/version-pin note from Task 4.
- [ ] **Step 2: Add the three `tactical:*` rows to the INSTALL.md §6 cron table** — `tactical:reconcile-alerts` (hourly), `tactical:sync-devices` (daily 05:32), `tactical:sync-scripts` (daily 05:35), matching `routes/console.php`.
- [ ] **Step 3: Add a queue-worker prerequisite line** — Tactical alert webhooks are processed by a queued job; a worker is required for alert→ticket; without one, alerts won't process.
- [ ] **Step 4: Commit.** (Record in the plan that there is **no `.env.example` impact** and **no README route-table** to change — Tactical config is in `Setting`, not `.env`.)

---

## Self-Review

**Spec coverage (P1 items, §6 + amendments):** durable webhook ✅ (Task 1) **+ replay/idempotency + payload validation** (review); singleton ✅ (Task 2, corrected call sites); **visible trust signal** ✅ (Task 3, replaces the unbuildable ram/os task); schema-drift pinned-contract ✅ (Task 4); characterization coverage ✅ (Task 5); **operator docs** ✅ (Task 6). `ram_gb`/`os_version` consciously deferred to **P4** (needs detail-endpoint reads). Hourly poll reconciliation unchanged but now **tested as the backstop** (Task 1 Step 1).

**Reversibility:** P1's only schema change is one additive table (`tactical_webhooks`) with a `down()` that drops it; no changes to existing tables; MariaDB-compatible types. Reversible.

**Scope boundary (ITIL):** P1 hardens **incident intake** (alert→webhook→ticket durability). The **change/action audit trail (`tactical_action_logs`) is P2**; run-script stays on loose `Log::info` until then — P1 must not be mistaken for "actions are audited."

**Retention note:** `tactical_webhooks` (like `ninja_webhooks`) grows per-alert; a prune job is tracked for a later phase. Raw `payload` may contain `check_output` — staff-only, single-tenant; flagged, not scrubbed in P1.

**Verification before "done":** every task ends green on `php artisan test --filter=...`; full `--filter=Tactical` passes before the PR; CI "Tests & style gate" green.

---

## Execution Handoff

Implemented by a Mayor-dispatched **supervised subagent** in the `tactical-rmm-flagship` worktree,
task-by-task with review between tasks, then routed through the persona panel as a PR. Merges/deploys
are a deliberate human step (Charlie) — **no auto-deploy**.
