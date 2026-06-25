# AI Technician — Phase 2 (Deterministic Emergency Backstop) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Guarantee that **no true emergency is missed while the operator is away** — a deterministic, non-AI scheduled sweep (ticket age / keyword / contract-SLA) is the *relied-on* detector that escalates through an ordered chain (skip-to-first-available, no-ack advances), groups storms, sends one honest client "max-hold" when nobody is reachable, and halts autonomous progression on the affected ticket — reusing 1C's `OperatorNotifier`, extended for per-operator addressing + outbound SMS.

**Architecture:** A `technician_emergencies` table is the escalation tracker (severity, reasons, storm group, escalation step, ack state, max-hold state). A deterministic `EmergencyDetector` computes `severity = max(rule-signals, AI-raised-if-present)` so client text can never *lower* severity. An `EmergencySweep` orchestrator (run by the scheduled `technician:emergency-sweep` command) scans eligible open/untouched tickets → detects → groups storms → escalates via `EscalationService` (ping the first *available* chain member; a no-ack interval advances; both-unavailable → one templated, disclosed `send_max_hold` to the client) → records to the append-only audit. Operator alerts go out via `OperatorNotifier::notifyUser()` (per-operator email + shared Teams webhook + optional SMS via a new fail-soft `SmsNotifier`). A signed `EmergencyAckToken` powers a one-tap "I've got this" link; implicit human-touch also acks. `DraftPipeline` halts on an open emergency. All gated on `TechnicianConfig::enabled()` → ships dormant.

**Tech Stack:** PHP 8.3, Laravel 12, `Setting`-backed config, `routes/console.php` `Schedule`, Guzzle (Plivo Messages API), `Illuminate\Support\Carbon`, the existing `TechnicianActionGate` / `TechnicianDisclosure` / `EmailService` / `OperatorNotifier` / `TechnicianApprovalGrant` pattern, PHPUnit on sqlite `:memory:` (`RefreshDatabase`, `Http::fake`, `$this->mock`, `$this->artisan`), Pint.

## Global Constraints

Apply to **every** task:

- **The deterministic sweep is the RELIED-ON detector.** It must fire on rules ALONE regardless of AI classification. `severity = max(ruleSeverity, aiRaisedSeverity)` — client/AI text can never lower it (spec §7, §8).
- **Dormant + enabled-gated.** Every schedule + the sweep is `->when(fn () => TechnicianConfig::enabled())`; the command early-exits if `! enabled()`. Merging while disabled fires nothing.
- **The max-hold is the ONLY new autonomous client send** — templated, disclosed (via `TechnicianDisclosure::withDisclosure` + `assertPresent`), rate-limited to **once per emergency**, routed through `TechnicianActionGate` as an AUTO-tier `send_max_hold` action (operator-opt-in via the tier map, exactly like `send_ack`). No substantive client content (resolves the trip's hold-all-sends vs spec-§8 tension — Charlie-approved 2026-06-24).
- **SMS NEVER authorizes anything.** `SmsNotifier` is outbound notify only (no inbound in Phase 2). It is fail-soft (a missing/failing Plivo config never throws or fails the tick).
- **Internal operator alerts bypass the gate** (the gate is for client-affecting actions) and write directly to the append-only `technician_action_logs` (`action_type` `emergency_escalate` / `emergency_max_hold`, `actor_label: 'ai-technician'`).
- **Fail-soft scheduler.** A notify/SMS/Teams failure is caught + logged; it never fails `schedule:run` or the sweep.
- **Append-only audit preserved.** Never add an update/delete path on `technician_action_logs`.
- **Availability is authoritative** (spec §5): a chain member marked not-covering is skipped immediately (Charlie-approved "skip to first available").
- **Runtime:** PHP 8.3 / Laravel 12. Tests: sqlite `:memory:` (`RefreshDatabase`); `Setting::setValue`; `Http::fake()` for Plivo/Teams; `$this->mock(OperatorNotifier::class)` to isolate the sweep; `Ticket::factory()`/`Client::factory()`/`User::factory()`; `TechnicianRun::create([...])` and `Contract::create([...])` inline (no factories). Pint-clean before each commit.

---

## File Structure

**Created:**

| Path | Responsibility |
|------|----------------|
| `database/migrations/2026_06_24_000001_create_technician_emergencies_table.php` | The emergency/escalation tracker table. |
| `app/Models/TechnicianEmergency.php` | Eloquent model + `scopeOpen` + `hasOpenEmergency($ticket)` helper. |
| `app/Enums/EmergencyState.php` | `open` / `acknowledged` / `resolved`. |
| `app/Services/Technician/Emergency/EmergencyDetector.php` | Deterministic `assess(Ticket): EmergencyAssessment` — rule severity + reasons; `max(rules, ai)`. |
| `app/Services/Technician/Emergency/EmergencyAssessment.php` | Readonly DTO `{bool isEmergency, int severity, array reasons, string signature}`. |
| `app/Services/Technician/Emergency/EmergencyGrouper.php` | Storm signature + group-into-or-create a `TechnicianEmergency`. |
| `app/Services/Technician/Emergency/EscalationService.php` | Chain stepping (skip-unavailable), no-ack advance, both-unavailable detection; internal-alert audit. |
| `app/Services/Technician/Emergency/MaxHoldSender.php` | The templated, disclosed `send_max_hold` client message via the gate (mirrors `AutoAcknowledge`). |
| `app/Services/Technician/Emergency/EmergencySweep.php` | The orchestrator: scan → detect → group → escalate → max-hold. |
| `app/Services/Technician/Notify/SmsNotifier.php` | Fail-soft Plivo outbound SMS. |
| `app/Services/Technician/Emergency/EmergencyAckToken.php` | Signed single-use ack token (mirrors `TechnicianApprovalGrant`, longer TTL, bound to `emergency_id`). |
| `app/Console/Commands/TechnicianEmergencySweep.php` | `technician:emergency-sweep`. |
| `app/Http/Controllers/Web/EmergencyAckController.php` | The one-tap "I've got this" link endpoint. |
| `app/Rules/SafeWebhookUrl.php` | https-only + `SafeUrlInspector::reject()` rule for operator-set webhook URLs (psa-ncl1). |
| `tests/Feature/Technician/Emergency/*Test.php` | One test file per task below. |

**Modified:**

| Path | Change |
|------|--------|
| `app/Support/TechnicianConfig.php` | Add emergency config getters/setters (§ Task 2). |
| `app/Services/Technician/Notify/OperatorNotifier.php` | Add `notifyUser(int $userId, string $subject, string $body): void`. |
| `app/Services/Technician/Notify/TeamsNotifier.php` | Request-time SSRF pin in `post()` (psa-ncl1, Task 13). |
| `app/Services/Technician/DraftPipeline.php` | Halt guard if the ticket has an open emergency (Task 11). |
| `app/Http/Controllers/Web/IntegrationsController.php` | Persist escalation chain + per-operator availability + emergency thresholds/keywords/max-hold + SMS + save-time webhook validation (Tasks 12, 13). |
| `resources/views/settings/integrations.blade.php` | The emergency + escalation + SMS settings card (Task 12). |
| `routes/console.php` | Schedule `technician:emergency-sweep` (Task 10). |
| `routes/web.php` | The emergency-ack route (Task 8). |

---

## Tasks

### Task 1: `technician_emergencies` table + model

**Files:**
- Create: `database/migrations/2026_06_24_000001_create_technician_emergencies_table.php`
- Create: `app/Enums/EmergencyState.php`
- Create: `app/Models/TechnicianEmergency.php`
- Test: `tests/Feature/Technician/Emergency/TechnicianEmergencyModelTest.php`

**Interfaces:**
- Produces:
  - `App\Enums\EmergencyState` (string enum): `Open='open'`, `Acknowledged='acknowledged'`, `Resolved='resolved'`.
  - `App\Models\TechnicianEmergency` with fillable `ticket_id, client_id, signature, severity, reasons(json/array cast), detected_by, state(EmergencyState cast), escalation_step(int), current_target_user_id(?int), ticket_ids(array cast), alerted_at, last_pinged_at, acknowledged_at, acknowledged_by, max_hold_sent_at, resolved_at` (datetime casts on the *_at columns).
  - `TechnicianEmergency::scopeOpen($q)` → `where('state', '!=', EmergencyState::Resolved->value)`.
  - `static TechnicianEmergency::hasOpenEmergency(Ticket $ticket): bool` → an open row whose `ticket_id` = the ticket OR whose `ticket_ids` JSON contains it.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Technician\Emergency;

use App\Enums\EmergencyState;
use App\Models\Client;
use App\Models\TechnicianEmergency;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TechnicianEmergencyModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_open_scope_and_has_open_emergency(): void
    {
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => $client->id]);

        $this->assertFalse(TechnicianEmergency::hasOpenEmergency($ticket));

        $e = TechnicianEmergency::create([
            'ticket_id' => $ticket->id, 'client_id' => $client->id,
            'signature' => 'sig', 'severity' => 3, 'reasons' => ['age'],
            'detected_by' => 'rules', 'state' => EmergencyState::Open,
            'escalation_step' => 0, 'ticket_ids' => [$ticket->id], 'alerted_at' => now(),
        ]);

        $this->assertTrue(TechnicianEmergency::hasOpenEmergency($ticket));
        $this->assertEqualsCanonicalizing(['age'], $e->fresh()->reasons);

        $e->update(['state' => EmergencyState::Resolved, 'resolved_at' => now()]);
        $this->assertFalse(TechnicianEmergency::hasOpenEmergency($ticket));
    }
}
```

- [ ] **Step 2: Run it — expect FAIL** (`php artisan test --filter=TechnicianEmergencyModelTest`) — table/model/enum missing.

- [ ] **Step 3: Create the enum** `app/Enums/EmergencyState.php`:

```php
<?php

namespace App\Enums;

enum EmergencyState: string
{
    case Open = 'open';
    case Acknowledged = 'acknowledged';
    case Resolved = 'resolved';
}
```

- [ ] **Step 4: Create the migration** (reversible; indexes for the sweep's lookups; `->after()` only references columns added earlier in THIS migration):

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('technician_emergencies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ticket_id')->index();      // representative ticket
            $table->unsignedBigInteger('client_id')->nullable()->index();
            $table->string('signature', 64)->index();              // storm-group key
            $table->unsignedTinyInteger('severity')->default(1);
            $table->json('reasons')->nullable();
            $table->string('detected_by', 16)->default('rules');   // rules|ai|both
            $table->string('state', 16)->default('open')->index();
            $table->unsignedInteger('escalation_step')->default(0);
            $table->unsignedBigInteger('current_target_user_id')->nullable();
            $table->json('ticket_ids')->nullable();                // storm members
            $table->timestamp('alerted_at')->nullable();
            $table->timestamp('last_pinged_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->unsignedBigInteger('acknowledged_by')->nullable();
            $table->timestamp('max_hold_sent_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['client_id', 'signature', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('technician_emergencies');
    }
};
```

- [ ] **Step 5: Create the model** `app/Models/TechnicianEmergency.php`:

```php
<?php

namespace App\Models;

use App\Enums\EmergencyState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class TechnicianEmergency extends Model
{
    protected $guarded = [];

    protected $casts = [
        'reasons' => 'array',
        'ticket_ids' => 'array',
        'state' => EmergencyState::class,
        'severity' => 'integer',
        'escalation_step' => 'integer',
        'alerted_at' => 'datetime',
        'last_pinged_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'max_hold_sent_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function scopeOpen(Builder $q): Builder
    {
        return $q->where('state', '!=', EmergencyState::Resolved->value);
    }

    public static function hasOpenEmergency(Ticket $ticket): bool
    {
        return static::query()->open()
            ->where(function (Builder $q) use ($ticket) {
                $q->where('ticket_id', $ticket->id)
                    ->orWhereJsonContains('ticket_ids', $ticket->id);
            })->exists();
    }
}
```

- [ ] **Step 6: Run it — expect PASS.** Then `./vendor/bin/pint --dirty` and commit:

```bash
git add database/migrations/2026_06_24_000001_create_technician_emergencies_table.php app/Enums/EmergencyState.php app/Models/TechnicianEmergency.php tests/Feature/Technician/Emergency/TechnicianEmergencyModelTest.php
git commit -m "feat(technician): technician_emergencies table + model (Phase 2)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: `TechnicianConfig` emergency configuration

**Files:**
- Modify: `app/Support/TechnicianConfig.php`
- Test: `tests/Feature/Technician/Emergency/EmergencyConfigTest.php`

**Interfaces (all read `Setting`, all have safe defaults):**
- `emergencyAgeMinutes(\App\Enums\TicketPriority $p): int` — Setting `technician_emergency_age_minutes` (JSON `{p1,p2,p3,p4}`), defaults `{p1:15,p2:60,p3:240,p4:1440}`.
- `emergencyKeywords(): array<string>` — Setting `technician_emergency_keywords` (JSON), default `['down','outage','offline','ransomware','breach','hacked','no internet','cannot work','urgent','emergency']`.
- `escalationTimeoutMinutes(): int` — `technician_escalation_timeout`, default 15, floor 5.
- `operatorAvailable(int $userId): bool` — `technician_operator_availability` (JSON `{userId: bool}`), default true (missing ⇒ available).
- `setOperatorAvailable(int $userId, bool): void`.
- `stormWindowMinutes(): int` — `technician_storm_window`, default 15.
- `maxHoldMessage(): string` — `technician_max_hold_message`, default `"Thank you for reaching out. We've flagged this as urgent and are working to get a technician to you as quickly as possible. We'll be in touch shortly."`.
- `emergencyRepingMinutes(): int` — `technician_emergency_reping`, default 30, floor 5.
- (reuses existing `escalationChain(): int[]`, `operatorCovering(): bool`, `enabled()`.)

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Technician\Emergency;

use App\Enums\TicketPriority;
use App\Models\Setting;
use App\Support\TechnicianConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmergencyConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults(): void
    {
        $this->assertSame(15, TechnicianConfig::emergencyAgeMinutes(TicketPriority::P1));
        $this->assertSame(1440, TechnicianConfig::emergencyAgeMinutes(TicketPriority::P4));
        $this->assertContains('ransomware', TechnicianConfig::emergencyKeywords());
        $this->assertTrue(TechnicianConfig::operatorAvailable(999)); // unset ⇒ available
        $this->assertSame(15, TechnicianConfig::escalationTimeoutMinutes());
    }

    public function test_overrides_and_availability_floor(): void
    {
        Setting::setValue('technician_emergency_age_minutes', json_encode(['p1' => 5]));
        $this->assertSame(5, TechnicianConfig::emergencyAgeMinutes(TicketPriority::P1));

        Setting::setValue('technician_escalation_timeout', '1'); // below floor
        $this->assertSame(5, TechnicianConfig::escalationTimeoutMinutes());

        TechnicianConfig::setOperatorAvailable(3, false);
        $this->assertFalse(TechnicianConfig::operatorAvailable(3));
        $this->assertTrue(TechnicianConfig::operatorAvailable(1));
    }
}
```

- [ ] **Step 2: Run it — expect FAIL.**

- [ ] **Step 3: Add the methods** to `app/Support/TechnicianConfig.php` (uses the existing `Setting`, `decodeMap`/`decodeList` helpers, `Carbon` already imported):

```php
    public static function emergencyAgeMinutes(\App\Enums\TicketPriority $p): int
    {
        $defaults = ['p1' => 15, 'p2' => 60, 'p3' => 240, 'p4' => 1440];
        $map = self::decodeMap('technician_emergency_age_minutes');
        $val = $map[$p->value] ?? $defaults[$p->value] ?? 240;

        return max(1, (int) $val);
    }

    /** @return array<string> */
    public static function emergencyKeywords(): array
    {
        $default = ['down', 'outage', 'offline', 'ransomware', 'breach', 'hacked', 'no internet', 'cannot work', 'urgent', 'emergency'];
        $raw = Setting::getValue('technician_emergency_keywords');
        $list = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

        return is_array($list) && $list !== [] ? array_values(array_filter(array_map('strval', $list))) : $default;
    }

    public static function escalationTimeoutMinutes(): int
    {
        $value = Setting::getValue('technician_escalation_timeout');

        return is_numeric($value) ? max(5, (int) $value) : 15;
    }

    public static function operatorAvailable(int $userId): bool
    {
        $map = self::decodeMap('technician_operator_availability'); // {userId: "1"/"0"}
        if (! array_key_exists((string) $userId, $map)) {
            return true; // unset ⇒ available
        }

        return (bool) $map[(string) $userId];
    }

    public static function setOperatorAvailable(int $userId, bool $covering): void
    {
        $map = self::decodeMap('technician_operator_availability');
        $map[(string) $userId] = $covering ? '1' : '0';
        Setting::setValue('technician_operator_availability', json_encode($map));
    }

    public static function stormWindowMinutes(): int
    {
        $value = Setting::getValue('technician_storm_window');

        return is_numeric($value) ? max(1, (int) $value) : 15;
    }

    public static function maxHoldMessage(): string
    {
        $value = Setting::getValue('technician_max_hold_message');

        return is_string($value) && trim($value) !== ''
            ? $value
            : "Thank you for reaching out. We've flagged this as urgent and are working to get a technician to you as quickly as possible. We'll be in touch shortly.";
    }

    public static function emergencyRepingMinutes(): int
    {
        $value = Setting::getValue('technician_emergency_reping');

        return is_numeric($value) ? max(5, (int) $value) : 30;
    }
```

> If `decodeMap` is `private`, these methods live in the same class so they can call it. Confirm `decodeMap` returns `array<string,string>` (it does — used by `tierMap`).

- [ ] **Step 4: Run it — expect PASS.** Pint + commit (`feat(technician): emergency + escalation config (Phase 2)`).

---

### Task 3: `EmergencyDetector` (the deterministic, relied-on detector)

**Files:**
- Create: `app/Services/Technician/Emergency/EmergencyAssessment.php`
- Create: `app/Services/Technician/Emergency/EmergencyDetector.php`
- Test: `tests/Feature/Technician/Emergency/EmergencyDetectorTest.php`

**Interfaces:**
- Consumes: `Ticket` (`priority`, `opened_at`/`created_at`, `responded_at`, `due_at`, `response_due_at`, `subject`, `description`, `isSlaBreach()`), `TechnicianConfig::{emergencyAgeMinutes,emergencyKeywords}`.
- Produces:
  - `EmergencyAssessment` (readonly): `{bool isEmergency, int severity, array<string> reasons, string signature}`.
  - `EmergencyDetector::assess(Ticket $ticket, int $aiSeverity = 0): EmergencyAssessment` — rule signals: **age** (open + untouched longer than `emergencyAgeMinutes(priority)`), **keyword** (subject/description contains a configured keyword), **SLA** (`isSlaBreach()`). Each fired rule contributes a severity; final `severity = max(ruleMax, $aiSeverity)`. `isEmergency = severity >= 2 || reasons not empty` (rules fire ⇒ emergency regardless of AI). `signature = sha1(client_id . ':' . normalizedSubject)` for storm grouping.

- [ ] **Step 1: Write the failing test** (labeled fixtures; severity = max-of-signals; client text can't lower):

```php
<?php

namespace Tests\Feature\Technician\Emergency;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Client;
use App\Models\Ticket;
use App\Services\Technician\Emergency\EmergencyDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmergencyDetectorTest extends TestCase
{
    use RefreshDatabase;

    private function ticket(array $attrs = []): Ticket
    {
        $client = Client::factory()->create();

        return Ticket::factory()->create(array_merge([
            'client_id' => $client->id,
            'status' => TicketStatus::New->value,
            'priority' => TicketPriority::P1->value,
            'opened_at' => now()->subHours(2),
            'responded_at' => null,
            'subject' => 'Printer is a little slow',
            'description' => 'minor',
        ], $attrs));
    }

    public function test_fresh_low_priority_ticket_is_not_an_emergency(): void
    {
        $t = $this->ticket(['priority' => TicketPriority::P4->value, 'opened_at' => now()->subMinute()]);
        $a = app(EmergencyDetector::class)->assess($t);
        $this->assertFalse($a->isEmergency);
    }

    public function test_aged_p1_untouched_is_an_emergency_by_age(): void
    {
        $t = $this->ticket(['opened_at' => now()->subHour()]); // > 15m P1 floor, no response
        $a = app(EmergencyDetector::class)->assess($t);
        $this->assertTrue($a->isEmergency);
        $this->assertContains('age', $a->reasons);
    }

    public function test_keyword_triggers_regardless_of_priority(): void
    {
        $t = $this->ticket(['priority' => TicketPriority::P4->value, 'opened_at' => now(), 'subject' => 'Server is DOWN - ransomware?']);
        $a = app(EmergencyDetector::class)->assess($t);
        $this->assertTrue($a->isEmergency);
        $this->assertContains('keyword', $a->reasons);
    }

    public function test_ai_severity_raises_but_rules_floor_holds(): void
    {
        $t = $this->ticket(['priority' => TicketPriority::P4->value, 'opened_at' => now(), 'subject' => 'all good']);
        // rules say nothing, AI raised severity 3 → emergency at 3
        $a = app(EmergencyDetector::class)->assess($t, 3);
        $this->assertTrue($a->isEmergency);
        $this->assertSame(3, $a->severity);
        // and a low AI severity cannot lower a rule signal:
        $t2 = $this->ticket(['subject' => 'OUTAGE', 'opened_at' => now()]);
        $a2 = app(EmergencyDetector::class)->assess($t2, 0);
        $this->assertTrue($a2->isEmergency);
        $this->assertGreaterThanOrEqual(2, $a2->severity);
    }

    public function test_signature_is_stable_per_client_and_subject(): void
    {
        $t = $this->ticket(['subject' => 'OUTAGE at site']);
        $a = app(EmergencyDetector::class)->assess($t);
        $b = app(EmergencyDetector::class)->assess($t->fresh());
        $this->assertSame($a->signature, $b->signature);
    }
}
```

- [ ] **Step 2: Run it — expect FAIL.**

- [ ] **Step 3: Write the DTO** `app/Services/Technician/Emergency/EmergencyAssessment.php`:

```php
<?php

namespace App\Services\Technician\Emergency;

final class EmergencyAssessment
{
    /** @param array<string> $reasons */
    public function __construct(
        public readonly bool $isEmergency,
        public readonly int $severity,
        public readonly array $reasons,
        public readonly string $signature,
    ) {}
}
```

- [ ] **Step 4: Write the detector** `app/Services/Technician/Emergency/EmergencyDetector.php`:

```php
<?php

namespace App\Services\Technician\Emergency;

use App\Models\Ticket;
use App\Support\TechnicianConfig;

/**
 * The deterministic, relied-on emergency detector (spec §8). Rules fire regardless
 * of AI classification; severity = max(rule signals, AI-raised). Client/AI text can
 * never LOWER severity. Pure (no side effects).
 */
class EmergencyDetector
{
    public function assess(Ticket $ticket, int $aiSeverity = 0): EmergencyAssessment
    {
        $reasons = [];
        $severity = $aiSeverity;

        // Age: open + not yet responded to, older than the per-priority floor.
        $opened = $ticket->opened_at ?? $ticket->created_at;
        $ageMin = TechnicianConfig::emergencyAgeMinutes($ticket->priority);
        if ($ticket->responded_at === null && $opened !== null && $opened->lt(now()->subMinutes($ageMin))) {
            $reasons[] = 'age';
            $severity = max($severity, 2);
        }

        // Keyword: any configured keyword in subject/description (case-insensitive).
        $haystack = strtolower(trim(($ticket->subject ?? '').' '.($ticket->description ?? '')));
        foreach (TechnicianConfig::emergencyKeywords() as $kw) {
            if ($kw !== '' && str_contains($haystack, strtolower($kw))) {
                $reasons[] = 'keyword';
                $severity = max($severity, 3);
                break;
            }
        }

        // SLA breach (contract-derived due_at / response_due_at; existing helper).
        if (method_exists($ticket, 'isSlaBreach') && $ticket->isSlaBreach()) {
            $reasons[] = 'sla';
            $severity = max($severity, 3);
        }

        $isEmergency = $reasons !== [] || $severity >= 2;

        $normSubject = preg_replace('/\s+/', ' ', strtolower(trim($ticket->subject ?? '')));
        $signature = sha1(($ticket->client_id ?? 0).':'.$normSubject);

        return new EmergencyAssessment($isEmergency, $severity, array_values(array_unique($reasons)), $signature);
    }
}
```

- [ ] **Step 5: Run it — expect PASS.** Pint + commit (`feat(technician): deterministic EmergencyDetector (Phase 2)`).

---

### Task 4: `SmsNotifier` (Plivo outbound, fail-soft)

**Files:**
- Create: `app/Services/Technician/Notify/SmsNotifier.php`
- Test: `tests/Feature/Technician/Notify/SmsNotifierTest.php`

**Interfaces:**
- Consumes: `App\Support\PlivoConfig::{get('auth_id'),get('auth_token'),get('did_number'),isConfigured()}`; Guzzle via `Illuminate\Support\Facades\Http`.
- Produces: `SmsNotifier::send(string $toNumber, string $text): bool` — if not configured or `$toNumber` empty → false (noop). Else `Http::withBasicAuth($authId,$authToken)->timeout(10)->post("https://api.plivo.com/v1/Account/{$authId}/Message/", ['src'=>$did,'dst'=>$toNumber,'text'=>$text])`; true on 2xx, false otherwise; never throws (catch `\Throwable`, log, false). Mirrors `TeamsNotifier`'s fail-soft shape; uses the Basic-auth Plivo pattern from `PhoneCallService`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Technician\Notify;

use App\Models\Setting;
use App\Services\Technician\Notify\SmsNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SmsNotifierTest extends TestCase
{
    use RefreshDatabase;

    private function configurePlivo(): void
    {
        Setting::setValue('plivo_auth_id', 'MAXXXX');
        Setting::setEncrypted('plivo_auth_token', 'secret');
        Setting::setValue('plivo_did_number', '+15551230000');
    }

    public function test_noop_when_unconfigured(): void
    {
        Http::fake();
        $this->assertFalse(app(SmsNotifier::class)->send('+15557654321', 'hi'));
        Http::assertNothingSent();
    }

    public function test_posts_to_plivo_messages_api(): void
    {
        $this->configurePlivo();
        Http::fake(['*' => Http::response('{"message_uuid":["x"]}', 202)]);

        $this->assertTrue(app(SmsNotifier::class)->send('+15557654321', 'AI Tech: you are needed on #123'));

        Http::assertSent(fn ($req) => str_contains($req->url(), '/v1/Account/MAXXXX/Message/')
            && $req['dst'] === '+15557654321'
            && str_contains($req['text'], '#123'));
    }

    public function test_failure_returns_false_and_does_not_throw(): void
    {
        $this->configurePlivo();
        Http::fake(['*' => Http::response('nope', 401)]);
        $this->assertFalse(app(SmsNotifier::class)->send('+15557654321', 'x'));
    }
}
```

- [ ] **Step 2: Run it — expect FAIL.**

- [ ] **Step 3: Write the notifier** `app/Services/Technician/Notify/SmsNotifier.php`:

```php
<?php

namespace App\Services\Technician\Notify;

use App\Support\PlivoConfig;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fail-soft outbound SMS via the Plivo Messages API (Basic auth, mirroring the
 * voice integration's HTTP pattern). Notify-only: SMS never authorizes an action.
 */
class SmsNotifier
{
    public function send(string $toNumber, string $text): bool
    {
        if ($toNumber === '' || ! PlivoConfig::isConfigured()) {
            return false;
        }

        $authId = (string) PlivoConfig::get('auth_id');
        $authToken = (string) PlivoConfig::get('auth_token');
        $src = (string) PlivoConfig::get('did_number');

        try {
            $response = Http::withBasicAuth($authId, $authToken)
                ->timeout(10)
                ->post("https://api.plivo.com/v1/Account/{$authId}/Message/", [
                    'src' => $src,
                    'dst' => $toNumber,
                    'text' => $text,
                ]);

            return $response->successful();
        } catch (\Throwable $e) {
            Log::warning('[Technician] Plivo SMS send failed', ['error' => $e->getMessage()]);

            return false;
        }
    }
}
```

- [ ] **Step 4: Run it — expect PASS.** Pint + commit (`feat(technician): fail-soft Plivo SmsNotifier (Phase 2)`).

---

### Task 5: `OperatorNotifier::notifyUser()` (per-operator addressing)

**Files:**
- Modify: `app/Services/Technician/Notify/OperatorNotifier.php`
- Test: `tests/Feature/Technician/Notify/OperatorNotifierUserTest.php`

**Interfaces:**
- Consumes: `App\Models\User` (`email`, a phone accessor — confirm the column; use `$user->phone ?? $user->mobile ?? null`), `EmailService::sendNew`, `TeamsNotifier::post`, the new `SmsNotifier::send`.
- Produces: `OperatorNotifier::notifyUser(int $userId, string $subject, string $body, bool $sms = false): void` — resolves the `User`; emails them (try/catch), posts to the shared Teams webhook (fail-soft), and if `$sms` and the user has a phone, sends an SMS (fail-soft). Constructor gains `SmsNotifier`. The existing `notify()` is unchanged.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Technician\Notify;

use App\Models\User;
use App\Services\EmailService;
use App\Services\Technician\Notify\OperatorNotifier;
use App\Services\Technician\Notify\SmsNotifier;
use App\Services\Technician\Notify\TeamsNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class OperatorNotifierUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_notify_user_emails_that_user_and_posts_teams(): void
    {
        $user = User::factory()->create(['email' => 'justin@example.com']);

        $this->mock(TeamsNotifier::class, fn (MockInterface $m) => $m->shouldReceive('post')->once()->andReturnTrue());
        $this->mock(SmsNotifier::class, fn (MockInterface $m) => $m->shouldReceive('send')->never());
        $this->mock(EmailService::class, fn (MockInterface $m) => $m->shouldReceive('sendNew')
            ->once()->with('justin@example.com', 'S', 'B', \Mockery::any(), \Mockery::any(), \Mockery::any())->andReturnNull());

        app(OperatorNotifier::class)->notifyUser($user->id, 'S', 'B');
    }

    public function test_sms_only_when_requested_and_phone_present(): void
    {
        $user = User::factory()->create(['email' => 'j@example.com', 'phone' => '+15550001111']);
        $this->mock(TeamsNotifier::class, fn (MockInterface $m) => $m->shouldReceive('post')->once());
        $this->mock(EmailService::class, fn (MockInterface $m) => $m->shouldReceive('sendNew')->once());
        $this->mock(SmsNotifier::class, fn (MockInterface $m) => $m->shouldReceive('send')->once()->with('+15550001111', \Mockery::any())->andReturnTrue());

        app(OperatorNotifier::class)->notifyUser($user->id, 'S', 'B', sms: true);
    }
}
```

> Implementer-confirm: the `users` phone column name (`phone`/`mobile`/none). If there is no phone column, drop the SMS branch from `notifyUser` and source the operator phone from a new `technician_operator_phones` Setting map instead; update the test accordingly. Note it in the task PR.

- [ ] **Step 2: Run it — expect FAIL.**

- [ ] **Step 3: Extend `OperatorNotifier`:**

```php
    public function __construct(
        private readonly TeamsNotifier $teams,
        private readonly EmailService $email,
        private readonly SmsNotifier $sms,
    ) {}

    public function notifyUser(int $userId, string $subject, string $body, bool $sms = false): void
    {
        $user = \App\Models\User::find($userId);
        if ($user === null) {
            return;
        }

        $this->teams->post($subject, $body); // shared channel, fail-soft

        if (! empty($user->email)) {
            try {
                $this->email->sendNew($user->email, $subject, $body, null, null, null);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[Technician] notifyUser email failed', ['error' => $e->getMessage()]);
            }
        }

        $phone = $user->phone ?? null;
        if ($sms && is_string($phone) && $phone !== '') {
            $this->sms->send($phone, $subject.' — '.$body);
        }
    }
```

- [ ] **Step 4: Run it — expect PASS.** Pint + commit (`feat(technician): OperatorNotifier::notifyUser per-operator addressing (Phase 2)`).

---

### Task 6: `EmergencyGrouper` (storm grouping)

**Files:**
- Create: `app/Services/Technician/Emergency/EmergencyGrouper.php`
- Test: `tests/Feature/Technician/Emergency/EmergencyGrouperTest.php`

**Interfaces:**
- Consumes: `TechnicianEmergency`, `EmergencyAssessment`, `TechnicianConfig::stormWindowMinutes`.
- Produces: `EmergencyGrouper::groupOrCreate(Ticket $ticket, EmergencyAssessment $a): TechnicianEmergency` — if an OPEN emergency with the same `signature` + same `client_id` exists with `alerted_at` within the storm window → attach this ticket (append to `ticket_ids`, raise `severity` to max) and return it WITHOUT re-creating; else create a new open emergency (`escalation_step=0`, `alerted_at=now`, `ticket_ids=[ticket]`). Returns a flag the caller uses to know whether it's new (e.g. `wasRecentlyCreated`).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Technician\Emergency;

use App\Models\Client;
use App\Models\Ticket;
use App\Services\Technician\Emergency\EmergencyDetector;
use App\Services\Technician\Emergency\EmergencyGrouper;
use App\Models\TechnicianEmergency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmergencyGrouperTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_signature_within_window_groups_into_one(): void
    {
        $client = Client::factory()->create();
        $t1 = Ticket::factory()->create(['client_id' => $client->id, 'subject' => 'OUTAGE site A', 'opened_at' => now()]);
        $t2 = Ticket::factory()->create(['client_id' => $client->id, 'subject' => 'OUTAGE site A', 'opened_at' => now()]);

        $det = app(EmergencyDetector::class);
        $grp = app(EmergencyGrouper::class);

        $e1 = $grp->groupOrCreate($t1, $det->assess($t1));
        $e2 = $grp->groupOrCreate($t2, $det->assess($t2));

        $this->assertSame($e1->id, $e2->id);                 // one group
        $this->assertCount(2, $e2->fresh()->ticket_ids);
        $this->assertSame(1, TechnicianEmergency::count());
    }

    public function test_different_signature_creates_separate(): void
    {
        $client = Client::factory()->create();
        $a = Ticket::factory()->create(['client_id' => $client->id, 'subject' => 'OUTAGE site A', 'opened_at' => now()]);
        $b = Ticket::factory()->create(['client_id' => $client->id, 'subject' => 'ransomware site B', 'opened_at' => now()]);
        $det = app(EmergencyDetector::class);
        $grp = app(EmergencyGrouper::class);
        $grp->groupOrCreate($a, $det->assess($a));
        $grp->groupOrCreate($b, $det->assess($b));
        $this->assertSame(2, TechnicianEmergency::count());
    }
}
```

- [ ] **Step 2: Run it — expect FAIL.**

- [ ] **Step 3: Write the grouper** `app/Services/Technician/Emergency/EmergencyGrouper.php`:

```php
<?php

namespace App\Services\Technician\Emergency;

use App\Enums\EmergencyState;
use App\Models\TechnicianEmergency;
use App\Models\Ticket;
use App\Support\TechnicianConfig;

/**
 * Storm grouping (spec §8): same client + alert signature within ~15 min → one
 * emergency/one escalation, not N. Otherwise create a new open emergency.
 */
class EmergencyGrouper
{
    public function groupOrCreate(Ticket $ticket, EmergencyAssessment $a): TechnicianEmergency
    {
        $windowStart = now()->subMinutes(TechnicianConfig::stormWindowMinutes());

        $existing = TechnicianEmergency::query()->open()
            ->where('signature', $a->signature)
            ->where('client_id', $ticket->client_id)
            ->where('alerted_at', '>=', $windowStart)
            ->orderByDesc('alerted_at')
            ->first();

        if ($existing !== null) {
            $ids = $existing->ticket_ids ?? [];
            if (! in_array($ticket->id, $ids, true)) {
                $ids[] = $ticket->id;
            }
            $existing->update([
                'ticket_ids' => $ids,
                'severity' => max($existing->severity, $a->severity),
                'reasons' => array_values(array_unique(array_merge($existing->reasons ?? [], $a->reasons))),
            ]);

            return $existing;
        }

        return TechnicianEmergency::create([
            'ticket_id' => $ticket->id,
            'client_id' => $ticket->client_id,
            'signature' => $a->signature,
            'severity' => $a->severity,
            'reasons' => $a->reasons,
            'detected_by' => $a->severity > 0 && $a->reasons === [] ? 'ai' : ($a->reasons !== [] ? 'rules' : 'ai'),
            'state' => EmergencyState::Open,
            'escalation_step' => 0,
            'ticket_ids' => [$ticket->id],
            'alerted_at' => now(),
        ]);
    }
}
```

- [ ] **Step 4: Run it — expect PASS.** Pint + commit (`feat(technician): EmergencyGrouper storm grouping (Phase 2)`).

---

### Task 7: `EscalationService` (skip-to-first-available, no-ack advance, both-unavailable)

**Files:**
- Create: `app/Services/Technician/Emergency/EscalationService.php`
- Test: `tests/Feature/Technician/Emergency/EscalationServiceTest.php`

**Interfaces:**
- Consumes: `TechnicianEmergency`, `TechnicianConfig::{escalationChain,operatorAvailable,escalationTimeoutMinutes,emergencyRepingMinutes}`, `OperatorNotifier::notifyUser`, `EmergencyAckToken::issue` (Task 8), `TechnicianActionLog`.
- Produces: `EscalationService::escalate(TechnicianEmergency $e): void` — driven each sweep tick:
  1. If acknowledged/resolved → return.
  2. Resolve the chain (`escalationChain()`); compute the *available* members (`operatorAvailable`).
  3. If none available → `bothUnavailable` path: caller (sweep) triggers max-hold; here, re-ping the last-known target on the reping cadence + record `emergency_escalate` audit with `reasons:['all_unavailable']`. Return.
  4. Determine the current target = the available member at `escalation_step` (skipping unavailable). If `last_pinged_at` is null or older than `escalationTimeoutMinutes` AND the current step hasn't been acked → if past the timeout, advance `escalation_step` to the next available member (no-ack advance); ping the (new) current target via `notifyUser(..., sms:true)` with a one-tap ack link (`EmergencyAckToken::issue($e->id, $userId)`); set `current_target_user_id`, `last_pinged_at`; record an `emergency_escalate` audit row.
- Records internal alerts DIRECTLY to `technician_action_logs` (bypass the gate — internal, not client-facing): `actor_label:'ai-technician'`, `action_type:'emergency_escalate'`, `result_status:'executed'`, `ticket_id`, `client_id`, `summary`, `correlation_id` = "emergency:{id}".

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Technician\Emergency;

use App\Enums\EmergencyState;
use App\Models\Client;
use App\Models\Setting;
use App\Models\TechnicianEmergency;
use App\Models\User;
use App\Services\Technician\Emergency\EscalationService;
use App\Services\Technician\Notify\OperatorNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class EscalationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function emergency(Client $client): TechnicianEmergency
    {
        return TechnicianEmergency::create([
            'ticket_id' => \App\Models\Ticket::factory()->create(['client_id' => $client->id])->id,
            'client_id' => $client->id, 'signature' => 's', 'severity' => 3, 'reasons' => ['age'],
            'detected_by' => 'rules', 'state' => EmergencyState::Open, 'escalation_step' => 0,
            'ticket_ids' => [], 'alerted_at' => now(),
        ]);
    }

    public function test_pings_first_available_chain_member(): void
    {
        $client = Client::factory()->create();
        $justin = User::factory()->create();
        $charlie = User::factory()->create();
        Setting::setValue('technician_escalation_chain', json_encode([$justin->id, $charlie->id]));
        $e = $this->emergency($client);

        $this->mock(OperatorNotifier::class, fn (MockInterface $m) => $m->shouldReceive('notifyUser')
            ->once()->withArgs(fn ($uid) => $uid === $justin->id));

        app(EscalationService::class)->escalate($e);
        $this->assertSame($justin->id, $e->fresh()->current_target_user_id);
        $this->assertDatabaseHas('technician_action_logs', ['action_type' => 'emergency_escalate']);
    }

    public function test_skips_unavailable_member_immediately(): void
    {
        $client = Client::factory()->create();
        $justin = User::factory()->create();
        $charlie = User::factory()->create();
        Setting::setValue('technician_escalation_chain', json_encode([$justin->id, $charlie->id]));
        \App\Support\TechnicianConfig::setOperatorAvailable($justin->id, false); // away

        $e = $this->emergency($client);
        $this->mock(OperatorNotifier::class, fn (MockInterface $m) => $m->shouldReceive('notifyUser')
            ->once()->withArgs(fn ($uid) => $uid === $charlie->id));

        app(EscalationService::class)->escalate($e);
        $this->assertSame($charlie->id, $e->fresh()->current_target_user_id);
    }

    public function test_no_ack_within_timeout_advances_chain(): void
    {
        $client = Client::factory()->create();
        $justin = User::factory()->create();
        $charlie = User::factory()->create();
        Setting::setValue('technician_escalation_chain', json_encode([$justin->id, $charlie->id]));
        Setting::setValue('technician_escalation_timeout', '15');

        $e = $this->emergency($client);
        $e->update(['escalation_step' => 0, 'current_target_user_id' => $justin->id, 'last_pinged_at' => now()->subMinutes(20)]);

        $this->mock(OperatorNotifier::class, fn (MockInterface $m) => $m->shouldReceive('notifyUser')
            ->once()->withArgs(fn ($uid) => $uid === $charlie->id));

        app(EscalationService::class)->escalate($e);
        $this->assertSame($charlie->id, $e->fresh()->current_target_user_id);
    }

    public function test_acknowledged_emergency_does_nothing(): void
    {
        $client = Client::factory()->create();
        $e = $this->emergency($client);
        $e->update(['state' => EmergencyState::Acknowledged, 'acknowledged_at' => now()]);
        $this->mock(OperatorNotifier::class, fn (MockInterface $m) => $m->shouldReceive('notifyUser')->never());
        app(EscalationService::class)->escalate($e);
    }
}
```

- [ ] **Step 2: Run it — expect FAIL.**

- [ ] **Step 3: Write the service** `app/Services/Technician/Emergency/EscalationService.php` — implement: return-if-acked; compute available chain; no-available → reping + `all_unavailable` audit; else pick current available target (advance on no-ack past timeout); `notifyUser(..., sms:true)` with the ack link; stamp `current_target_user_id`/`last_pinged_at`/`escalation_step`; write the `emergency_escalate` audit row. Use `EmergencyAckToken::issue($e->id, $targetUserId)` to build `route('emergency.ack', ['token' => $token])` and include the URL in the ping body. (Full method body — keep each branch explicit; no placeholders.)

> The implementer writes the body to satisfy the four tests. Key invariants to preserve: availability is authoritative (skip unavailable immediately); advance only after `escalationTimeoutMinutes` with no ack; re-ping the same target only every `emergencyRepingMinutes`; every ping writes exactly one `emergency_escalate` audit row.

- [ ] **Step 4: Run it — expect PASS.** Pint + commit (`feat(technician): EscalationService chain + availability + no-ack advance (Phase 2)`).

---

### Task 8: Emergency acknowledgement (one-tap signed link + implicit human-touch)

**Files:**
- Create: `app/Services/Technician/Emergency/EmergencyAckToken.php`
- Create: `app/Http/Controllers/Web/EmergencyAckController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Technician/Emergency/EmergencyAckTest.php`

**Interfaces:**
- `EmergencyAckToken::issue(int $emergencyId, int $userId): string` / `verify(string $token, int $emergencyId, int $userId): bool` — mirrors `TechnicianApprovalGrant` (HMAC over `{em, u, e}` with `app_key`, base64url envelope) but TTL = `TechnicianConfig::escalationTimeoutMinutes() * 4` floor 2h (long enough for an async operator). Stateless; single-use enforced by the emergency-row CAS.
- `EmergencyAckController::ack(Request $request, string $token)` — decode for the claimed `{em,u}`, `verify`, then atomically claim: `TechnicianEmergency::where('id',$em)->where('state','open')->update([... acknowledged ...])`; if 0 rows → already acked (idempotent 200). Sets `state=acknowledged`, `acknowledged_at`, `acknowledged_by=u`. Records an `emergency_ack` audit row. Renders a tiny "Got it — thanks, you're now on ticket #X" confirmation.
- Route: `Route::get('/technician/emergency/ack/{token}', [EmergencyAckController::class, 'ack'])->name('emergency.ack')` (no auth middleware — the signed token IS the auth; the token binds the user id).
- **Implicit ack:** the sweep (Task 10) also marks an emergency acknowledged if a human has touched any member ticket since `alerted_at` (a non-AI note / status change / assignment) — handled in the sweep, tested there.

- [ ] **Step 1: Write the failing test** (token round-trip + single-use CAS):

```php
<?php

namespace Tests\Feature\Technician\Emergency;

use App\Enums\EmergencyState;
use App\Models\Client;
use App\Models\TechnicianEmergency;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Technician\Emergency\EmergencyAckToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmergencyAckTest extends TestCase
{
    use RefreshDatabase;

    private function emergency(): TechnicianEmergency
    {
        $client = Client::factory()->create();

        return TechnicianEmergency::create([
            'ticket_id' => Ticket::factory()->create(['client_id' => $client->id])->id,
            'client_id' => $client->id, 'signature' => 's', 'severity' => 3, 'reasons' => ['age'],
            'detected_by' => 'rules', 'state' => EmergencyState::Open, 'escalation_step' => 0,
            'ticket_ids' => [], 'alerted_at' => now(),
        ]);
    }

    public function test_valid_token_acks_once_and_is_idempotent(): void
    {
        $user = User::factory()->create();
        $e = $this->emergency();
        $token = EmergencyAckToken::issue($e->id, $user->id);

        $this->get(route('emergency.ack', ['token' => $token]))->assertOk();
        $e->refresh();
        $this->assertSame(EmergencyState::Acknowledged, $e->state);
        $this->assertSame($user->id, $e->acknowledged_by);

        // second tap: idempotent, no error, no state change
        $this->get(route('emergency.ack', ['token' => $token]))->assertOk();
        $this->assertDatabaseHas('technician_action_logs', ['action_type' => 'emergency_ack']);
    }

    public function test_tampered_token_is_rejected(): void
    {
        $e = $this->emergency();
        $this->get(route('emergency.ack', ['token' => 'garbage']))->assertForbidden();
        $this->assertSame(EmergencyState::Open, $e->fresh()->state);
    }
}
```

- [ ] **Step 2: Run it — expect FAIL.**

- [ ] **Step 3: Write `EmergencyAckToken`** (clone `TechnicianApprovalGrant`'s sign/verify; payload `['em'=>$emergencyId,'u'=>$userId,'e'=>$expiry]`; base64url envelope; TTL from config). **Step 4: Write `EmergencyAckController::ack`** (verify → CAS update → audit → confirmation view; 403 on bad token). **Step 5: Register the route.**

- [ ] **Step 6: Run it — expect PASS.** Pint + commit (`feat(technician): one-tap emergency ack link + token (Phase 2)`).

---

### Task 9: `MaxHoldSender` (the one autonomous client message)

**Files:**
- Create: `app/Services/Technician/Emergency/MaxHoldSender.php`
- Test: `tests/Feature/Technician/Emergency/MaxHoldSenderTest.php`

**Interfaces:**
- Consumes: `TechnicianActionGate::dispatch`, `TechnicianDisclosure::{withDisclosure,assertPresent}`, `EmailService::sendTicketReplyNote`, `TechnicianConfig::{maxHoldMessage,aiActorName}`, `Ticket`, `TechnicianEmergency`.
- Produces: `MaxHoldSender::send(TechnicianEmergency $e, Ticket $ticket): void` — **mirrors `AutoAcknowledge`**: build `withDisclosure(maxHoldMessage(), aiActorName())` + `assertPresent`; `contentHash = hash('sha256','send_max_hold:'.$ticket->id.':'.$body)`; `gate->dispatch('send_max_hold', $ticket->id, $ticket->client_id, $contentHash, 'emergency max-hold', null, executor: fn() => create the TicketNote)`. If executed: `email->sendTicketReplyNote($ticket, $note, $ticket->contact?->email, [])`, set `$note->email_id`, then `$e->update(['max_hold_sent_at' => now()])`. **Guard: do nothing if `$e->max_hold_sent_at !== null` (once per emergency).** `send_max_hold` only fires AUTO if the operator has mapped it to `auto` in the tier map (else the gate holds it as `awaiting_approval` — which during the trip means it simply won't send, the safe default).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Technician\Emergency;

use App\Enums\EmergencyState;
use App\Models\Client;
use App\Models\Setting;
use App\Models\TechnicianEmergency;
use App\Models\Ticket;
use App\Services\EmailService;
use App\Services\Technician\Emergency\MaxHoldSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class MaxHoldSenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_sends_one_disclosed_max_hold_through_the_gate_when_auto(): void
    {
        Setting::setValue('technician_enabled', '1');
        Setting::setValue('technician_action_tiers', json_encode(['send_max_hold' => 'auto']));
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => $client->id]);
        $e = TechnicianEmergency::create([
            'ticket_id' => $ticket->id, 'client_id' => $client->id, 'signature' => 's', 'severity' => 3,
            'reasons' => ['age'], 'detected_by' => 'rules', 'state' => EmergencyState::Open,
            'escalation_step' => 0, 'ticket_ids' => [$ticket->id], 'alerted_at' => now(),
        ]);

        $this->mock(EmailService::class, fn (MockInterface $m) => $m->shouldReceive('sendTicketReplyNote')->once()->andReturnNull());

        app(MaxHoldSender::class)->send($e, $ticket);

        $this->assertNotNull($e->fresh()->max_hold_sent_at);
        $this->assertDatabaseHas('technician_action_logs', ['action_type' => 'send_max_hold', 'result_status' => 'executed']);
        $note = $ticket->notes()->latest('id')->first();
        $this->assertStringContainsString('an AI assistant for our team', $note->body); // disclosure present

        // idempotent: second call does nothing
        app(MaxHoldSender::class)->send($e->fresh(), $ticket);
        $this->assertSame(1, $ticket->notes()->count());
    }

    public function test_held_not_sent_when_not_auto(): void
    {
        Setting::setValue('technician_enabled', '1'); // tier map empty → default-deny Approve → held
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => $client->id]);
        $e = TechnicianEmergency::create([
            'ticket_id' => $ticket->id, 'client_id' => $client->id, 'signature' => 's', 'severity' => 3,
            'reasons' => ['age'], 'detected_by' => 'rules', 'state' => EmergencyState::Open,
            'escalation_step' => 0, 'ticket_ids' => [$ticket->id], 'alerted_at' => now(),
        ]);
        $this->mock(EmailService::class, fn (MockInterface $m) => $m->shouldReceive('sendTicketReplyNote')->never());

        app(MaxHoldSender::class)->send($e, $ticket);
        $this->assertNull($e->fresh()->max_hold_sent_at); // not marked sent (held)
    }
}
```

- [ ] **Step 2: Run it — expect FAIL.** **Step 3: Write `MaxHoldSender`** mirroring `AutoAcknowledge` exactly (disclosure → gate dispatch with the note-creating executor → email-after-executed → set `email_id` → mark `max_hold_sent_at`); only mark `max_hold_sent_at` when `$result->status === 'executed'`.

- [ ] **Step 4: Run it — expect PASS.** Pint + commit (`feat(technician): MaxHoldSender — one disclosed client holding message via the gate (Phase 2)`).

---

### Task 10: `EmergencySweep` + `technician:emergency-sweep` command + schedule

**Files:**
- Create: `app/Services/Technician/Emergency/EmergencySweep.php`
- Create: `app/Console/Commands/TechnicianEmergencySweep.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/Technician/Emergency/EmergencySweepTest.php`

**Interfaces:**
- `EmergencySweep::run(): void` — (1) scan candidate tickets: `Ticket::open()` not in an excluded client, not already in an open emergency where appropriate; for each, `EmergencyDetector::assess`; if `isEmergency` → `EmergencyGrouper::groupOrCreate`. (2) For each OPEN emergency: **implicit ack** — if any member ticket was touched by a human since `alerted_at` → mark acknowledged + audit; else `EscalationService::escalate`; if all chain members unavailable AND `max_hold_sent_at` is null → `MaxHoldSender::send` on the representative ticket. (3) Resolve emergencies whose tickets are all closed/resolved. Fail-soft per emergency (try/catch + log). Self-throttled via `Setting`-backed `technician_last_emergency_sweep_at` (only the heavy *scan* is throttled to the reping cadence; per-emergency escalation timing is driven by `last_pinged_at`).
- `TechnicianEmergencySweep` (`signature='technician:emergency-sweep'`): if `! TechnicianConfig::enabled()` → SUCCESS; else `app(EmergencySweep::class)->run()`; SUCCESS.
- `routes/console.php`: `Schedule::command('technician:emergency-sweep')->everyMinute()->withoutOverlapping()->runInBackground()->when(fn () => TechnicianConfig::enabled());`

- [ ] **Step 1: Write the failing test** (the integration test — detect → group → escalate; + implicit-ack; mock `OperatorNotifier`):

```php
<?php

namespace Tests\Feature\Technician\Emergency;

use App\Models\Client;
use App\Models\Setting;
use App\Models\TechnicianEmergency;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Technician\Notify\OperatorNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class EmergencySweepTest extends TestCase
{
    use RefreshDatabase;

    public function test_disabled_does_nothing(): void
    {
        $this->mock(OperatorNotifier::class, fn (MockInterface $m) => $m->shouldReceive('notifyUser')->never());
        $this->artisan('technician:emergency-sweep')->assertSuccessful();
        $this->assertSame(0, TechnicianEmergency::count());
    }

    public function test_detects_and_escalates_an_aged_p1(): void
    {
        Setting::setValue('technician_enabled', '1');
        $justin = User::factory()->create();
        Setting::setValue('technician_escalation_chain', json_encode([$justin->id]));
        $client = Client::factory()->create();
        Ticket::factory()->create([
            'client_id' => $client->id, 'status' => \App\Enums\TicketStatus::New->value,
            'priority' => \App\Enums\TicketPriority::P1->value, 'opened_at' => now()->subHour(),
            'responded_at' => null, 'subject' => 'site OUTAGE',
        ]);

        $this->mock(OperatorNotifier::class, fn (MockInterface $m) => $m->shouldReceive('notifyUser')->atLeast()->once());

        $this->artisan('technician:emergency-sweep')->assertSuccessful();
        $this->assertSame(1, TechnicianEmergency::count());
        $this->assertDatabaseHas('technician_action_logs', ['action_type' => 'emergency_escalate']);
    }
}
```

- [ ] **Step 2: Run it — expect FAIL.** **Step 3: Write `EmergencySweep`** (the orchestrator; fail-soft per emergency). **Step 4: Write the command. Step 5: Schedule it.**

- [ ] **Step 6: Run it — expect PASS.** Pint + commit (`feat(technician): EmergencySweep + technician:emergency-sweep schedule (Phase 2)`).

---

### Task 11: Halt autonomous progression on an open emergency

**Files:**
- Modify: `app/Services/Technician/DraftPipeline.php`
- Test: `tests/Feature/Technician/Emergency/DraftPipelineEmergencyHaltTest.php`

**Interfaces:**
- Consumes: `TechnicianEmergency::hasOpenEmergency(Ticket)`.
- Produces: in `DraftPipeline::run()`, immediately after the daily-budget guard (recon: `app/Services/Technician/DraftPipeline.php` ~L45, before `hasUnaddressedClientReply`), add:

```php
        if (\App\Models\TechnicianEmergency::hasOpenEmergency($ticket)) {
            Log::info('[Technician] Open emergency — halting autonomous pipeline', ['ticket_id' => $ticket->id]);

            return;
        }
```

- [ ] **Step 1: Write the failing test** — a ticket that would normally draft, but with an open emergency, produces no `send_reply` run:

```php
<?php

namespace Tests\Feature\Technician\Emergency;

use App\Enums\EmergencyState;
use App\Models\Client;
use App\Models\Setting;
use App\Models\TechnicianEmergency;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Services\Technician\DraftPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DraftPipelineEmergencyHaltTest extends TestCase
{
    use RefreshDatabase;

    public function test_open_emergency_halts_drafting(): void
    {
        Setting::setValue('technician_enabled', '1');
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => $client->id]);
        TechnicianEmergency::create([
            'ticket_id' => $ticket->id, 'client_id' => $client->id, 'signature' => 's', 'severity' => 3,
            'reasons' => ['age'], 'detected_by' => 'rules', 'state' => EmergencyState::Open,
            'escalation_step' => 0, 'ticket_ids' => [$ticket->id], 'alerted_at' => now(),
        ]);

        app(DraftPipeline::class)->run($ticket);

        $this->assertSame(0, TechnicianRun::where('ticket_id', $ticket->id)->where('action_type', 'send_reply')->count());
    }
}
```

- [ ] **Step 2: Run it — expect FAIL** (no guard yet → a run may be created or the pipeline proceeds). **Step 3: Add the guard. Step 4: Run — expect PASS.** Pint + commit (`feat(technician): halt draft pipeline on an open emergency (Phase 2)`).

---

### Task 12: Settings UI — emergency, escalation, availability, SMS

**Files:**
- Modify: `app/Http/Controllers/Web/IntegrationsController.php` (`index` view vars + `updateTechnician`)
- Modify: `resources/views/settings/integrations.blade.php`
- Test: `tests/Feature/Technician/Emergency/EmergencySettingsTest.php`

**Interfaces:**
- `updateTechnician` also persists: `technician_escalation_chain` (ordered user IDs, from a multiselect/CSV → JSON array), `technician_operator_availability` (per-user checkboxes → JSON map), `technician_emergency_age_minutes` (p1–p4 → JSON), `technician_emergency_keywords` (textarea → JSON array), `technician_escalation_timeout`, `technician_emergency_reping`, `technician_storm_window`, `technician_max_hold_message`, and the `send_max_hold` tier toggle (merge `send_max_hold => 'auto'` into `technician_action_tiers` when checked). The `index` exposes the matching view vars.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Technician\Emergency;

use App\Models\User;
use App\Support\TechnicianConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmergencySettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_saves_escalation_and_emergency_config(): void
    {
        $user = User::factory()->create();
        $justin = User::factory()->create();

        $this->actingAs($user)->post(route('settings.integrations.technician.update'), [
            'technician_enabled' => '1',
            'technician_escalation_chain' => [(string) $justin->id, (string) $user->id],
            'technician_escalation_timeout' => '20',
            'technician_emergency_keywords' => "down\noutage\nransomware",
            'technician_max_hold_message' => 'We are on it.',
            'technician_max_hold_auto' => '1',
        ])->assertRedirect();

        $this->assertSame([$justin->id, $user->id], TechnicianConfig::escalationChain());
        $this->assertSame(20, TechnicianConfig::escalationTimeoutMinutes());
        $this->assertContains('ransomware', TechnicianConfig::emergencyKeywords());
        $this->assertSame('We are on it.', TechnicianConfig::maxHoldMessage());
        $this->assertSame('auto', TechnicianConfig::tierMap()['send_max_hold'] ?? null);
    }
}
```

- [ ] **Step 2: Run it — expect FAIL. Step 3: Extend `updateTechnician`** (parse + persist each setting; merge `send_max_hold` into the tier map without dropping `send_ack`). **Step 4: Extend the Blade card** (escalation chain multiselect of active users; per-operator availability switches; emergency age inputs p1–p4; keywords textarea; timeout/reping/storm-window numbers; max-hold message textarea; "auto-send max-hold" switch — all under a new "Emergency backstop (Phase 2)" `<hr>` section in the existing AI Technician form, `{{ }}`-escaped). **Step 5: Add the `index` view vars.**

- [ ] **Step 6: Run it — expect PASS.** Then run the **full Technician suite** (`php artisan test --filter=Technician`). Pint + commit (`feat(technician): emergency/escalation/SMS settings UI (Phase 2)`).

---

### Task 13: Close psa-ncl1 — SSRF-pin the operator-set Teams webhook

**Files:**
- Create: `app/Rules/SafeWebhookUrl.php`
- Modify: `app/Http/Controllers/Web/IntegrationsController.php` (`updateTechnician` validates `technician_teams_webhook_url`)
- Modify: `app/Services/Technician/Notify/TeamsNotifier.php` (request-time IP-pin)
- Test: `tests/Feature/Technician/Notify/TeamsWebhookSsrfTest.php`

**Interfaces:**
- `SafeWebhookUrl` (Laravel `ValidationRule`): fail unless `https` scheme AND `SafeUrlInspector::reject()` passes for the host (reuse the shared checker shipped in PR #39; mirror `app/Rules/SafeTacticalUrl.php`).
- `TeamsNotifier::post()`: before `Http::post`, resolve the host and reject via `SafeUrlInspector::ipIsSafe()` (fail-closed → return false), OR pin with Guzzle `CURLOPT_RESOLVE` as `TacticalClient` does. A webhook resolving to a private/link-local/loopback IP is refused.

- [ ] **Step 1: Write the failing test** — saving an internal-IP webhook is rejected; `post()` to a host resolving to `169.254.169.254` returns false without sending:

```php
<?php

namespace Tests\Feature\Technician\Notify;

use App\Models\Setting;
use App\Models\User;
use App\Services\Technician\Notify\TeamsNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TeamsWebhookSsrfTest extends TestCase
{
    use RefreshDatabase;

    public function test_save_rejects_non_https_or_internal_webhook(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('settings.integrations.technician.update'), [
            'technician_enabled' => '1',
            'technician_teams_webhook_url' => 'http://169.254.169.254/latest/meta-data',
        ])->assertSessionHasErrors('technician_teams_webhook_url');
    }

    public function test_post_fails_closed_on_internal_resolution(): void
    {
        // A loopback-resolving host must be refused at request time.
        Setting::setValue('technician_teams_webhook_url', 'https://localhost/hook');
        Http::fake();
        $this->assertFalse(app(TeamsNotifier::class)->post('S', 'B'));
        Http::assertNothingSent();
    }
}
```

- [ ] **Step 2: Run it — expect FAIL. Step 3: Write `SafeWebhookUrl`** (mirror `SafeTacticalUrl`). **Step 4: Validate in `updateTechnician`** (`$request->validate(['technician_teams_webhook_url' => ['nullable','string', new SafeWebhookUrl])]` — only when present/non-empty). **Step 5: Pin in `TeamsNotifier::post()`** (resolve host → `SafeUrlInspector::ipIsSafe()` fail-closed).

- [ ] **Step 6: Run it — expect PASS.** Full Technician suite green. Pint + commit (`fix(technician): SSRF-pin the operator-set Teams webhook (closes psa-ncl1)`).

---

## Plan Self-Review

**Spec coverage (§8 emergency model + §5 config + §7 severity):**
- *Deterministic non-AI relied-on sweep; severity = max(model, rules)* → Tasks 3 (`EmergencyDetector`) + 10 (`EmergencySweep`). Rules fire regardless of AI; `max()` floor proven by test.
- *Ordered escalation chain, no tertiary, skip-to-first-available, no-ack advances* → Task 7 (`EscalationService`) — Charlie-approved skip-unavailable + no-ack advance, tested.
- *Availability (manual toggle authoritative)* → Task 2 (`operatorAvailable`) + Task 7 (skip) + Task 12 (UI).
- *Both-unavailable honest client max-hold (one templated, disclosed)* → Task 9 (`MaxHoldSender`) + Task 10 (trigger) — the one autonomous client send, AUTO-tier through the gate, rate-limited once per emergency.
- *Storm grouping (~15 min, same client + signature)* → Task 6 (`EmergencyGrouper`).
- *Reuse `OperatorNotifier`; per-operator + SMS* → Tasks 4–5.
- *Ack + one-tap link + implicit human-touch* → Task 8 + Task 10 (implicit).
- *Stop autonomous progression on emergency* → Task 11.
- *Settings without a deploy* → Task 12.
- *Enablement gate psa-ncl1 (SSRF webhook)* → Task 13.

**Deferred / not Phase 2 (correctly):** the guidance loop (separate spec, built next); inbound SMS/Teams; the AI emergency-raise *emission* (the sweep CONSUMES an `$aiSeverity` if present via `assess(ticket, aiSeverity)` but Phase 2 doesn't build the AI-raise path — it's the deterministic backstop that matters; wire the AI raise in a fast-follow).

**Placeholder scan:** Tasks 7, 8, 10, 12 leave the larger method bodies/Blade to the implementer with explicit invariants + full tests that pin behavior (the tests ARE the spec for those bodies); every other task has complete code. The orchestrator/service bodies are behavior-locked by their tests — acceptable for SDD, but the implementer must not weaken a test to pass.

**Type consistency:** `EmergencyAssessment{isEmergency,severity,reasons,signature}`, `EmergencyState`, `TechnicianEmergency` columns, `TechnicianConfig` method names, `OperatorNotifier::notifyUser(int,string,string,bool)`, `SmsNotifier::send(string,string):bool`, `EmergencyAckToken::issue/verify`, and the `send_max_hold`/`emergency_escalate`/`emergency_ack` action types are used identically across tasks.

**Migration check (Part C):** one new table (`technician_emergencies`), no `->after()` on a foreign column, reversible `down()`. Additive, dormant-safe. No edits to historical migrations.

**Blast radius:** dormant — every schedule + command gates on `enabled()` (false in prod); the `DraftPipeline` guard only fires if a `technician_emergencies` row exists (none until the sweep runs, which is gated); `OperatorNotifier`/`TeamsNotifier`/`IntegrationsController` changes are inert until the operator configures + enables. The Task 13 SSRF pin is a tightening (fail-closed) on a path that's dormant anyway. Merging changes nothing in prod until the toggle flips.

## Sequencing note
Build Tasks 1→13 in order (each builds on the prior). After Task 13, run the full app suite + `php artisan test --filter=Technician`, then an opus whole-branch review before the PR. Ships **dormant**; soak before Aug 1. The guidance loop (separate spec) builds after this.

---

## Panel Review Change-Order (2026-06-24) — APPLY during the SDD build

A 5-lens panel (forest / architecture / security / correctness / feasibility), each grounded in the real codebase, reviewed this plan. **All five verdicts: PROCEED-WITH-CHANGES** (no REVISE; the spine is sound). Apply these to the named tasks as you implement them. CO-1…CO-8 are hard requirements; CO-9…CO-12 fold into their tasks; CO-13…CO-18 if cheap.

### Blockers (wrong without these)

- **CO-1 (Task 6+10) — Duplicate emergency on a long-unacked ticket.** The grouper's `stormWindowMinutes` (15m) is the storm-CLUSTERING key, NOT the re-detection dedup key. At minute 16 a still-aged, still-untouched ticket (the exact Phase-2 scenario) falls outside the window → `groupOrCreate` makes a SECOND emergency → duplicate escalation + duplicate max-hold. **FIX:** the sweep candidate scan must skip any ticket where `TechnicianEmergency::hasOpenEmergency($ticket)` is true BEFORE calling `groupOrCreate` (make "not already in an open emergency" literal). Regression test: detect at T0, `Carbon::setTestNow(now()->addMinutes(20))`, re-run sweep, assert `TechnicianEmergency::count() === 1`.
- **CO-2 (Task 7) — Direct audit write omits NOT-NULL columns.** `technician_action_logs.tier` + `.content_hash` are NOT NULL (no default); the `emergency_escalate` insert omits both → SQLite-passes / MariaDB-throws on the first real escalation (silent trap). **FIX:** the direct `TechnicianActionLog::create([...])` must supply the FULL set: `actor_id => TechnicianConfig::aiActorUserId()`, `actor_label => 'ai-technician'`, `action_type => 'emergency_escalate'`, `tier => 'auto'`, `result_status => 'executed'`, `ticket_id`, `client_id`, `content_hash => hash('sha256','emergency_escalate:'.$e->id.':'.$step)`, `summary`, `correlation_id => 'emergency:'.$e->id`. (Append-only guards still apply to inserts; bypassing the gate for INTERNAL alerts is fine — immutability preserved.)
- **CO-3 (Task 2+5) — `users` has NO `phone` column** (confirmed: no migration, not fillable; phone lives on `people`). **FIX:** add to Task 2 `operatorPhone(int $userId): ?string` (reads a `technician_operator_phones` JSON map) + `setOperatorPhone(int,?string): void`; Task 5 `notifyUser` uses `TechnicianConfig::operatorPhone($userId)` (drop `$user->phone`); rewrite the Task 5 test to set the map; surface the phone map in the Task 12 UI.

### High

- **CO-4 (Task 12) — Tier-map overwrite drops `send_ack`.** `updateTechnician` REPLACES `technician_action_tiers` wholesale from the auto-ack checkbox. **FIX:** build the map from ALL toggles at once: `$tiers=[]; if($request->has('technician_auto_ack')) $tiers['send_ack']='auto'; if($request->has('technician_max_hold_auto')) $tiers['send_max_hold']='auto'; Setting::setValue('technician_action_tiers', json_encode($tiers));`. Test posts BOTH toggles, asserts BOTH keys `'auto'`.
- **CO-5 (Task 8) — Ack must NOT be able to silence the deterministic backstop (denial-of-escalation).** The unauthenticated, long-TTL, multi-transport ack link is a bearer credential; a leak/forward could stop escalation + suppress max-hold = a missed emergency (the one thing §15 forbids). **FIX:** (a) Ack = SNOOZE/ASSIGN, not a hard stop — the deterministic sweep keeps re-pinging + max-holding until a HUMAN ACTUALLY TOUCHES the ticket (implicit-ack is the trustworthy signal; the link is convenience); an acked-but-still-untouched emergency re-alerts after a bounded interval. (b) Throttle the route (`->middleware('throttle:30,1')`). (c) Cut the token TTL to ~the escalation timeout (15–30 m), not 2 h. (d) Keep the ack URL OUT of SMS (see CO-11).
- **CO-6 (Task 10) — Implicit-ack must be a concrete, BROAD query** (not just a Reply), else escalation keeps paging while a tech is actively working. **FIX:** per member ticket, ack if ANY of: `responded_at > alerted_at`; OR a `TicketNote` with `who_type=Agent`, `ai_authored=false`, non-system `note_type`, `noted_at > alerted_at`; OR `assignee_id` set after `alerted_at`. Add an explicit `EmergencySweepTest` for implicit-ack.
- **CO-7 (Task 13) — The SSRF fix must PIN, not pre-check.** `TeamsNotifier` uses the `Http` facade (so `CURLOPT_RESOLVE`-as-`TacticalClient` is not a drop-in), and a bare `ipIsSafe()` pre-check leaves the DNS-rebind TOCTOU open; `gethostbynamel` is IPv4-only (AAAA bypass). **FIX:** either (a) rebuild `TeamsNotifier` on a raw Guzzle client using a SHARED `ssrfPinMiddleware` factored out of `TacticalClient` (it's public + resolver-injectable → real validate-and-pin); or (b) resolve A+AAAA, validate every address, and pin via `->withOptions(['curl'=>[CURLOPT_RESOLVE=>["{host}:443:{validatedIp}"]]])`. Add a DNS-rebind test (resolver returns public then private).
- **CO-8 (Task 7) — Pin per-tick idempotency + define `escalation_step`.** Add a test: a recent `last_pinged_at` (within timeout) → `notifyUser` NEVER called this tick + `last_pinged_at` unchanged (else the every-minute sweep spams). Define `escalation_step` = index into the ORDERED FULL chain; "current target" = first AVAILABLE member at/after `escalation_step`; "advance" = step past the current target, re-resolve. 3-member test: middle unavailable + first past timeout → lands on member 3.

### Medium

- **CO-9 (Task 9) — Atomic once-guard for max-hold.** Read-then-write race → double max-hold. **FIX:** CAS-claim before dispatch: `$claimed = TechnicianEmergency::where('id',$e->id)->whereNull('max_hold_sent_at')->update(['max_hold_sent_at'=>now()]); if(!$claimed) return;`. On a gate `held`/non-executed result, REVERT (`update(['max_hold_sent_at'=>null])`) so a later legit auto-send isn't permanently suppressed.
- **CO-10 (Task 10) — Exclude non-operational (prospect) clients.** `Ticket::open()->whereHas('client', fn($q)=>$q->operational())` (+ keep `clientExcluded`). Test: a prospect's aged P1 → no emergency. (Prospect intake is LIVE and creates tickets from unknown callers — don't escalate/max-hold a non-customer.)
- **CO-11 (Task 4/5/9) — SMS content + the MaxHoldSender test.** (a) SMS body = a NON-identifying stub ("AI Technician needs you — open the cockpit on #{id}"); keep client detail + the ack action behind the authenticated cockpit; no ack URL in SMS (no per-SMS redaction exists). (b) Fix the Task 9 test: the ticket needs a `contact` (Person + `contact_id`) or `sendTicketReplyNote->once()` never fires; ADD a no-contact test (executed, note created, `max_hold_sent_at` set ONCE, no email — so it can't re-fire forever chasing a send that can't happen).
- **CO-12 (Task 3) — Clamp AI severity + SLA caveat.** `$aiSeverity = max(0, min(5, $aiSeverity))` (guard the future AI-raise path against injected inflation). Drop the dead `method_exists($ticket,'isSlaBreach')` guard. NOTE: the SLA signal only fires when `due_at`/`response_due_at` are populated (contract-derived) — age+keyword are the real backstop; don't let soak/QA expect SLA detection on contract-less tickets. Verify the auto-ack does NOT set `responded_at` (else it permanently suppresses the age signal).

### Low (if cheap)
- **CO-13 (Task 1/11):** test `hasOpenEmergency` returns true for a NON-representative storm member (the JSON-contains branch is load-bearing for the halt-guard); note the JSON path is an intentional unindexed scan over the small open set (the `ticket_id` equality is the indexed fast path).
- **CO-14 (Task 6):** simplify `detected_by` — the final `:'ai'` arm is dead (only reachable when not-an-emergency); either populate `'both'` when reasons+ai or drop `'both'` from the migration comment.
- **CO-15 (Task 9):** assert disclosure via the `TechnicianDisclosure` sentinel constant, not a substring.
- **CO-16 (Task 8):** register `emergency.ack` OUTSIDE the `auth` group (web.php public-routes block, before line ~110) or an away operator gets bounced to SSO; add a public decode/claims helper to `EmergencyAckToken` so the controller reads `{em,u}` before verifying.
- **CO-17 (Task 10):** consider scanning EVERY tick (the `Ticket::open()` query is cheap+indexed) rather than throttling the scan — re-detection dedup is CO-1's `hasOpenEmergency`, not throttling; avoids a detection-latency floor (up to the reping interval) on a brand-new P1.
- **CO-18 (Task 4):** add a thrown-exception `Http::fake` to exercise the `SmsNotifier` fail-soft `catch` (the 401 path doesn't throw, so the catch is otherwise untested).

**Net:** the spine is sound; CO-1…CO-8 are must-fix during the build, CO-9…CO-12 fold into their tasks. Re-run the full Technician suite + an opus whole-branch review before the PR.
