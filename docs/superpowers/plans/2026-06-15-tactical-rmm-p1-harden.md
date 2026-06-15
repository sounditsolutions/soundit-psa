# Tactical RMM — Phase 1 (Harden) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the existing Tactical RMM integration *trustworthy* — durable alert webhooks, consistent DI, complete synced fields, version-drift protection, and real test coverage — with zero user-visible behaviour change.

**Architecture:** This is Phase 1 of the flagship plan in `docs/superpowers/specs/2026-06-15-tactical-rmm-integration-design.md`. It hardens the foundation before the Action Bus (P2) and remote actions (P3+) land on top. Every change mirrors an existing, proven analog in the **Ninja** integration — the explicit pattern to follow.

**Tech Stack:** Laravel (PHP 8.3), MariaDB, queued jobs (database driver), Guzzle HTTP, PHPUnit/Pest via `php artisan test`. No frontend build step. Settings/secrets via `Setting` model (encrypted).

**Conventions (from `docs/REVIEW_PERSONAS.md` → Senior Developer):** services in `app/Services/`, thin controllers, vendor API clients are **constructor-injected singletons** (mirror `NinjaClient`/`MeshClient`), MariaDB-compatible migrations, file cache/queue.

**Reference analogs to read before starting:**
- `app/Http/Controllers/Api/NinjaWebhookController.php` (persist + dispatch pattern)
- `app/Jobs/ProcessNinjaWebhook.php` (queued job with retries/backoff)
- `database/migrations/2026_02_18_000002_create_ninja_webhooks_table.php` (`ninja_webhooks` schema)
- `app/Providers/AppServiceProvider.php` (singleton bindings, ~lines 40–86)
- `app/Services/Tactical/TacticalDeviceSyncService.php` (`mapAgentToTacticalAsset`)
- `app/Services/Tactical/TacticalClient.php` (auth + verb helpers)

---

## File Structure

| File | Responsibility | Action |
|---|---|---|
| `database/migrations/2026_06_15_000001_create_tactical_webhooks_table.php` | Persisted inbound webhook payloads | Create (mirror `ninja_webhooks`) |
| `app/Models/TacticalWebhook.php` | Eloquent model for the above | Create |
| `app/Jobs/ProcessTacticalWebhook.php` | Async processing of a persisted webhook via `TacticalAlertService` | Create (mirror `ProcessNinjaWebhook`) |
| `app/Http/Controllers/Api/TacticalWebhookController.php` | Persist + dispatch + 200 fast (was inline) | Modify |
| `app/Providers/AppServiceProvider.php` | Bind `TacticalClient` singleton | Modify |
| `app/Services/Tactical/TacticalDeviceSyncService.php` | Populate `ram_gb` + `os_version` | Modify (`mapAgentToTacticalAsset`) |
| `tests/Feature/Tactical/TacticalWebhookTest.php` | Webhook auth/persist/dispatch/dedupe | Create |
| `tests/Feature/Tactical/TacticalDeviceSyncTest.php` | Sync field mapping incl. new fields | Create |
| `tests/Unit/Tactical/TacticalClientTest.php` | Client auth header, verb helpers, error mapping | Create |
| `tests/Feature/Tactical/TacticalAlertServiceTest.php` | Alert upsert/resolve + severity/noise filters | Create |
| `tests/Feature/Tactical/TacticalSchemaDriftTest.php` | Guard our assumed fields vs a checked-in schema snapshot | Create |
| `tests/Fixtures/tactical/agent_detail.json`, `alert_failure.json`, `api_schema.json` | Representative payloads for the above | Create |
| `docs/INSTALL.md` | Tactical webhook + version-pin note | Modify (Doc Manager persona) |

---

### Task 1: Persist + queue the inbound webhook (durability)

The webhook currently processes inline (`TacticalWebhookController::handle`). Tactical's outbound
`URLAction` has an 8 s timeout and **no retry**, so PSA must ack fast and process async — exactly
what Ninja already does. Mirror it.

**Files:**
- Create: `database/migrations/2026_06_15_000001_create_tactical_webhooks_table.php`
- Create: `app/Models/TacticalWebhook.php`
- Create: `app/Jobs/ProcessTacticalWebhook.php`
- Modify: `app/Http/Controllers/Api/TacticalWebhookController.php`
- Test: `tests/Feature/Tactical/TacticalWebhookTest.php`

- [ ] **Step 1: Write the failing test** (`tests/Feature/Tactical/TacticalWebhookTest.php`)

```php
public function test_valid_webhook_is_persisted_and_queued_and_acked_fast(): void
{
    Queue::fake();
    config(['...']); // ensure tactical_webhook_key set via Setting (see existing test helpers)
    $payload = json_decode(file_get_contents(base_path('tests/Fixtures/tactical/alert_failure.json')), true);

    $res = $this->withHeaders(['X-Webhook-Key' => $this->validWebhookKey()])
                ->postJson('/api/webhooks/tactical', $payload);

    $res->assertOk();
    $this->assertDatabaseHas('tactical_webhooks', ['event' => 'alert_failure', 'status' => 'pending']);
    Queue::assertPushed(\App\Jobs\ProcessTacticalWebhook::class);
}

public function test_invalid_webhook_key_is_rejected_and_not_persisted(): void
{
    Queue::fake();
    $res = $this->withHeaders(['X-Webhook-Key' => 'wrong'])
                ->postJson('/api/webhooks/tactical', ['event' => 'alert_failure']);
    $res->assertStatus(401);
    $this->assertDatabaseCount('tactical_webhooks', 0);
    Queue::assertNotPushed(\App\Jobs\ProcessTacticalWebhook::class);
}
```

- [ ] **Step 2: Run it, confirm it fails**

Run: `php artisan test --filter=TacticalWebhookTest`
Expected: FAIL (`tactical_webhooks` table/model/job missing).

- [ ] **Step 3: Create the migration + model**

Mirror `database/migrations/2026_02_18_000002_create_ninja_webhooks_table.php`. Columns:
`id`, `event` (string, nullable), `agent_id` (string, nullable, indexed), `payload` (json),
`status` (string, default `pending`), `attempts` (unsignedInt default 0), `processed_at`
(timestamp nullable), `error` (text nullable), timestamps. Model `App\Models\TacticalWebhook`
with `$fillable` + `payload` cast to `array`.

- [ ] **Step 4: Create `ProcessTacticalWebhook` job**

Mirror `app/Jobs/ProcessNinjaWebhook.php`: `public $tries = 3; public $backoff = [60, 300];`
constructor takes the `TacticalWebhook` id (or model); `handle(TacticalAlertService $svc)` loads
the row, routes on `event` (`alert_resolved` → `handleAlertResolved`, else `handleAlertFailure`),
sets `status`/`processed_at`/`error`, saves.

- [ ] **Step 5: Modify the controller to persist + dispatch + 200**

In `TacticalWebhookController::handle`: after middleware auth, create a `TacticalWebhook` row from
the request JSON (`event`, `agent_id`, `payload`), dispatch `ProcessTacticalWebhook::dispatch($row->id)`,
and `return response()->noContent()` (or `response()->json(['queued' => true])`). Remove the inline
synchronous `TacticalAlertService` call. Keep the `VerifyTacticalWebhookKey` middleware + throttle.

- [ ] **Step 6: Run tests, confirm pass**

Run: `php artisan test --filter=TacticalWebhookTest`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/*tactical_webhooks* app/Models/TacticalWebhook.php app/Jobs/ProcessTacticalWebhook.php app/Http/Controllers/Api/TacticalWebhookController.php tests/Feature/Tactical/TacticalWebhookTest.php
git commit -m "feat(tactical): persist + queue inbound webhooks for durability (P1)"
```

---

### Task 2: Bind `TacticalClient` as a DI singleton

**Files:**
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Unit/Tactical/TacticalClientTest.php` (binding assertion)

- [ ] **Step 1: Write the failing test**

```php
public function test_tactical_client_is_a_singleton(): void
{
    $a = app(\App\Services\Tactical\TacticalClient::class);
    $b = app(\App\Services\Tactical\TacticalClient::class);
    $this->assertSame($a, $b);
}
```

- [ ] **Step 2: Run it, confirm it fails**

Run: `php artisan test --filter=TacticalClientTest::test_tactical_client_is_a_singleton`
Expected: FAIL (not same instance).

- [ ] **Step 3: Add the binding**

In `AppServiceProvider::register`, alongside the `NinjaClient`/`MeshClient` singletons, add
`$this->app->singleton(\App\Services\Tactical\TacticalClient::class);`.

- [ ] **Step 4: Run it, confirm pass.** Run: `php artisan test --filter=TacticalClientTest::test_tactical_client_is_a_singleton` → PASS.

- [ ] **Step 5: Replace direct instantiation at call sites**

Grep `new TacticalClient` (IntegrationsController, AssetController, TicketController,
ClientIntegrationController, the sync commands). Replace each with constructor injection or
`app(TacticalClient::class)`. Run the full suite after each file: `php artisan test --filter=Tactical`.

- [ ] **Step 6: Commit**

```bash
git add app/Providers/AppServiceProvider.php app/Http app/Console tests/Unit/Tactical/TacticalClientTest.php
git commit -m "refactor(tactical): bind TacticalClient as singleton, inject at call sites (P1)"
```

---

### Task 3: Populate `ram_gb` and `os_version` in device sync

`tactical_assets.ram_gb` and `os_version` columns exist but are never written.

**Files:**
- Modify: `app/Services/Tactical/TacticalDeviceSyncService.php` (`mapAgentToTacticalAsset`)
- Test: `tests/Feature/Tactical/TacticalDeviceSyncTest.php`
- Fixture: `tests/Fixtures/tactical/agent_detail.json`

- [ ] **Step 1: Write the failing test**

```php
public function test_sync_populates_ram_gb_and_os_version(): void
{
    $agent = json_decode(file_get_contents(base_path('tests/Fixtures/tactical/agent_detail.json')), true);
    $mapped = (new TacticalDeviceSyncService(...))->mapAgentToTacticalAssetForTest($agent); // or via a full sync with a fake client
    $this->assertEqualsWithDelta(16.0, $mapped['ram_gb'], 0.1);
    $this->assertNotEmpty($mapped['os_version']);
}
```

- [ ] **Step 2: Run it, confirm fail.** Run: `php artisan test --filter=TacticalDeviceSyncTest` → FAIL (keys missing/null).

- [ ] **Step 3: Implement the mapping**

In `mapAgentToTacticalAsset`, derive `ram_gb` from the agent payload's total-RAM field (bytes →
GB, rounded to 1 decimal — verify the exact key against the fixture / `/api/schema/`; likely
`total_ram` in bytes) and `os_version` from the OS string (the build/version portion of
`operating_system`). Guard nulls.

- [ ] **Step 4: Run it, confirm pass.** Run: `php artisan test --filter=TacticalDeviceSyncTest` → PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Tactical/TacticalDeviceSyncService.php tests/Feature/Tactical/TacticalDeviceSyncTest.php tests/Fixtures/tactical/agent_detail.json
git commit -m "feat(tactical): populate ram_gb and os_version on device sync (P1)"
```

---

### Task 4: Schema-drift guard

Tactical has no API-version contract. Catch breaking field changes early with a fixture-based
contract test + a documented refresh step.

**Files:**
- Create: `tests/Feature/Tactical/TacticalSchemaDriftTest.php`
- Create: `tests/Fixtures/tactical/api_schema.json` (trimmed `/api/schema/` snapshot for the
  agent-detail + alert components we depend on)
- Modify: `docs/INSTALL.md` (how to refresh the snapshot + the pinned tested version)

- [ ] **Step 1: Write the test**

Assert that every field `TacticalClient`/`TacticalDeviceSyncService`/`TacticalAlertService` reads
(maintain an explicit `EXPECTED_AGENT_FIELDS`/`EXPECTED_ALERT_FIELDS` array in the test) is present
in the checked-in schema snapshot. Fail with a clear message naming the missing field and the
refresh command.

```php
public function test_assumed_agent_fields_exist_in_schema_snapshot(): void
{
    $schema = json_decode(file_get_contents(base_path('tests/Fixtures/tactical/api_schema.json')), true);
    foreach (self::EXPECTED_AGENT_FIELDS as $field) {
        $this->assertTrue($this->schemaHasField($schema, 'AgentDetail', $field),
            "Tactical agent field '$field' missing from schema snapshot — Tactical may have changed. Refresh: see docs/INSTALL.md (Tactical schema).");
    }
}
```

- [ ] **Step 2: Run it, confirm pass** (snapshot includes the fields). Run: `php artisan test --filter=TacticalSchemaDriftTest`.

- [ ] **Step 3: Document refresh + version pin in `docs/INSTALL.md`** (Section 9 Optional Integrations / Tactical): how to dump `/api/schema/` (`SWAGGER_ENABLED`), where the snapshot lives, and the Tactical version it was validated against.

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/Tactical/TacticalSchemaDriftTest.php tests/Fixtures/tactical/api_schema.json docs/INSTALL.md
git commit -m "test(tactical): schema-drift guard + version-pin docs (P1)"
```

---

### Task 5: Backfill characterization tests for untested core

Lock in current behaviour of the previously-untested client + services so P2+ refactors are safe.

**Files:**
- Create: `tests/Unit/Tactical/TacticalClientTest.php` (extend Task 2's file)
- Create: `tests/Feature/Tactical/TacticalAlertServiceTest.php`
- Fixture: `tests/Fixtures/tactical/alert_failure.json`

- [ ] **Step 1: Client tests** — with a mocked Guzzle handler, assert `X-API-KEY` header is sent,
  `get/post/put/patch` hit the expected paths, and a non-2xx maps to `TacticalClientException`.

```php
public function test_requests_send_api_key_header(): void { /* mock handler captures request, assert header */ }
public function test_non_2xx_throws_tactical_exception(): void { $this->expectException(TacticalClientException::class); /* ... */ }
```

- [ ] **Step 2: Alert service tests** — feed `alert_failure.json`; assert an `alerts` row is
  upserted with the mapped severity; assert below-threshold severity is dropped; assert a
  transient/noise message ("retry should be performed") is dropped; assert `handleAlertResolved`
  resolves the matching open alert.

- [ ] **Step 3: Run the whole Tactical suite.** Run: `php artisan test --filter=Tactical` → PASS.

- [ ] **Step 4: Commit**

```bash
git add tests/Unit/Tactical/TacticalClientTest.php tests/Feature/Tactical/TacticalAlertServiceTest.php tests/Fixtures/tactical/alert_failure.json
git commit -m "test(tactical): characterization coverage for client + alert service (P1)"
```

---

## Self-Review

**Spec coverage (P1 items in §6 of the spec):** webhook ack-and-queue ✅ (Task 1); `TacticalClient`
singleton ✅ (Task 2); populate `ram_gb`/`os_version` ✅ (Task 3); schema-drift guard + version pin
✅ (Task 4); backfill test coverage ✅ (Tasks 1–5). Hourly poll reconciliation is unchanged
(already present) — no task needed. No user-visible behaviour change ✅.

**Placeholder scan:** Implementation steps point at concrete files and named analogs to mirror
(legitimate in an existing codebase) rather than vague "add error handling". Exact payload keys
(`total_ram`, OS version parsing) are flagged for verification against the fixture/`/api/schema/`
during Step 3 — the implementer confirms against real data, which is correct for an unversioned API.

**Type consistency:** `TacticalWebhook` (model), `ProcessTacticalWebhook` (job),
`tactical_webhooks` (table), `event`/`agent_id`/`payload`/`status` columns are used consistently
across Tasks 1 and 5.

**Verification before "done":** every task ends with a green `php artisan test --filter=...` run; the
full `--filter=Tactical` suite must pass before the phase PR. CI "Tests & style gate" must be green.

---

## Execution Handoff

Execution model for this city: **Mayor-orchestrated supervised subagents** (no polecat pool).
This phase will be implemented by a dispatched subagent working in the `tactical-rmm-flagship`
worktree, task-by-task, reviewed by the Mayor between tasks, then routed through the persona panel
as a PR before any merge. Merges/deploys remain a deliberate human step (Charlie), no auto-deploy.
