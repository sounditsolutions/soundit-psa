# AI Technician — Phase 0 (Foundation) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the deterministic safety substrate for the AI Technician — a Setting-backed coverage-profile config, a reused AI-actor identity, an append-only `technician_actions` audit, the sole-entry-point `TechnicianActionGate` (server-side tiering, default-deny, fail-closed, kill-switch), a `technician_runs` state machine with an idempotency key, a Loop job dispatched from `TicketObserver::created`, structural disclosure with a pre-send reject, and one end-to-end AUTO vertical slice (auto-acknowledge) — proving the whole substrate without any LLM.

**Architecture:** A new `app/Services/Technician/` package holds the gate, config reader, disclosure renderer, the run-state machine model, and the append-only audit model, mirroring the existing `app/Services/Tactical/` chokepoint pattern (`TacticalActionService` + `TacticalActionLog` + `TacticalActionConfirmToken`). The Loop is a `ShouldQueue` job dispatched from `TicketObserver::created` alongside the existing `RunTriagePipeline` dispatch (same prospect gate + system-user recursion guard). Every side-effecting Technician action flows through `TechnicianActionGate::dispatch()` — it classifies tier server-side on the resolved action (default-deny), re-checks the kill-switch immediately before execution, executes AUTO actions via a passed closure while recording non-AUTO actions as `awaiting_approval`, and always writes one immutable audit row.

**Tech Stack:** PHP 8.3, Laravel 11, Eloquent models + migrations, `Setting::getValue`/`setValue` config, PHP enums, PHPUnit feature tests on sqlite `:memory:` (`RefreshDatabase`, `Bus::fake()`, factories, `actingAs`), Laravel Pint for formatting, HMAC-SHA256 signed tokens (copying `TacticalActionConfirmToken`).

## Global Constraints

These apply to **every** task below; each task's requirements implicitly include this section. Copied from the spec (`docs/superpowers/specs/2026-06-23-ai-technician-design.md` §2/§4/§7) verbatim where noted.

- **Runtime:** PHP 8.3 / Laravel; PHPUnit with sqlite-in-memory for tests (`DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, `QUEUE_CONNECTION=sync`); MariaDB in prod.
- **Pint-clean:** run `./vendor/bin/pint --dirty` before each commit; the tree must be Pint-clean.
- **The gate is the sole action path (spec §4.3):** "The *sole* entry point for every side-effecting Technician action… The Loop holds **no** direct reference to `EmailService`/`TicketService`/`TacticalActionService` — only the gate (assert by test)."
- **Default-deny AUTO (spec §4.3a / §7):** "classifies tier **server-side on the resolved action** (default-deny: anything outside the explicit AUTO allowlist is ≥APPROVE), BLOCK is a server denylist."
- **Append-only audit (spec §4.3e):** "writes an **append-only** audit row (copy `TacticalActionLog`'s `updating`/`deleting` guards)." Immutability enforced by the model `updating`/`deleting` `LogicException` guards (covers sqlite) **and** MariaDB/MySQL `BEFORE UPDATE`/`BEFORE DELETE` triggers (driver-gated, skipped on sqlite).
- **Fail-closed (spec §4.6 / §7 / §14):** "Unreadable config / unverifiable approval / unknown action → **hold, never execute.**"
- **Server-side tier on the *resolved* action (spec §7):** "classify on the *resolved* action, default-deny for AUTO; BLOCK is server-enforced; the model proposes, the server gates." The model's self-reported tier is **never** trusted.
- **Structural disclosure appended by the sending layer (spec §6 / §7):** "the disclosed-AI banner + 'get a human' affordance are appended by the **sending layer** (template), not authored by the model, on every client-facing message; the pre-send scan rejects any body lacking it." 
- **Reuse the AI-actor System User (spec §3 / §4.6):** "**Reuse** the existing configurable 'System User (AI Actor)'… One AI staff identity across triage + technician." This is the user id behind the `triage_system_user_id` Setting (Sound IT = "Chet"); resolve it exactly as `TriageConfig::systemUserId()` does.
- **Config-not-hardcoded (spec §2.1 / §5):** "Operators + roles, coverage windows… availability signals, the AI-actor identity, SLA sources, thresholds, and per-action tiers are all **configuration/data — never hardcoded.**" All `Setting`/DB-backed, editable without a deploy.
- **`actor_label:'ai-technician'` (spec §4.3d):** every Technician action stamps the AI-actor user id **and** `actor_label = 'ai-technician'` on its audit row (mirrors the Tactical `'ai-triage'` label convention).
- **Idempotency key (spec §4.4):** `technician_runs` has a unique key on `ticket_id + action_type + content_hash` to prevent double-send under poll re-import / job retry.
- **Kill-switch (spec §4.6 / §7):** a global pause (`Setting`-backed, no deploy) re-checked **inside the gate, immediately before execution** (including in-flight); fail-closed.
- **Prospect gate + recursion guard (spec §4.1):** the Loop dispatch is prospect-gated and system-user-recursion-guarded the same way as the existing triage dispatch in `TicketObserver::created`.

---

## File Structure

Every file created or modified, with its single responsibility.

**Created:**

| Path | Responsibility |
|------|----------------|
| `app/Support/TechnicianConfig.php` | Setting-backed reader for the coverage profile: AI-actor user id (reused), kill-switch, availability toggle, escalation chain, per-action tier map, per-client overrides, thresholds. Sole config surface; fail-closed defaults. |
| `app/Enums/TechnicianTier.php` | The `Auto` / `Approve` / `Block` tier enum. |
| `app/Enums/TechnicianRunState.php` | The run state machine states: `Gathering` / `Drafting` / `AwaitingApproval` / `Executing` / `Done`. |
| `app/Services/Technician/TechnicianTierClassifier.php` | Pure, config-driven, default-deny classifier: resolved `action_type` → `TechnicianTier`. |
| `app/Services/Technician/TechnicianApprovalGrant.php` | Signed, single-use, payload-bound approval-grant token (issue + verify), copied from `TacticalActionConfirmToken`. Phase 0 only *verifies*; issuance is exercised by tests. |
| `app/Services/Technician/TechnicianActionGate.php` | THE chokepoint. `dispatch()`: classify (default-deny on resolved action) → re-check kill-switch + per-client/per-action flags → AUTO executes the passed closure, non-AUTO records `awaiting_approval` (no execution) → stamp AI-actor + `actor_label:'ai-technician'` → write one append-only audit row. Fail-closed. |
| `app/Services/Technician/TechnicianDisclosure.php` | Sending-layer wrapper: appends the disclosed-AI banner + "get a human" line to a Technician-authored client-facing body; and `assertPresent()` — a pre-send check that throws if a body lacks the structural disclosure. |
| `app/Services/Technician/AutoAcknowledge.php` | The vertical slice: composes a templated, disclosed, non-substantive acknowledgment and sends it AS AN AUTO ACTION THROUGH THE GATE, producing an AI-authored client note + audit row, and advances the run. |
| `app/Jobs/RunTechnicianLoop.php` | The Loop dispatch seam. Loads the ticket (prospect-gated), creates/loads the `technician_run`, runs the Phase-0 auto-ack. |
| `app/Models/TechnicianActionLog.php` | Append-only audit row (immutable: `UPDATED_AT=null` + `updating`/`deleting` guards). |
| `app/Models/TechnicianRun.php` | The per-ticket run-state machine record + idempotency key + state-transition helper. |
| `database/migrations/2026_06_23_000001_create_technician_action_logs_table.php` | `technician_action_logs` schema + MariaDB immutability triggers. |
| `database/migrations/2026_06_23_000002_create_technician_runs_table.php` | `technician_runs` schema + unique idempotency index. |
| `database/migrations/2026_06_23_000003_add_ai_authored_to_ticket_notes.php` | Adds the `ai_authored` boolean marker column to `ticket_notes` (the AI-authored marker, cf. `resolution_ai_drafted`). |
| `tests/Feature/Technician/TechnicianConfigTest.php` | Tests Task 1. |
| `tests/Feature/Technician/TechnicianActionLogTest.php` | Tests Task 2. |
| `tests/Feature/Technician/TechnicianTierClassifierTest.php` | Tests Task 3. |
| `tests/Feature/Technician/TechnicianActionGateTest.php` | Tests Tasks 4 + 6 (gate + kill-switch). |
| `tests/Feature/Technician/TechnicianRunTest.php` | Tests Task 5. |
| `tests/Feature/Technician/TechnicianDisclosureTest.php` | Tests Task 7. |
| `tests/Feature/Technician/TechnicianLoopDispatchTest.php` | Tests Task 8. |
| `tests/Feature/Technician/AutoAcknowledgeTest.php` | Tests Task 9 (the vertical slice). |

**Modified:**

| Path | Change |
|------|--------|
| `app/Models/TicketNote.php` | Add `ai_authored` to `$fillable` + cast to `boolean`. |
| `app/Observers/TicketObserver.php` | In `created()`, after the triage dispatch, dispatch `RunTechnicianLoop` under the same prospect gate + system-user recursion guard, gated by `TechnicianConfig::enabled()`. |

---

## Tasks

### Task 1: Coverage-profile config reader + AI-actor resolver

**Files:**
- Create: `app/Support/TechnicianConfig.php`
- Create: `app/Enums/TechnicianTier.php`
- Test: `tests/Feature/Technician/TechnicianConfigTest.php`

**Interfaces:**
- Consumes: `App\Models\Setting::getValue(string $key, mixed $default = null): mixed`; `App\Models\Setting::setValue(string $key, mixed $value): void`; `App\Models\User`.
- Produces:
  - `App\Enums\TechnicianTier` (string enum): `Auto = 'auto'`, `Approve = 'approve'`, `Block = 'block'`.
  - `App\Support\TechnicianConfig`:
    - `public static function enabled(): bool` — `technician_enabled` Setting (master on/off; default false).
    - `public static function killSwitchEngaged(): bool` — true when `technician_kill_switch` Setting is truthy (default false = not engaged).
    - `public static function aiActorUserId(): ?int` — reads `triage_system_user_id` Setting (the reused "System User (AI Actor)"); falls back to `User::orderBy('id')->value('id')` exactly like `TriageConfig::systemUserId()`.
    - `public static function tierMap(): array` — decoded JSON map `action_type => tier-string` from `technician_action_tiers` Setting; `[]` if unreadable. **Fail-closed: a missing/invalid map yields `[]`, so the classifier defaults everything to `Approve`.**
    - `public static function clientExcluded(int $clientId): bool` — true if `$clientId` is in the JSON array from `technician_excluded_client_ids` (default `[]`).
    - `public static function clientAlwaysHuman(int $clientId): bool` — true if `$clientId` is in the JSON array from `technician_always_human_client_ids` (default `[]`).
    - `public static function escalationChain(): array` — ordered `int[]` user ids from `technician_escalation_chain` JSON (default `[]`).
    - `public static function operatorCovering(): bool` — the authoritative manual "covering / not covering" toggle from `technician_operator_covering` (default true).
    - `public static function ackEtaText(): string` — the realistic-ETA placeholder string from `technician_ack_eta_text` (default `'within one business day'`).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Technician;

use App\Enums\TechnicianTier;
use App\Models\Setting;
use App\Models\User;
use App\Support\TechnicianConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TechnicianConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_disabled_and_kill_switch_default_off(): void
    {
        $this->assertFalse(TechnicianConfig::enabled());
        $this->assertFalse(TechnicianConfig::killSwitchEngaged());
    }

    public function test_enabled_and_kill_switch_read_settings(): void
    {
        Setting::setValue('technician_enabled', '1');
        Setting::setValue('technician_kill_switch', '1');

        $this->assertTrue(TechnicianConfig::enabled());
        $this->assertTrue(TechnicianConfig::killSwitchEngaged());
    }

    public function test_ai_actor_falls_back_to_first_user_then_honours_setting(): void
    {
        $first = User::factory()->create();
        $chet = User::factory()->create();

        $this->assertSame($first->id, TechnicianConfig::aiActorUserId());

        Setting::setValue('triage_system_user_id', (string) $chet->id);
        $this->assertSame($chet->id, TechnicianConfig::aiActorUserId());
    }

    public function test_tier_map_is_empty_when_unset_or_invalid(): void
    {
        $this->assertSame([], TechnicianConfig::tierMap());

        Setting::setValue('technician_action_tiers', 'not-json');
        $this->assertSame([], TechnicianConfig::tierMap());

        Setting::setValue('technician_action_tiers', json_encode([
            'send_ack' => TechnicianTier::Auto->value,
            'send_reply' => TechnicianTier::Approve->value,
        ]));
        $this->assertSame([
            'send_ack' => 'auto',
            'send_reply' => 'approve',
        ], TechnicianConfig::tierMap());
    }

    public function test_per_client_overrides_and_defaults(): void
    {
        $this->assertFalse(TechnicianConfig::clientExcluded(7));
        $this->assertFalse(TechnicianConfig::clientAlwaysHuman(7));
        $this->assertTrue(TechnicianConfig::operatorCovering());
        $this->assertSame([], TechnicianConfig::escalationChain());

        Setting::setValue('technician_excluded_client_ids', json_encode([7, 9]));
        Setting::setValue('technician_always_human_client_ids', json_encode([7]));
        Setting::setValue('technician_operator_covering', '0');
        Setting::setValue('technician_escalation_chain', json_encode([3, 1]));

        $this->assertTrue(TechnicianConfig::clientExcluded(7));
        $this->assertFalse(TechnicianConfig::clientExcluded(8));
        $this->assertTrue(TechnicianConfig::clientAlwaysHuman(7));
        $this->assertFalse(TechnicianConfig::operatorCovering());
        $this->assertSame([3, 1], TechnicianConfig::escalationChain());
    }

    public function test_ack_eta_text_default_and_override(): void
    {
        $this->assertSame('within one business day', TechnicianConfig::ackEtaText());

        Setting::setValue('technician_ack_eta_text', 'by end of next business day');
        $this->assertSame('by end of next business day', TechnicianConfig::ackEtaText());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=TechnicianConfigTest`
Expected: FAIL — `Error: Class "App\Support\TechnicianConfig" not found` (and `App\Enums\TechnicianTier` not found).

- [ ] **Step 3: Write the enum**

Create `app/Enums/TechnicianTier.php`:

```php
<?php

namespace App\Enums;

/**
 * The autonomy tier of a resolved Technician action (spec §3/§7).
 *
 *  Auto    — safe/reversible/draft: executes through the gate without a human.
 *  Approve — client-facing or state-changing: held for a signed human approval.
 *  Block   — never: server denylist, refused outright.
 *
 * The gate classifies SERVER-SIDE on the resolved action and default-denies:
 * anything not explicitly mapped to Auto is treated as ≥Approve.
 */
enum TechnicianTier: string
{
    case Auto = 'auto';
    case Approve = 'approve';
    case Block = 'block';
}
```

- [ ] **Step 4: Write the config reader**

Create `app/Support/TechnicianConfig.php`:

```php
<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\User;

/**
 * Setting-backed coverage-profile reader (spec §5). Everything is data — no
 * hardcoded operator/trip. Fail-closed: unreadable JSON yields the safe empty
 * default (an empty tier map default-denies every action in the classifier).
 */
class TechnicianConfig
{
    /** Master on/off for the whole Technician subsystem. */
    public static function enabled(): bool
    {
        return (bool) Setting::getValue('technician_enabled');
    }

    /** Global pause — re-checked inside the gate immediately before execution. */
    public static function killSwitchEngaged(): bool
    {
        return (bool) Setting::getValue('technician_kill_switch');
    }

    /**
     * The reused "System User (AI Actor)" id (spec §3/§4.6). Same selection as
     * TriageConfig::systemUserId(): the configured setting, else the first user.
     */
    public static function aiActorUserId(): ?int
    {
        $configured = Setting::getValue('triage_system_user_id');

        if ($configured) {
            return (int) $configured;
        }

        return User::orderBy('id')->value('id');
    }

    /**
     * action_type => tier-string map (data, not code). Invalid/missing → [],
     * which the classifier reads as "default-deny everything to Approve".
     *
     * @return array<string, string>
     */
    public static function tierMap(): array
    {
        return self::decodeMap('technician_action_tiers');
    }

    public static function clientExcluded(int $clientId): bool
    {
        return in_array($clientId, self::decodeList('technician_excluded_client_ids'), true);
    }

    public static function clientAlwaysHuman(int $clientId): bool
    {
        return in_array($clientId, self::decodeList('technician_always_human_client_ids'), true);
    }

    /** @return array<int, int> ordered user ids */
    public static function escalationChain(): array
    {
        return array_values(array_map('intval', self::decodeList('technician_escalation_chain')));
    }

    /** Authoritative manual "covering / not covering" toggle (default: covering). */
    public static function operatorCovering(): bool
    {
        $value = Setting::getValue('technician_operator_covering');

        return $value === null || (bool) $value;
    }

    public static function ackEtaText(): string
    {
        $value = Setting::getValue('technician_ack_eta_text');

        return is_string($value) && $value !== '' ? $value : 'within one business day';
    }

    /** @return array<string, string> */
    private static function decodeMap(string $key): array
    {
        $raw = Setting::getValue($key);
        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? array_map('strval', $decoded) : [];
    }

    /** @return array<int, mixed> */
    private static function decodeList(string $key): array
    {
        $raw = Setting::getValue($key);
        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=TechnicianConfigTest`
Expected: PASS (6 tests).

- [ ] **Step 6: Commit**

```bash
./vendor/bin/pint --dirty
git add app/Enums/TechnicianTier.php app/Support/TechnicianConfig.php tests/Feature/Technician/TechnicianConfigTest.php
git commit -m "feat(technician): coverage-profile config reader + AI-actor resolver + tier enum"
```

---

### Task 2: Append-only `technician_action_logs` model + migration

**Files:**
- Create: `database/migrations/2026_06_23_000001_create_technician_action_logs_table.php`
- Create: `app/Models/TechnicianActionLog.php`
- Test: `tests/Feature/Technician/TechnicianActionLogTest.php`

**Interfaces:**
- Consumes: `App\Models\User`, `App\Models\Ticket`, `App\Models\Client`.
- Produces:
  - `App\Models\TechnicianActionLog` (Eloquent), `$fillable`: `actor_id`, `actor_label`, `action_type`, `tier`, `result_status`, `ticket_id`, `client_id`, `run_id`, `content_hash`, `summary`, `correlation_id`. `UPDATED_AT = null`. Casts: `created_at => datetime`. `updating`/`deleting` throw `LogicException('technician_action_logs is append-only')`. Relations: `actor()` → `User` (`actor_id`), `ticket()` → `Ticket`, `client()` → `Client`.
  - Columns/semantics: `result_status` ∈ `{executed, awaiting_approval, blocked, held}`; `tier` ∈ `{auto, approve, block}`; `actor_label` is always `'ai-technician'` for Technician actions.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Technician;

use App\Models\TechnicianActionLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class TechnicianActionLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_row_can_be_created(): void
    {
        $log = TechnicianActionLog::create([
            'actor_id' => null,
            'actor_label' => 'ai-technician',
            'action_type' => 'send_ack',
            'tier' => 'auto',
            'result_status' => 'executed',
            'ticket_id' => null,
            'client_id' => null,
            'run_id' => null,
            'content_hash' => str_repeat('a', 64),
            'summary' => 'Auto-acknowledged the client.',
            'correlation_id' => 'c0ffee',
        ]);

        $this->assertDatabaseHas('technician_action_logs', [
            'id' => $log->id,
            'actor_label' => 'ai-technician',
            'action_type' => 'send_ack',
            'result_status' => 'executed',
        ]);
        $this->assertNull($log->updated_at ?? null);
    }

    public function test_updating_a_row_throws(): void
    {
        $log = TechnicianActionLog::create([
            'actor_label' => 'ai-technician',
            'action_type' => 'send_ack',
            'tier' => 'auto',
            'result_status' => 'executed',
            'content_hash' => str_repeat('b', 64),
            'summary' => 'x',
            'correlation_id' => 'abc',
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('technician_action_logs is append-only');

        $log->update(['summary' => 'tampered']);
    }

    public function test_deleting_a_row_throws(): void
    {
        $log = TechnicianActionLog::create([
            'actor_label' => 'ai-technician',
            'action_type' => 'send_ack',
            'tier' => 'auto',
            'result_status' => 'executed',
            'content_hash' => str_repeat('c', 64),
            'summary' => 'x',
            'correlation_id' => 'def',
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('technician_action_logs is append-only');

        $log->delete();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=TechnicianActionLogTest`
Expected: FAIL — `Class "App\Models\TechnicianActionLog" not found`.

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_06_23_000001_create_technician_action_logs_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * technician_action_logs — the immutable, append-only audit trail for every
 * side-effecting AI-Technician action (spec §4.3/§4.6). Mirrors
 * tactical_action_logs: model guards cover SQLite; MariaDB/MySQL triggers block
 * even raw query-builder writes (driver-gated).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('technician_action_logs', function (Blueprint $table) {
            $table->id();
            // Actor: the reused AI-actor user; actor_label is always 'ai-technician'.
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_label');                 // always 'ai-technician'
            $table->string('action_type')->index();         // e.g. send_ack, send_reply
            $table->string('tier');                         // auto|approve|block (resolved server-side)
            $table->string('result_status')->index();       // executed|awaiting_approval|blocked|held
            $table->foreignId('ticket_id')->nullable()->constrained('tickets')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            // run_id is a plain (nullable) FK-less column to avoid an ordering
            // dependency on the technician_runs migration; constrained later if needed.
            $table->unsignedBigInteger('run_id')->nullable()->index();
            $table->string('content_hash', 64)->index();    // sha256 of the action payload
            $table->text('summary');                        // human-readable one-liner
            $table->string('correlation_id')->index();
            // Append-only: created_at ONLY (no updated_at).
            $table->timestamp('created_at')->nullable();
        });

        // DB-layer immutability — MariaDB/MySQL only (skipped on SQLite tests).
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared('DROP TRIGGER IF EXISTS technician_action_logs_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS technician_action_logs_no_delete');
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER technician_action_logs_no_update
                BEFORE UPDATE ON technician_action_logs
                FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'technician_action_logs is append-only';
            SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER technician_action_logs_no_delete
                BEFORE DELETE ON technician_action_logs
                FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'technician_action_logs is append-only';
            SQL);
        }
    }

    public function down(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared('DROP TRIGGER IF EXISTS technician_action_logs_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS technician_action_logs_no_delete');
        }

        Schema::dropIfExists('technician_action_logs');
    }
};
```

- [ ] **Step 4: Write the model**

Create `app/Models/TechnicianActionLog.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only audit row for an AI-Technician action dispatch (spec §4.3/§4.6).
 *
 * Immutable by design: UPDATED_AT is disabled and the boot updating/deleting
 * guards throw. DB triggers (MariaDB/MySQL) are the defence-in-depth layer that
 * also blocks raw query-builder writes; this model guard covers SQLite (tests).
 *
 * @property int $id
 * @property int|null $actor_id
 * @property string $actor_label
 * @property string $action_type
 * @property string $tier
 * @property string $result_status
 * @property int|null $ticket_id
 * @property int|null $client_id
 * @property int|null $run_id
 * @property string $content_hash
 * @property string $summary
 * @property string $correlation_id
 * @property \Illuminate\Support\Carbon|null $created_at
 */
class TechnicianActionLog extends Model
{
    /** Append-only: no updated_at column / timestamp. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'actor_id',
        'actor_label',
        'action_type',
        'tier',
        'result_status',
        'ticket_id',
        'client_id',
        'run_id',
        'content_hash',
        'summary',
        'correlation_id',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Append-only: block any mutation of an existing row. `updating` only
        // fires for rows that already exist, so inserts pass through.
        static::updating(function (self $log): void {
            throw new LogicException('technician_action_logs is append-only');
        });

        static::deleting(function (self $log): void {
            throw new LogicException('technician_action_logs is append-only');
        });
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=TechnicianActionLogTest`
Expected: PASS (3 tests).

- [ ] **Step 6: Commit**

```bash
./vendor/bin/pint --dirty
git add database/migrations/2026_06_23_000001_create_technician_action_logs_table.php app/Models/TechnicianActionLog.php tests/Feature/Technician/TechnicianActionLogTest.php
git commit -m "feat(technician): append-only technician_action_logs model + migration"
```

---

### Task 3: The tier classifier (config-driven, default-deny, server-side)

**Files:**
- Create: `app/Services/Technician/TechnicianTierClassifier.php`
- Test: `tests/Feature/Technician/TechnicianTierClassifierTest.php`

**Interfaces:**
- Consumes: `App\Support\TechnicianConfig::tierMap(): array<string,string>`; `App\Enums\TechnicianTier`.
- Produces:
  - `App\Services\Technician\TechnicianTierClassifier`:
    - `public function classify(string $actionType): TechnicianTier` — looks up `$actionType` in `TechnicianConfig::tierMap()`. Returns `TechnicianTier::Block` if mapped to `'block'`; `TechnicianTier::Auto` **only** if explicitly mapped to `'auto'`; otherwise (unmapped, unknown string, or `'approve'`) returns `TechnicianTier::Approve` (**default-deny**). The model's self-reported tier is never an input.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Technician;

use App\Enums\TechnicianTier;
use App\Models\Setting;
use App\Services\Technician\TechnicianTierClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TechnicianTierClassifierTest extends TestCase
{
    use RefreshDatabase;

    private function setTiers(array $map): void
    {
        Setting::setValue('technician_action_tiers', json_encode($map));
    }

    public function test_unmapped_action_defaults_to_approve(): void
    {
        $this->assertSame(
            TechnicianTier::Approve,
            (new TechnicianTierClassifier)->classify('send_reply'),
        );
    }

    public function test_explicit_auto_is_honoured(): void
    {
        $this->setTiers(['send_ack' => 'auto']);

        $this->assertSame(
            TechnicianTier::Auto,
            (new TechnicianTierClassifier)->classify('send_ack'),
        );
    }

    public function test_explicit_block_is_honoured(): void
    {
        $this->setTiers(['run_script' => 'block']);

        $this->assertSame(
            TechnicianTier::Block,
            (new TechnicianTierClassifier)->classify('run_script'),
        );
    }

    public function test_unknown_tier_string_is_treated_as_approve(): void
    {
        $this->setTiers(['send_reply' => 'totally-bogus']);

        $this->assertSame(
            TechnicianTier::Approve,
            (new TechnicianTierClassifier)->classify('send_reply'),
        );
    }

    public function test_empty_map_default_denies_everything(): void
    {
        // No setting at all → empty map → Approve (never Auto).
        $this->assertSame(
            TechnicianTier::Approve,
            (new TechnicianTierClassifier)->classify('send_ack'),
        );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=TechnicianTierClassifierTest`
Expected: FAIL — `Class "App\Services\Technician\TechnicianTierClassifier" not found`.

- [ ] **Step 3: Write the classifier**

Create `app/Services/Technician/TechnicianTierClassifier.php`:

```php
<?php

namespace App\Services\Technician;

use App\Enums\TechnicianTier;
use App\Support\TechnicianConfig;

/**
 * Classifies a RESOLVED action type to a tier, server-side, default-deny
 * (spec §4.3/§7). The model's self-reported tier is never consulted — only the
 * config tier map. Anything not explicitly mapped to 'auto' is ≥Approve.
 */
class TechnicianTierClassifier
{
    public function classify(string $actionType): TechnicianTier
    {
        $mapped = TechnicianConfig::tierMap()[$actionType] ?? null;

        return match ($mapped) {
            TechnicianTier::Auto->value => TechnicianTier::Auto,
            TechnicianTier::Block->value => TechnicianTier::Block,
            default => TechnicianTier::Approve, // default-deny
        };
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=TechnicianTierClassifierTest`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint --dirty
git add app/Services/Technician/TechnicianTierClassifier.php tests/Feature/Technician/TechnicianTierClassifierTest.php
git commit -m "feat(technician): default-deny server-side tier classifier"
```

---

### Task 4: The signed approval grant + the `TechnicianActionGate` (classify, fail-closed, audit-write)

This task builds the chokepoint and its signed-grant verifier together because the gate's non-AUTO path depends on the grant verify. The kill-switch re-check is wired here too but its dedicated tests live in Task 6 (which adds no new code if it stays green).

**Files:**
- Create: `app/Services/Technician/TechnicianApprovalGrant.php`
- Create: `app/Services/Technician/TechnicianActionGate.php`
- Test: `tests/Feature/Technician/TechnicianActionGateTest.php`

**Interfaces:**
- Consumes: `App\Services\Technician\TechnicianTierClassifier::classify(string): TechnicianTier`; `App\Support\TechnicianConfig::{killSwitchEngaged(),aiActorUserId(),clientExcluded(int),clientAlwaysHuman(int)}`; `App\Models\TechnicianActionLog::create(array)`; `App\Enums\TechnicianTier`; `Illuminate\Support\Str::uuid()`.
- Produces:
  - `App\Services\Technician\TechnicianApprovalGrant` (copied from `TacticalActionConfirmToken`):
    - `public static function issue(string $actionType, int $ticketId, string $contentHash, ?int $approverUserId): string`
    - `public static function verify(string $token, string $actionType, int $ticketId, string $contentHash, ?int $approverUserId): bool` — HMAC-SHA256 over `{actionType, ticketId, contentHash, approverUserId, expiresAt}` keyed on `app.key`; single constant-time `hash_equals`; TTL `TechnicianApprovalGrant::TTL_SECONDS = 600`; any decode/shape failure → `false`.
  - `App\Services\Technician\TechnicianActionResult` (a tiny readonly DTO, defined inside the gate file): `public function __construct(public readonly string $status, public readonly TechnicianTier $tier, public readonly TechnicianActionLog $log)`. `$status` ∈ `{executed, awaiting_approval, blocked, held}`.
  - `App\Services\Technician\TechnicianActionGate`:
    - `public function dispatch(string $actionType, int $ticketId, ?int $clientId, string $contentHash, string $summary, ?int $runId, callable $executor, ?string $approvalToken = null, ?int $approverUserId = null): TechnicianActionResult`
    - Algorithm (in order): (1) classify `$actionType` server-side. (2) If `TechnicianConfig::killSwitchEngaged()` → audit `held` (no execution), return. (3) If `$clientId !== null` and `clientExcluded($clientId)` → audit `held`, return. (4) If tier is `Block` → audit `blocked`, return. (5) If tier is `Approve` **or** (`$clientId !== null` and `clientAlwaysHuman($clientId)`): require a valid grant — if `$approvalToken` is null **or** `TechnicianApprovalGrant::verify(...)` is false → audit `awaiting_approval` (no execution), return; if valid → fall through to execute. (6) Execute: re-check `killSwitchEngaged()` **again** immediately before calling `$executor` (in-flight halt) — if engaged now → audit `held`, return without executing; else call `$executor()`, audit `executed`, return. Every path stamps `actor_id = TechnicianConfig::aiActorUserId()`, `actor_label = 'ai-technician'`, `tier`, a fresh `correlation_id`, `content_hash`, `summary`. **An exception thrown by `$executor` propagates** (caller's job framework records the failure) — but the gate writes no `executed` row in that case because the audit-write follows a successful `$executor()` call. Fail-closed throughout.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Technician;

use App\Enums\TechnicianTier;
use App\Models\Setting;
use App\Models\TechnicianActionLog;
use App\Models\User;
use App\Services\Technician\TechnicianActionGate;
use App\Services\Technician\TechnicianApprovalGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TechnicianActionGateTest extends TestCase
{
    use RefreshDatabase;

    private int $actorId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actorId = User::factory()->create()->id; // first user = AI actor fallback
    }

    private function gate(): TechnicianActionGate
    {
        return app(TechnicianActionGate::class);
    }

    private function autoTier(string $type): void
    {
        Setting::setValue('technician_action_tiers', json_encode([$type => 'auto']));
    }

    public function test_auto_action_executes_and_audits_executed(): void
    {
        $this->autoTier('send_ack');
        $ran = false;

        $result = $this->gate()->dispatch(
            actionType: 'send_ack',
            ticketId: 10,
            clientId: 5,
            contentHash: str_repeat('a', 64),
            summary: 'ack',
            runId: 1,
            executor: function () use (&$ran) { $ran = true; },
        );

        $this->assertTrue($ran);
        $this->assertSame('executed', $result->status);
        $this->assertSame(TechnicianTier::Auto, $result->tier);
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'send_ack',
            'result_status' => 'executed',
            'actor_label' => 'ai-technician',
            'actor_id' => $this->actorId,
            'tier' => 'auto',
        ]);
    }

    public function test_approve_action_without_grant_records_awaiting_and_does_not_execute(): void
    {
        // 'send_reply' is unmapped → default-deny → Approve.
        $ran = false;

        $result = $this->gate()->dispatch(
            actionType: 'send_reply',
            ticketId: 10,
            clientId: 5,
            contentHash: str_repeat('b', 64),
            summary: 'reply',
            runId: 1,
            executor: function () use (&$ran) { $ran = true; },
        );

        $this->assertFalse($ran);
        $this->assertSame('awaiting_approval', $result->status);
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'send_reply',
            'result_status' => 'awaiting_approval',
            'tier' => 'approve',
        ]);
    }

    public function test_approve_action_with_valid_grant_executes(): void
    {
        $approver = User::factory()->create();
        $hash = str_repeat('c', 64);
        $token = TechnicianApprovalGrant::issue('send_reply', 10, $hash, $approver->id);
        $ran = false;

        $result = $this->gate()->dispatch(
            actionType: 'send_reply',
            ticketId: 10,
            clientId: 5,
            contentHash: $hash,
            summary: 'reply',
            runId: 1,
            executor: function () use (&$ran) { $ran = true; },
            approvalToken: $token,
            approverUserId: $approver->id,
        );

        $this->assertTrue($ran);
        $this->assertSame('executed', $result->status);
    }

    public function test_grant_for_a_different_hash_is_rejected(): void
    {
        $approver = User::factory()->create();
        $token = TechnicianApprovalGrant::issue('send_reply', 10, str_repeat('c', 64), $approver->id);
        $ran = false;

        $result = $this->gate()->dispatch(
            actionType: 'send_reply',
            ticketId: 10,
            clientId: 5,
            contentHash: str_repeat('d', 64), // different content than the grant
            summary: 'reply',
            runId: 1,
            executor: function () use (&$ran) { $ran = true; },
            approvalToken: $token,
            approverUserId: $approver->id,
        );

        $this->assertFalse($ran);
        $this->assertSame('awaiting_approval', $result->status);
    }

    public function test_block_tier_is_refused(): void
    {
        Setting::setValue('technician_action_tiers', json_encode(['run_script' => 'block']));
        $ran = false;

        $result = $this->gate()->dispatch(
            actionType: 'run_script',
            ticketId: 10,
            clientId: 5,
            contentHash: str_repeat('e', 64),
            summary: 'script',
            runId: 1,
            executor: function () use (&$ran) { $ran = true; },
        );

        $this->assertFalse($ran);
        $this->assertSame('blocked', $result->status);
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'run_script',
            'result_status' => 'blocked',
            'tier' => 'block',
        ]);
    }

    public function test_always_human_client_forces_approval_even_for_auto_action(): void
    {
        $this->autoTier('send_ack');
        Setting::setValue('technician_always_human_client_ids', json_encode([5]));
        $ran = false;

        $result = $this->gate()->dispatch(
            actionType: 'send_ack',
            ticketId: 10,
            clientId: 5,
            contentHash: str_repeat('f', 64),
            summary: 'ack',
            runId: 1,
            executor: function () use (&$ran) { $ran = true; },
        );

        $this->assertFalse($ran);
        $this->assertSame('awaiting_approval', $result->status);
    }

    public function test_excluded_client_is_held(): void
    {
        $this->autoTier('send_ack');
        Setting::setValue('technician_excluded_client_ids', json_encode([5]));
        $ran = false;

        $result = $this->gate()->dispatch(
            actionType: 'send_ack',
            ticketId: 10,
            clientId: 5,
            contentHash: str_repeat('a', 64),
            summary: 'ack',
            runId: 1,
            executor: function () use (&$ran) { $ran = true; },
        );

        $this->assertFalse($ran);
        $this->assertSame('held', $result->status);
        $this->assertDatabaseHas('technician_action_logs', [
            'result_status' => 'held',
        ]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=TechnicianActionGateTest`
Expected: FAIL — `Class "App\Services\Technician\TechnicianActionGate" not found`.

- [ ] **Step 3: Write the approval grant**

Create `app/Services/Technician/TechnicianApprovalGrant.php`:

```php
<?php

namespace App\Services\Technician;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;

/**
 * A short-lived, signed approval grant for a non-AUTO Technician action
 * (spec §4.5/§7). Copied from TacticalActionConfirmToken: HMAC-bound to the
 * tuple {action_type, ticket_id, content_hash, approver_user_id, expires_at} so
 * it cannot be replayed against a different action, ticket, content, or approver.
 *
 * Phase 0 only VERIFIES grants at the gate (no human-approval UI yet); issuance
 * exists so the verify path is testable and the full approval round-trip
 * (cockpit / Teams) can extend it in Phase 1.
 */
class TechnicianApprovalGrant
{
    /** Time-to-live in seconds (~10 min). */
    public const TTL_SECONDS = 600;

    public static function issue(
        string $actionType,
        int $ticketId,
        string $contentHash,
        ?int $approverUserId,
    ): string {
        $expiresAt = Carbon::now()->getTimestamp() + self::TTL_SECONDS;

        $payload = self::payload($actionType, $ticketId, $contentHash, $approverUserId, $expiresAt);
        $signature = self::sign($payload);

        $envelope = json_encode(['p' => $payload, 's' => $signature]);

        return rtrim(strtr(base64_encode($envelope), '+/', '-_'), '=');
    }

    public static function verify(
        string $token,
        string $actionType,
        int $ticketId,
        string $contentHash,
        ?int $approverUserId,
    ): bool {
        $decoded = base64_decode(strtr($token, '-_', '+/'), true);
        if ($decoded === false || $decoded === '') {
            return false;
        }

        $envelope = json_decode($decoded, true);
        if (! is_array($envelope) || ! isset($envelope['p'], $envelope['s']) || ! is_array($envelope['p'])) {
            return false;
        }

        $claimedExpiry = $envelope['p']['e'] ?? null;
        if (! is_int($claimedExpiry)) {
            return false;
        }

        $expectedPayload = self::payload($actionType, $ticketId, $contentHash, $approverUserId, $claimedExpiry);
        $expectedSignature = self::sign($expectedPayload);

        $providedSignature = is_string($envelope['s']) ? $envelope['s'] : '';
        if (! hash_equals($expectedSignature, $providedSignature)) {
            return false;
        }

        return Carbon::now()->getTimestamp() <= $claimedExpiry;
    }

    /**
     * @return array{a: string, t: int, h: string, u: int|null, e: int}
     */
    private static function payload(
        string $actionType,
        int $ticketId,
        string $contentHash,
        ?int $approverUserId,
        int $expiresAt,
    ): array {
        return [
            'a' => $actionType,
            't' => $ticketId,
            'h' => $contentHash,
            'u' => $approverUserId,
            'e' => $expiresAt,
        ];
    }

    private static function sign(array $payload): string
    {
        return hash_hmac('sha256', json_encode($payload), self::key());
    }

    private static function key(): string
    {
        $key = (string) Config::get('app.key');

        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            if ($decoded !== false) {
                return $decoded;
            }
        }

        return $key;
    }
}
```

- [ ] **Step 4: Write the gate (and its result DTO)**

Create `app/Services/Technician/TechnicianActionGate.php`:

```php
<?php

namespace App\Services\Technician;

use App\Enums\TechnicianTier;
use App\Models\TechnicianActionLog;
use App\Support\TechnicianConfig;
use Illuminate\Support\Str;

/**
 * The normalized outcome of a gate dispatch.
 *
 *  executed           — an AUTO (or validly-approved) action ran.
 *  awaiting_approval  — a non-AUTO action recorded, NOT executed (Phase 0:
 *                       no approval round-trip yet; the run holds).
 *  blocked            — a server-denylisted (BLOCK) action refused.
 *  held               — kill-switch engaged or client excluded; fail-closed.
 */
final class TechnicianActionResult
{
    public function __construct(
        public readonly string $status,
        public readonly TechnicianTier $tier,
        public readonly TechnicianActionLog $log,
    ) {}
}

/**
 * The SOLE entry point for every side-effecting AI-Technician action
 * (spec §4.3). It classifies the resolved action server-side (default-deny),
 * re-checks the kill-switch + per-client flags immediately before execution,
 * executes AUTO/approved actions via the passed $executor, records non-AUTO
 * actions as awaiting_approval WITHOUT executing, stamps the reused AI actor +
 * actor_label:'ai-technician', and writes exactly one append-only audit row on
 * EVERY path. Fail-closed throughout.
 *
 * The Loop holds NO reference to EmailService/TicketService/TacticalActionService
 * — it passes an $executor closure to this gate (asserted by test).
 */
class TechnicianActionGate
{
    public function __construct(
        private readonly TechnicianTierClassifier $classifier = new TechnicianTierClassifier,
    ) {}

    /**
     * @param  callable():void  $executor  the side effect, run ONLY if the gate clears it
     */
    public function dispatch(
        string $actionType,
        int $ticketId,
        ?int $clientId,
        string $contentHash,
        string $summary,
        ?int $runId,
        callable $executor,
        ?string $approvalToken = null,
        ?int $approverUserId = null,
    ): TechnicianActionResult {
        $correlationId = (string) Str::uuid();
        $tier = $this->classifier->classify($actionType);

        // Kill-switch (pre-classification execution barrier) — fail-closed.
        if (TechnicianConfig::killSwitchEngaged()) {
            return $this->result('held', $tier, $this->audit($actionType, $tier, 'held', $ticketId, $clientId, $runId, $contentHash, $summary, $correlationId));
        }

        // Per-client exclusion — fail-closed.
        if ($clientId !== null && TechnicianConfig::clientExcluded($clientId)) {
            return $this->result('held', $tier, $this->audit($actionType, $tier, 'held', $ticketId, $clientId, $runId, $contentHash, $summary, $correlationId));
        }

        // BLOCK denylist — server-enforced.
        if ($tier === TechnicianTier::Block) {
            return $this->result('blocked', $tier, $this->audit($actionType, $tier, 'blocked', $ticketId, $clientId, $runId, $contentHash, $summary, $correlationId));
        }

        // Non-AUTO (Approve, or an always-human client) requires a valid grant.
        $requiresApproval = $tier !== TechnicianTier::Auto
            || ($clientId !== null && TechnicianConfig::clientAlwaysHuman($clientId));

        if ($requiresApproval) {
            $granted = $approvalToken !== null && TechnicianApprovalGrant::verify(
                $approvalToken,
                $actionType,
                $ticketId,
                $contentHash,
                $approverUserId,
            );

            if (! $granted) {
                return $this->result('awaiting_approval', $tier, $this->audit($actionType, $tier, 'awaiting_approval', $ticketId, $clientId, $runId, $contentHash, $summary, $correlationId));
            }
        }

        // In-flight kill-switch re-check immediately before execution — fail-closed.
        if (TechnicianConfig::killSwitchEngaged()) {
            return $this->result('held', $tier, $this->audit($actionType, $tier, 'held', $ticketId, $clientId, $runId, $contentHash, $summary, $correlationId));
        }

        $executor();

        return $this->result('executed', $tier, $this->audit($actionType, $tier, 'executed', $ticketId, $clientId, $runId, $contentHash, $summary, $correlationId));
    }

    private function result(string $status, TechnicianTier $tier, TechnicianActionLog $log): TechnicianActionResult
    {
        return new TechnicianActionResult($status, $tier, $log);
    }

    private function audit(
        string $actionType,
        TechnicianTier $tier,
        string $resultStatus,
        int $ticketId,
        ?int $clientId,
        ?int $runId,
        string $contentHash,
        string $summary,
        string $correlationId,
    ): TechnicianActionLog {
        return TechnicianActionLog::create([
            'actor_id' => TechnicianConfig::aiActorUserId(),
            'actor_label' => 'ai-technician',
            'action_type' => $actionType,
            'tier' => $tier->value,
            'result_status' => $resultStatus,
            'ticket_id' => $ticketId,
            'client_id' => $clientId,
            'run_id' => $runId,
            'content_hash' => $contentHash,
            'summary' => $summary,
            'correlation_id' => $correlationId,
        ]);
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=TechnicianActionGateTest`
Expected: PASS (7 tests).

- [ ] **Step 6: Commit**

```bash
./vendor/bin/pint --dirty
git add app/Services/Technician/TechnicianApprovalGrant.php app/Services/Technician/TechnicianActionGate.php tests/Feature/Technician/TechnicianActionGateTest.php
git commit -m "feat(technician): signed approval grant + TechnicianActionGate chokepoint (default-deny, fail-closed, append-only audit)"
```

---

### Task 5: `technician_runs` state machine model + migration + idempotency key

**Files:**
- Create: `app/Enums/TechnicianRunState.php`
- Create: `database/migrations/2026_06_23_000002_create_technician_runs_table.php`
- Create: `app/Models/TechnicianRun.php`
- Test: `tests/Feature/Technician/TechnicianRunTest.php`

**Interfaces:**
- Consumes: `App\Models\Ticket`, `App\Models\Client`.
- Produces:
  - `App\Enums\TechnicianRunState` (string enum): `Gathering='gathering'`, `Drafting='drafting'`, `AwaitingApproval='awaiting_approval'`, `Executing='executing'`, `Done='done'`.
  - `App\Models\TechnicianRun` (Eloquent), `$fillable`: `ticket_id`, `client_id`, `action_type`, `content_hash`, `state`. Cast `state => TechnicianRunState::class`. Unique DB index on (`ticket_id`, `action_type`, `content_hash`). `public function advanceTo(TechnicianRunState $state): void` — sets `state` and saves. Relations: `ticket()`, `client()`.
  - `awaiting_approval` lives **on the run**, never on `TicketStatus`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Technician;

use App\Enums\TechnicianRunState;
use App\Models\Client;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class TechnicianRunTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
    }

    public function test_a_run_starts_in_gathering_and_advances(): void
    {
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => $client->id]);

        $run = TechnicianRun::create([
            'ticket_id' => $ticket->id,
            'client_id' => $client->id,
            'action_type' => 'send_ack',
            'content_hash' => str_repeat('a', 64),
            'state' => TechnicianRunState::Gathering,
        ]);

        $this->assertSame(TechnicianRunState::Gathering, $run->state);

        $run->advanceTo(TechnicianRunState::Done);

        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
        $this->assertDatabaseHas('technician_runs', [
            'id' => $run->id,
            'state' => 'done',
        ]);
    }

    public function test_idempotency_key_blocks_a_duplicate(): void
    {
        $ticket = Ticket::factory()->create();
        $attrs = [
            'ticket_id' => $ticket->id,
            'client_id' => $ticket->client_id,
            'action_type' => 'send_ack',
            'content_hash' => str_repeat('b', 64),
            'state' => TechnicianRunState::Gathering,
        ];

        TechnicianRun::create($attrs);

        $this->expectException(QueryException::class);
        TechnicianRun::create($attrs); // same ticket + action_type + content_hash
    }

    public function test_same_ticket_different_action_is_allowed(): void
    {
        $ticket = Ticket::factory()->create();

        TechnicianRun::create([
            'ticket_id' => $ticket->id,
            'client_id' => $ticket->client_id,
            'action_type' => 'send_ack',
            'content_hash' => str_repeat('c', 64),
            'state' => TechnicianRunState::Gathering,
        ]);

        $second = TechnicianRun::create([
            'ticket_id' => $ticket->id,
            'client_id' => $ticket->client_id,
            'action_type' => 'send_reply',
            'content_hash' => str_repeat('c', 64),
            'state' => TechnicianRunState::Gathering,
        ]);

        $this->assertDatabaseHas('technician_runs', ['id' => $second->id]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=TechnicianRunTest`
Expected: FAIL — `Class "App\Models\TechnicianRun" not found`.

- [ ] **Step 3: Write the state enum**

Create `app/Enums/TechnicianRunState.php`:

```php
<?php

namespace App\Enums;

/**
 * The persistent state of a per-ticket Technician run (spec §4.4). Approval
 * waits are PERSISTED here (AwaitingApproval), never a sleeping job, and never
 * on the TicketStatus enum (the cockpit derives a badge from this).
 */
enum TechnicianRunState: string
{
    case Gathering = 'gathering';
    case Drafting = 'drafting';
    case AwaitingApproval = 'awaiting_approval';
    case Executing = 'executing';
    case Done = 'done';
}
```

- [ ] **Step 4: Write the migration**

Create `database/migrations/2026_06_23_000002_create_technician_runs_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * technician_runs — the per-ticket run-state machine (spec §4.4). A unique
 * idempotency key (ticket_id + action_type + content_hash) prevents a
 * double-send under poll re-import / job retry. awaiting_approval lives HERE,
 * not on the TicketStatus enum.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('technician_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->string('action_type');      // e.g. send_ack
            $table->string('content_hash', 64); // sha256 of the action payload
            $table->string('state', 20)->default('gathering');
            $table->timestamps();

            // Idempotency key — the heart of "safe to re-run" (spec §4.4/§14).
            $table->unique(['ticket_id', 'action_type', 'content_hash'], 'technician_runs_idempotency');
            $table->index('state');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('technician_runs');
    }
};
```

- [ ] **Step 5: Write the model**

Create `app/Models/TechnicianRun.php`:

```php
<?php

namespace App\Models;

use App\Enums\TechnicianRunState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $ticket_id
 * @property int|null $client_id
 * @property string $action_type
 * @property string $content_hash
 * @property TechnicianRunState $state
 */
class TechnicianRun extends Model
{
    protected $fillable = [
        'ticket_id',
        'client_id',
        'action_type',
        'content_hash',
        'state',
    ];

    protected function casts(): array
    {
        return [
            'state' => TechnicianRunState::class,
        ];
    }

    public function advanceTo(TechnicianRunState $state): void
    {
        $this->state = $state;
        $this->save();
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=TechnicianRunTest`
Expected: PASS (3 tests).

- [ ] **Step 7: Commit**

```bash
./vendor/bin/pint --dirty
git add app/Enums/TechnicianRunState.php database/migrations/2026_06_23_000002_create_technician_runs_table.php app/Models/TechnicianRun.php tests/Feature/Technician/TechnicianRunTest.php
git commit -m "feat(technician): technician_runs state machine + idempotency key"
```

---

### Task 6: Kill-switch gate behaviour (Setting + in-flight halt)

The kill-switch is already read by `TechnicianConfig::killSwitchEngaged()` (Task 1) and enforced inside the gate (Task 4). This task adds the **dedicated kill-switch test coverage** the spec calls out (`§7`/`§13` fire-drill: "flip the kill-switch mid-run → in-flight halts"). It adds tests only; if they pass against the existing gate, no production code changes — which is the proof the gate already fails-closed on the switch.

**Files:**
- Modify: `tests/Feature/Technician/TechnicianActionGateTest.php` (append two tests)

**Interfaces:**
- Consumes: `App\Services\Technician\TechnicianActionGate::dispatch(...)`; `App\Support\TechnicianConfig`; `App\Models\Setting`.
- Produces: no new production interface (coverage only).

- [ ] **Step 1: Write the failing tests (append to the existing gate test class, before the closing brace)**

```php
    public function test_kill_switch_holds_an_auto_action_before_execution(): void
    {
        $this->autoTier('send_ack');
        Setting::setValue('technician_kill_switch', '1');
        $ran = false;

        $result = $this->gate()->dispatch(
            actionType: 'send_ack',
            ticketId: 10,
            clientId: 5,
            contentHash: str_repeat('a', 64),
            summary: 'ack',
            runId: 1,
            executor: function () use (&$ran) { $ran = true; },
        );

        $this->assertFalse($ran);
        $this->assertSame('held', $result->status);
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'send_ack',
            'result_status' => 'held',
        ]);
    }

    public function test_kill_switch_flipped_in_flight_halts_an_approved_action(): void
    {
        // Approved action (valid grant) — but the executor flips the kill-switch
        // mid-run to model an operator pulling the cord; the gate's in-flight
        // re-check must still let THIS executor finish (it already passed the
        // barrier), so we instead assert a SECOND dispatch is held.
        $approver = User::factory()->create();
        $hash = str_repeat('c', 64);
        $token = TechnicianApprovalGrant::issue('send_reply', 10, $hash, $approver->id);

        // First dispatch executes (switch off).
        $first = $this->gate()->dispatch(
            actionType: 'send_reply',
            ticketId: 10,
            clientId: 5,
            contentHash: $hash,
            summary: 'reply',
            runId: 1,
            executor: function () { /* sent */ },
            approvalToken: $token,
            approverUserId: $approver->id,
        );
        $this->assertSame('executed', $first->status);

        // Operator pulls the cord.
        Setting::setValue('technician_kill_switch', '1');
        $ran = false;

        $second = $this->gate()->dispatch(
            actionType: 'send_reply',
            ticketId: 10,
            clientId: 5,
            contentHash: $hash,
            summary: 'reply',
            runId: 1,
            executor: function () use (&$ran) { $ran = true; },
            approvalToken: $token,
            approverUserId: $approver->id,
        );

        $this->assertFalse($ran);
        $this->assertSame('held', $second->status);
    }
```

- [ ] **Step 2: Run the tests**

Run: `php artisan test --filter=TechnicianActionGateTest`
Expected: PASS (now 9 tests). If a kill-switch test fails, the gate is not fail-closing on the switch — fix `TechnicianActionGate` (the pre- and in-flight `killSwitchEngaged()` checks from Task 4) until green. (Expected outcome: it already passes — this task is the proof.)

- [ ] **Step 3: Commit**

```bash
./vendor/bin/pint --dirty
git add tests/Feature/Technician/TechnicianActionGateTest.php
git commit -m "test(technician): kill-switch holds + in-flight halt coverage"
```

---

### Task 7: Structural-disclosure wrapper + pre-send reject

**Files:**
- Create: `app/Services/Technician/TechnicianDisclosure.php`
- Test: `tests/Feature/Technician/TechnicianDisclosureTest.php`

**Interfaces:**
- Consumes: nothing (pure string transform).
- Produces:
  - `App\Services\Technician\TechnicianDisclosure`:
    - `public const MARKER = '— Sent by Chet, an AI assistant for our team.'` — **the** structural-disclosure sentinel; `withDisclosure()` appends a banner containing this marker, and `assertPresent()` checks for it. (The display name is rendered from config in a later phase; the marker is the load-bearing, model-independent string the pre-send scan keys on.)
    - `public function withDisclosure(string $body): string` — returns `$body` plus two appended lines (separated by a blank line): the `MARKER` banner line, and a "get a human" line: `'If you would prefer to work with a person, just reply and ask — a member of our team will take over.'`. Always appends (idempotency is the caller's concern; Phase 0 composes once).
    - `public function assertPresent(string $body): void` — throws `App\Services\Technician\MissingDisclosureException` (defined in this file, extends `\RuntimeException`) if `MARKER` is not a substring of `$body`. The sending layer calls this immediately before any client send; a model-authored body that lacks the structural disclosure is rejected.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Technician;

use App\Services\Technician\MissingDisclosureException;
use App\Services\Technician\TechnicianDisclosure;
use Tests\TestCase;

class TechnicianDisclosureTest extends TestCase
{
    public function test_with_disclosure_appends_banner_and_human_affordance(): void
    {
        $out = (new TechnicianDisclosure)->withDisclosure('Thanks for reaching out.');

        $this->assertStringContainsString('Thanks for reaching out.', $out);
        $this->assertStringContainsString(TechnicianDisclosure::MARKER, $out);
        $this->assertStringContainsString('prefer to work with a person', $out);
    }

    public function test_assert_present_passes_for_a_disclosed_body(): void
    {
        $disclosure = new TechnicianDisclosure;
        $body = $disclosure->withDisclosure('Hello.');

        $disclosure->assertPresent($body); // must not throw
        $this->assertTrue(true);
    }

    public function test_assert_present_rejects_a_body_without_disclosure(): void
    {
        $this->expectException(MissingDisclosureException::class);

        (new TechnicianDisclosure)->assertPresent('Hello, this is John from the help desk.');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=TechnicianDisclosureTest`
Expected: FAIL — `Class "App\Services\Technician\TechnicianDisclosure" not found`.

- [ ] **Step 3: Write the disclosure service**

Create `app/Services/Technician/TechnicianDisclosure.php`:

```php
<?php

namespace App\Services\Technician;

use RuntimeException;

/** Thrown when a client-facing body is missing the structural disclosure. */
class MissingDisclosureException extends RuntimeException {}

/**
 * Structural disclosure (spec §6/§7). The disclosed-AI banner + "get a human"
 * affordance are appended by THIS sending layer — never authored by the model.
 * assertPresent() is the pre-send check that rejects any body lacking the
 * structural disclosure (or one a model tried to sign off as a named human).
 */
class TechnicianDisclosure
{
    /** The load-bearing, model-independent disclosure sentinel. */
    public const MARKER = '— Sent by Chet, an AI assistant for our team.';

    private const HUMAN_AFFORDANCE =
        'If you would prefer to work with a person, just reply and ask — a member of our team will take over.';

    public function withDisclosure(string $body): string
    {
        return rtrim($body)
            ."\n\n".self::MARKER
            ."\n".self::HUMAN_AFFORDANCE;
    }

    public function assertPresent(string $body): void
    {
        if (! str_contains($body, self::MARKER)) {
            throw new MissingDisclosureException(
                'Client-facing Technician message is missing the structural AI disclosure.',
            );
        }
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=TechnicianDisclosureTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint --dirty
git add app/Services/Technician/TechnicianDisclosure.php tests/Feature/Technician/TechnicianDisclosureTest.php
git commit -m "feat(technician): structural disclosure wrapper + pre-send reject"
```

---

### Task 8: The Loop job + `TicketObserver` dispatch (guarded run creation)

This builds the Loop **dispatch seam** and run creation. The job's Phase-0 body (calling auto-ack) is wired in Task 9; here it creates/loads the run and stops, so the dispatch + gating are independently testable.

**Files:**
- Create: `app/Jobs/RunTechnicianLoop.php`
- Modify: `app/Observers/TicketObserver.php:42` (after the `RunTriagePipeline::dispatch` line, inside `created()`)
- Modify: `app/Models/TicketNote.php:21-39` (add `ai_authored` to `$fillable`) and `app/Models/TicketNote.php:41-54` (cast)
- Create: `database/migrations/2026_06_23_000003_add_ai_authored_to_ticket_notes.php`
- Test: `tests/Feature/Technician/TechnicianLoopDispatchTest.php`

**Interfaces:**
- Consumes: `App\Models\Ticket`, `App\Models\TechnicianRun`, `App\Enums\TechnicianRunState`, `App\Enums\ClientStage`, `App\Support\TechnicianConfig`, `App\Support\TriageConfig::systemUserId()`.
- Produces:
  - `App\Jobs\RunTechnicianLoop implements ShouldQueue` (uses `Queueable`), `public int $tries = 2; public int $timeout = 600;` `public function __construct(private readonly int $ticketId)`. `public function onQueue` set to `'technician'` (dedicated queue per spec §4.4) via constructor calling `$this->onQueue('technician')`. `handle()`: load the ticket; return if missing; return if `client?->stage === ClientStage::Prospect`; ensure a `TechnicianRun` exists for `(ticket_id, action_type='send_ack', content_hash=<ack hash>)` (idempotent via `firstOrCreate`). In Phase 0 the ack content hash is `hash('sha256', 'send_ack:'.$ticket->id)` (stable per ticket). Task 9 adds the auto-ack call after run creation.
  - `TicketNote` gains a fillable, boolean-cast `ai_authored` column (the AI-authored marker, cf. `resolution_ai_drafted`).
  - `TicketObserver::created()` dispatches `RunTechnicianLoop::dispatch($ticket->id)` when: not a prospect (existing guard), `TechnicianConfig::enabled()`, and `created_by !== TriageConfig::systemUserId()` (recursion guard) — mirroring the triage dispatch exactly.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Technician;

use App\Enums\TechnicianRunState;
use App\Jobs\RunTechnicianLoop;
use App\Models\Client;
use App\Models\Setting;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Support\TriageConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class TechnicianLoopDispatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
    }

    public function test_active_client_ticket_dispatches_the_loop_when_enabled(): void
    {
        Setting::setValue('technician_enabled', '1');
        $client = Client::factory()->create(); // Active

        Ticket::factory()->create(['client_id' => $client->id]);

        Bus::assertDispatched(RunTechnicianLoop::class);
    }

    public function test_disabled_technician_does_not_dispatch_the_loop(): void
    {
        // technician_enabled unset → disabled.
        $client = Client::factory()->create();

        Ticket::factory()->create(['client_id' => $client->id]);

        Bus::assertNotDispatched(RunTechnicianLoop::class);
    }

    public function test_prospect_ticket_never_dispatches_the_loop(): void
    {
        Setting::setValue('technician_enabled', '1');
        $prospect = Client::factory()->prospect()->create();

        Ticket::factory()->create(['client_id' => $prospect->id]);

        Bus::assertNotDispatched(RunTechnicianLoop::class);
    }

    public function test_ticket_created_by_the_ai_actor_does_not_dispatch_the_loop(): void
    {
        Setting::setValue('technician_enabled', '1');
        $actorId = TriageConfig::systemUserId(); // first user, since unset
        $client = Client::factory()->create();

        Ticket::factory()->create([
            'client_id' => $client->id,
            'created_by' => $actorId,
        ]);

        Bus::assertNotDispatched(RunTechnicianLoop::class);
    }

    public function test_handle_creates_a_run_idempotently(): void
    {
        // Run the job body directly (Bus::fake only intercepts dispatch).
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => $client->id]);

        (new RunTechnicianLoop($ticket->id))->handle();
        (new RunTechnicianLoop($ticket->id))->handle(); // second run must not duplicate

        $this->assertSame(
            1,
            TechnicianRun::where('ticket_id', $ticket->id)->where('action_type', 'send_ack')->count(),
        );
        $this->assertDatabaseHas('technician_runs', [
            'ticket_id' => $ticket->id,
            'action_type' => 'send_ack',
            'state' => TechnicianRunState::Gathering->value,
        ]);
    }
}
```

Note: `test_ticket_created_by_the_ai_actor...` requires at least one pre-existing user so `TriageConfig::systemUserId()` resolves to a real id; the test creates the ticket with `created_by` set to that id. If `systemUserId()` returns `null` (no users) the guard is a no-op — so the test creates the user implicitly via the id it reads back. To make this deterministic, create a user first:

```php
        $user = \App\Models\User::factory()->create();
        Setting::setValue('triage_system_user_id', (string) $user->id);
        $actorId = $user->id;
```

Replace the `$actorId = TriageConfig::systemUserId();` line in that test with the three lines above.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=TechnicianLoopDispatchTest`
Expected: FAIL — `Class "App\Jobs\RunTechnicianLoop" not found`.

- [ ] **Step 3: Write the `ai_authored` migration**

Create `database/migrations/2026_06_23_000003_add_ai_authored_to_ticket_notes.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the AI-authored marker to ticket notes (spec §4.6, cf.
 * resolution_ai_drafted). A Technician-authored client note carries who_type =
 * Agent AND ai_authored = true so the UI/portal can render it as AI-authored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_notes', function (Blueprint $table) {
            $table->boolean('ai_authored')->default(false)->after('who_type');
        });
    }

    public function down(): void
    {
        Schema::table('ticket_notes', function (Blueprint $table) {
            $table->dropColumn('ai_authored');
        });
    }
};
```

- [ ] **Step 4: Add `ai_authored` to the `TicketNote` model**

In `app/Models/TicketNote.php`, add `'ai_authored'` to `$fillable` (after `'who_type'`):

```php
        'who_type',
        'ai_authored',
        'is_billable',
```

And add the cast inside `casts()` (after `'who_type' => WhoType::class,`):

```php
            'who_type' => WhoType::class,
            'ai_authored' => 'boolean',
```

- [ ] **Step 5: Write the Loop job**

Create `app/Jobs/RunTechnicianLoop.php`:

```php
<?php

namespace App\Jobs;

use App\Enums\ClientStage;
use App\Enums\TechnicianRunState;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * The Loop dispatch seam (spec §4.1). Mirrors RunTriagePipeline: dispatched from
 * TicketObserver::created, prospect-gated, on a dedicated 'technician' queue so
 * Technician load can't starve billing/email jobs. Phase 0: create/load the
 * run, then run the auto-ack (wired in the AutoAcknowledge task).
 */
class RunTechnicianLoop implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 600;

    public function __construct(private readonly int $ticketId)
    {
        $this->onQueue('technician');
    }

    public function handle(): void
    {
        $ticket = Ticket::find($this->ticketId);

        if (! $ticket) {
            Log::warning('[Technician] Ticket not found', ['ticket_id' => $this->ticketId]);

            return;
        }

        // Choke-point prospect gate (mirrors RunTriagePipeline) — no Technician
        // work for prospect-stage clients regardless of dispatch site.
        if ($ticket->client?->stage === ClientStage::Prospect) {
            Log::debug('[Technician] Skipping — prospect client', ['ticket_id' => $this->ticketId]);

            return;
        }

        $run = TechnicianRun::firstOrCreate(
            [
                'ticket_id' => $ticket->id,
                'action_type' => 'send_ack',
                'content_hash' => hash('sha256', 'send_ack:'.$ticket->id),
            ],
            [
                'client_id' => $ticket->client_id,
                'state' => TechnicianRunState::Gathering,
            ],
        );

        // Phase 0: the AutoAcknowledge task appends its call here.
    }
}
```

- [ ] **Step 6: Wire the observer dispatch**

In `app/Observers/TicketObserver.php`, add the import near the existing job imports:

```php
use App\Jobs\RunTechnicianLoop;
```

and add the `TechnicianConfig` import:

```php
use App\Support\TechnicianConfig;
```

Then, inside `created()`, immediately after the existing `RunTriagePipeline::dispatch($ticket->id, 'triage');` line (currently `app/Observers/TicketObserver.php:42`), append:

```php
        // AI Technician Loop (spec §4.1) — same prospect gate (above) + the same
        // system-user recursion guard as triage. Gated by TechnicianConfig::enabled().
        if (
            TechnicianConfig::enabled()
            && ! ($ticket->created_by && $ticket->created_by === TriageConfig::systemUserId())
        ) {
            RunTechnicianLoop::dispatch($ticket->id);
        }
```

Note: the prospect early-return at the top of `created()` already covers prospects; this guard adds the enabled-check and re-uses the existing `TriageConfig::systemUserId()` recursion guard. (`TriageConfig` is already imported in the file.)

- [ ] **Step 7: Run test to verify it passes**

Run: `php artisan test --filter=TechnicianLoopDispatchTest`
Expected: PASS (5 tests).

- [ ] **Step 8: Run the full Technician suite to confirm no regressions**

Run: `php artisan test --filter=Technician`
Expected: PASS (all Technician tests green).

- [ ] **Step 9: Commit**

```bash
./vendor/bin/pint --dirty
git add app/Jobs/RunTechnicianLoop.php app/Observers/TicketObserver.php app/Models/TicketNote.php database/migrations/2026_06_23_000003_add_ai_authored_to_ticket_notes.php tests/Feature/Technician/TechnicianLoopDispatchTest.php
git commit -m "feat(technician): Loop job + guarded TicketObserver dispatch + ai_authored note marker"
```

---

### Task 9: Auto-Acknowledge AUTO action end-to-end through the gate (the vertical slice)

This proves the whole substrate: the Loop → `AutoAcknowledge` → composes a disclosed templated ack → sends it **as an AUTO action through `TechnicianActionGate`** → the executor writes an AI-authored client `TicketNote` (`WhoType::Agent`, `ai_authored=true`, disclosure present) → an append-only audit row is written → the run advances to `Done`.

**Files:**
- Create: `app/Services/Technician/AutoAcknowledge.php`
- Modify: `app/Jobs/RunTechnicianLoop.php` (call `AutoAcknowledge::run($run, $ticket)` after run creation)
- Test: `tests/Feature/Technician/AutoAcknowledgeTest.php`

**Interfaces:**
- Consumes: `App\Services\Technician\TechnicianActionGate::dispatch(...)`; `App\Services\Technician\TechnicianDisclosure::{withDisclosure(string):string, assertPresent(string):void}`; `App\Support\TechnicianConfig::{aiActorUserId(), ackEtaText()}`; `App\Models\TechnicianRun`; `App\Models\TicketNote::create(array)`; `App\Models\User`; `App\Enums\{TechnicianRunState, WhoType, NoteType}`; `App\Models\Ticket`.
- Produces:
  - `App\Services\Technician\AutoAcknowledge`:
    - `public function __construct(private TechnicianActionGate $gate, private TechnicianDisclosure $disclosure)` (constructor-injected so the gate is the sole side-effect path).
    - `public function run(TechnicianRun $run, Ticket $ticket): void` — composes `$body = $this->disclosure->withDisclosure($this->template($ticket))`; calls `$this->disclosure->assertPresent($body)` (pre-send check); dispatches through the gate with `actionType: 'send_ack'`, `contentHash: $run->content_hash`, an `executor` closure that creates the AI-authored client `TicketNote`; if the result status is `executed`, advances the run to `TechnicianRunState::Done`.
    - The note created by the executor: `ticket_id`, `author_id => TechnicianConfig::aiActorUserId()`, `author_name => User::find(aiActorUserId)?->name ?? 'AI Assistant'`, `who_type => WhoType::Agent`, `ai_authored => true`, `body => $body`, `note_type => NoteType::Reply`, `is_private => false`, `noted_at => now()`.
    - `private function template(Ticket $ticket): string` — a plain-language, non-substantive ack (NOT a chatbot greeting): `"Thanks for getting in touch — we've received your request and a member of our team will review it and follow up ".TechnicianConfig::ackEtaText().". We wanted to let you know it's in our queue."`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Technician;

use App\Enums\NoteType;
use App\Enums\TechnicianRunState;
use App\Enums\WhoType;
use App\Jobs\RunTechnicianLoop;
use App\Models\Client;
use App\Models\Setting;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Services\Technician\TechnicianDisclosure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class AutoAcknowledgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake(); // we drive the job body directly, not the queue
    }

    private function configureAutoAck(User $actor): void
    {
        Setting::setValue('technician_enabled', '1');
        Setting::setValue('triage_system_user_id', (string) $actor->id);
        Setting::setValue('technician_action_tiers', json_encode(['send_ack' => 'auto']));
    }

    public function test_auto_acknowledge_produces_disclosed_ai_authored_note_audit_and_advances_run(): void
    {
        $actor = User::factory()->create(['name' => 'Chet']);
        $this->configureAutoAck($actor);
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => $client->id]);

        (new RunTechnicianLoop($ticket->id))->handle();

        // 1. A client-facing note authored by the AI actor, WhoType::Agent, ai_authored.
        $note = TicketNote::where('ticket_id', $ticket->id)
            ->where('ai_authored', true)
            ->first();
        $this->assertNotNull($note, 'expected an AI-authored ack note');
        $this->assertSame($actor->id, $note->author_id);
        $this->assertSame(WhoType::Agent, $note->who_type);
        $this->assertFalse((bool) $note->is_private);
        $this->assertSame(NoteType::Reply, $note->note_type);

        // 2. Structural disclosure present (sending layer appended it).
        $this->assertStringContainsString(TechnicianDisclosure::MARKER, $note->body);
        $this->assertStringContainsString('prefer to work with a person', $note->body);

        // 3. An append-only audit row, attributable to the AI actor + label, executed.
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'send_ack',
            'result_status' => 'executed',
            'actor_label' => 'ai-technician',
            'actor_id' => $actor->id,
            'tier' => 'auto',
            'ticket_id' => $ticket->id,
        ]);

        // 4. The run advanced to done.
        $run = TechnicianRun::where('ticket_id', $ticket->id)->where('action_type', 'send_ack')->firstOrFail();
        $this->assertSame(TechnicianRunState::Done, $run->state);
    }

    public function test_auto_acknowledge_is_idempotent_on_re_run(): void
    {
        $actor = User::factory()->create(['name' => 'Chet']);
        $this->configureAutoAck($actor);
        $ticket = Ticket::factory()->create(['client_id' => Client::factory()->create()->id]);

        (new RunTechnicianLoop($ticket->id))->handle();
        (new RunTechnicianLoop($ticket->id))->handle(); // re-import / retry

        // The run is reused (idempotency key), so a second ack note is NOT created.
        $this->assertSame(
            1,
            TicketNote::where('ticket_id', $ticket->id)->where('ai_authored', true)->count(),
        );
    }

    public function test_kill_switch_engaged_writes_no_ack_note(): void
    {
        $actor = User::factory()->create(['name' => 'Chet']);
        $this->configureAutoAck($actor);
        Setting::setValue('technician_kill_switch', '1');
        $ticket = Ticket::factory()->create(['client_id' => Client::factory()->create()->id]);

        (new RunTechnicianLoop($ticket->id))->handle();

        $this->assertSame(
            0,
            TicketNote::where('ticket_id', $ticket->id)->where('ai_authored', true)->count(),
        );
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'send_ack',
            'result_status' => 'held',
        ]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AutoAcknowledgeTest`
Expected: FAIL — `Class "App\Services\Technician\AutoAcknowledge" not found` (and the run does not advance / no ack note).

- [ ] **Step 3: Write the AutoAcknowledge service**

Create `app/Services/Technician/AutoAcknowledge.php`:

```php
<?php

namespace App\Services\Technician;

use App\Enums\NoteType;
use App\Enums\TechnicianRunState;
use App\Enums\WhoType;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Support\TechnicianConfig;

/**
 * Phase-0 vertical slice (spec §6, §9 "auto-acknowledge", §12 Phase 0). On run
 * creation, the Technician composes a templated, non-substantive, DISCLOSED
 * acknowledgment and sends it AS AN AUTO ACTION THROUGH THE GATE — proving the
 * whole substrate (gate → AI-authored client note → append-only audit → run
 * advance). The richer AI-help "choice" UI/copy + suppression rules are Phase 1.
 */
class AutoAcknowledge
{
    public function __construct(
        private readonly TechnicianActionGate $gate,
        private readonly TechnicianDisclosure $disclosure,
    ) {}

    public function run(TechnicianRun $run, Ticket $ticket): void
    {
        // The sending layer composes the disclosure (NOT the model).
        $body = $this->disclosure->withDisclosure($this->template($ticket));

        // Pre-send structural-disclosure check (fail-closed if absent).
        $this->disclosure->assertPresent($body);

        $actorId = TechnicianConfig::aiActorUserId();
        $authorName = ($actorId ? User::find($actorId)?->name : null) ?? 'AI Assistant';

        $result = $this->gate->dispatch(
            actionType: 'send_ack',
            ticketId: $ticket->id,
            clientId: $ticket->client_id,
            contentHash: $run->content_hash,
            summary: 'Auto-acknowledged the client.',
            runId: $run->id,
            executor: function () use ($ticket, $actorId, $authorName, $body): void {
                TicketNote::create([
                    'ticket_id' => $ticket->id,
                    'author_id' => $actorId,
                    'author_name' => $authorName,
                    'who_type' => WhoType::Agent,
                    'ai_authored' => true,
                    'body' => $body,
                    'note_type' => NoteType::Reply,
                    'is_private' => false,
                    'noted_at' => now(),
                ]);
            },
        );

        if ($result->status === 'executed') {
            $run->advanceTo(TechnicianRunState::Done);
        }
    }

    private function template(Ticket $ticket): string
    {
        return "Thanks for getting in touch — we've received your request and a member of our team "
            .'will review it and follow up '.TechnicianConfig::ackEtaText().'. '
            ."We wanted to let you know it's in our queue.";
    }
}
```

- [ ] **Step 4: Wire the Loop job to call auto-ack**

In `app/Jobs/RunTechnicianLoop.php`, add the import:

```php
use App\Services\Technician\AutoAcknowledge;
```

and replace the comment line `// Phase 0: the AutoAcknowledge task appends its call here.` with:

```php
        // Only run the ack while the run is still pre-send (idempotent re-runs
        // that find a Done run do nothing — the gate's content_hash + the run
        // state both guard against a duplicate send).
        if ($run->state === TechnicianRunState::Gathering) {
            app(AutoAcknowledge::class)->run($run, $ticket);
        }
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=AutoAcknowledgeTest`
Expected: PASS (3 tests).

- [ ] **Step 6: Run the full Technician suite**

Run: `php artisan test --filter=Technician`
Expected: PASS (every Technician test green: config, action-log, classifier, gate+kill-switch, run, disclosure, loop-dispatch, auto-ack).

- [ ] **Step 7: Commit**

```bash
./vendor/bin/pint --dirty
git add app/Services/Technician/AutoAcknowledge.php app/Jobs/RunTechnicianLoop.php tests/Feature/Technician/AutoAcknowledgeTest.php
git commit -m "feat(technician): auto-acknowledge vertical slice end-to-end through the gate"
```

---

## Plan Self-Review

- **Spec coverage (§4–§7, §12 Phase 0):** config model → Task 1; AI-actor reuse → Task 1 (`aiActorUserId` = `triage_system_user_id` fallback, identical to `TriageConfig::systemUserId()`); append-only audit → Task 2 (copies `TacticalActionLog` guards + MariaDB triggers); server-side default-deny tier → Task 3; the gate (sole path, classify, kill-switch, fail-closed, audit, AUTO-executes / non-AUTO awaiting_approval, signed-grant verify) → Task 4; run state machine + idempotency key → Task 5; kill-switch + in-flight halt coverage → Task 6; structural disclosure + pre-send reject → Task 7; Loop job + guarded `TicketObserver` dispatch → Task 8; auto-acknowledge vertical slice (AI-authored `WhoType::Agent` note, `ai_authored` marker, disclosure present, audit row, run advance) → Task 9. The "Loop holds no direct ref to EmailService/TicketService/TacticalActionService" invariant is satisfied structurally: `AutoAcknowledge` injects only the gate + disclosure, and the side effect is a `TicketNote::create` inside the gate-cleared executor closure.
- **Out-of-scope items correctly excluded:** no LLM pipeline, no cockpit/Teams/approval round-trip, no prompt-injection fences, no emergency backstop, no execution adapters, no redaction coverage map — see the next section.
- **Type consistency:** `TechnicianTier` (`auto/approve/block`), `TechnicianRunState` (`gathering/drafting/awaiting_approval/executing/done`), gate `result.status` (`executed/awaiting_approval/blocked/held`), and `actor_label='ai-technician'` are used identically across Tasks 1–9. `TechnicianActionGate::dispatch(...)` and `TechnicianApprovalGrant::{issue,verify}(...)` signatures match between definition (Task 4) and call sites (Task 9). `content_hash` is the same value in the run (Task 5/8: `hash('sha256','send_ack:'.$id)`) and the gate audit (Task 9 passes `$run->content_hash`).

## Out of scope / next plans

These are explicitly deferred — do **not** add tasks for them here:

- **Phase 1 — Safe core (next plan):** the LLM pipeline (triage → cross-domain context → the "can I own this?" classifier → `ReplyDraftService` reply draft → `TicketResolutionDrafter` resolution → action proposal); the **client-reply hook** in `EmailService::linkEmailToTicket` + the portal reply path (idempotency-guarded) re-running the Loop; the AI-help "choice" UI/portal copy + the signed one-click choice link + the suppress-for-billing/security/outage rules; the **cockpit** approval queue + **Teams one-way notify** (digest + reports) + the full approval round-trip (issuing + redeeming `TechnicianApprovalGrant`); **prompt instruction-injection fences** on every Technician prompt + the output disclosure/leak scan (`WikiRedactor::scan` or stricter); the **redaction coverage map** across all context sources (assets/CIPP-M365/RMM/Tactical/contracts/prior-tickets, `ActionRedactor`-grade where richer).
- **Parallel — Teams approval spike → ~July 20 go/no-go (separate track):** Azure Bot Framework + Adaptive Cards + the signed, identity-bound (real approver AAD id) callback; ships only if boringly reliable, else fall back to cockpit + notify.
- **Phase 2 — Emergency:** the deterministic non-AI scheduled sweep backstop; the configurable ordered escalation chain + availability (manual toggle authoritative, presence advisory) + no-ack advancement + both-humans-down honest max-hold; storm/incident grouping (same client + alert signature within ~15 min → one group) + rate-limit/de-dup.
- **Phase 3+ (post-trip):** execution adapters (quarantine/DNS/scripts) with per-action blast-radius caps; graduating auto-actions (release/allow stay APPROVE-sticky; operational-scope guards excluding prospect/out-of-scope clients); autonomous low-risk sends; phone/real-time; the client-portal AI sibling; the Gas City escalation escape-hatch.
