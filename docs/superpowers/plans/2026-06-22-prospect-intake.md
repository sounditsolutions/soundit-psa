# Prospect Intake — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a tech capture and track a phone/voicemail request from a not-yet-customer (a "prospect") in one dedup-safe move, then promote it to a full client — without exposing the non-customer to the client portal, billing/integration sweeps, or the AI pipeline.

**Architecture:** A prospect is a real `Client` with `stage = Prospect` (a new axis orthogonal to the existing `is_active`). `client_id` is stable from first contact, so the whole ticket/call stack is reused and conversion migrates no history. Safety comes from gates keyed on `stage`: a portal lockout routed through one `Person::canAccessPortal()`, AI-pipeline early-outs, and a `scopeOperational()` predicate repointed across the billing/integration/picker sites.

**Tech Stack:** Laravel (PHP), Eloquent, PHPUnit feature tests on sqlite `:memory:`, Blade + light vanilla JS, Plivo telephony (`PhoneCall`), Pint for style.

**Source spec:** `docs/superpowers/specs/2026-06-22-prospect-intake-design.md` (rev 2).

## Global Constraints

- **Stage values:** `App\Enums\ClientStage { Prospect, Active }`. Default `Active`. String-backed (the repo uses **no** native DB ENUM — `string` column + PHP backed-enum cast everywhere).
- **`scopeActive()` is NOT changed.** It stays `where('is_active', true)`; the client-management list rides it and must keep showing prospects. Add a *new* `scopeOperational()` = `stage=Active AND is_active=true` for exclude-prospect sites.
- **Provisioning invariant (security):** no code path may set `portal_enabled = true`, set a `password`, or grant a portal session for a `Person` whose `client.stage === Prospect`. Provisioned prospect People have `portal_enabled = false`, `password = null`.
- **No silent auto-create:** a prospect Client/Person/Ticket is only ever created by an explicit staff action, after a search + (on phone match) a confirm.
- **Tests:** `use RefreshDatabase;`, `Bus::fake()` in `setUp()` (ticket/client creation fires observers), factories, `actingAs()`. Run a single file with `php artisan test <path>`; full suite with `php artisan test`. Run `./vendor/bin/pint --test <changed.php>` before every commit (CI's style gate blocks otherwise).
- **Commit discipline:** explicit `git add <files>` (never `-am` — the repo has live `.beads/`/`.gc/` runtime drift). Each task ends green + committed.
- **Verify-then-edit:** cited `file:line` come from a review panel and may have drifted. Before editing an existing method, open it at the cited symbol and confirm; the **test** in each task is the authoritative contract.
- **Wording:** internal value `Prospect`; status badge "Prospect"; create control "+ New client"; queue facet "Unknown caller."

## File Structure

| File | Responsibility | New/Mod |
|------|----------------|---------|
| `app/Enums/ClientStage.php` | The lifecycle enum | New |
| `database/migrations/2026_06_22_000000_add_stage_to_clients.php` | `stage` string column | New |
| `app/Models/Client.php` | cast + `scopeOperational()` | Mod |
| `database/factories/ClientFactory.php` | `prospect()` state | Mod |
| `app/Models/Person.php` | `canAccessPortal()` | Mod |
| `app/Services/Prospect/ProspectIntakeService.php` | dedup matcher + provision + convert | New |
| `app/Http/Controllers/Web/ProspectController.php` | provision / dismiss / convert actions | New |
| (many) billing/sync/picker call sites | repoint to `->operational()` | Mod |
| portal controllers + `PortalAuthenticate` | route through `canAccessPortal()` | Mod |
| `app/Observers/TicketObserver.php`, `RunTriagePipeline`, `MineTicketKnowledge`, `CallController::buildTicketSuggestions` | stage gates | Mod |
| `app/Models/PhoneCall.php`, `PhoneCallService::getRecentCalls`, call views | "Unknown caller" facet + capture UI | Mod |
| `tests/Feature/Prospect/*` | the tests | New |

---

### Task 1: `ClientStage` enum, `stage` column, cast, factory state, `scopeOperational()`

**Files:**
- Create: `app/Enums/ClientStage.php`
- Create: `database/migrations/2026_06_22_000000_add_stage_to_clients.php`
- Modify: `app/Models/Client.php` (casts array; add `scopeOperational`)
- Modify: `database/factories/ClientFactory.php` (add `prospect()` state)
- Test: `tests/Feature/Prospect/ClientStageTest.php`

**Interfaces:**
- Produces: `App\Enums\ClientStage::{Prospect,Active}` (string-backed: `'prospect'`,`'active'`); `Client::scopeOperational(Builder)` → `stage=Active AND is_active=true`; `Client::factory()->prospect()` → `stage=Prospect, is_active=true`.

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace Tests\Feature\Prospect;

use App\Enums\ClientStage;
use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientStageTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_clients_default_to_active_stage(): void
    {
        $c = Client::factory()->create();
        $this->assertSame(ClientStage::Active, $c->fresh()->stage);
    }

    public function test_scope_operational_excludes_prospects_and_suspended_but_active_scope_keeps_prospects(): void
    {
        $active    = Client::factory()->create(['is_active' => true]);
        $prospect  = Client::factory()->prospect()->create();              // stage=Prospect, is_active=true
        $suspended = Client::factory()->create(['is_active' => false]);    // stage=Active, is_active=false

        $operational = Client::operational()->pluck('id');
        $this->assertTrue($operational->contains($active->id));
        $this->assertFalse($operational->contains($prospect->id));   // prospect excluded from "real customer"
        $this->assertFalse($operational->contains($suspended->id));  // suspended excluded too

        $listed = Client::active()->pluck('id');                     // scopeActive UNCHANGED = is_active=true
        $this->assertTrue($listed->contains($prospect->id));         // prospect still appears in the Clients list
        $this->assertFalse($listed->contains($suspended->id));
    }
}
```

- [ ] **Step 2: Run test, verify it fails**

Run: `php artisan test tests/Feature/Prospect/ClientStageTest.php`
Expected: FAIL — `ClientStage` not found / `operational` scope undefined.

- [ ] **Step 3: Create the enum**

```php
<?php
namespace App\Enums;

enum ClientStage: string
{
    case Prospect = 'prospect';
    case Active = 'active';

    public function label(): string
    {
        return match ($this) {
            self::Prospect => 'Prospect',
            self::Active => 'Active',
        };
    }
}
```

- [ ] **Step 4: Create the migration** (string column, default fills rows atomically — no backfill step)

```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('stage')->default('active')->index()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('clients', fn (Blueprint $table) => $table->dropColumn('stage'));
    }
};
```

- [ ] **Step 5: Add the cast + scope to `Client`**

In `app/Models/Client.php`: add `'stage' => \App\Enums\ClientStage::class` to the `casts()` array (or `$casts`, match the file's style — `Client` uses a `casts()` method or `$casts` property; follow what's there), ensure `stage` is in `$fillable` ONLY if needed by tests (prefer NOT fillable — set explicitly in services), and add:

```php
public function scopeOperational(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
{
    return $query->where('stage', \App\Enums\ClientStage::Active)->where('is_active', true);
}
```

Leave `scopeActive()` exactly as-is.

- [ ] **Step 6: Add the factory state**

In `database/factories/ClientFactory.php`:

```php
public function prospect(): static
{
    return $this->state(fn () => ['stage' => \App\Enums\ClientStage::Prospect, 'is_active' => true]);
}
```

(The default factory definition needs no change — the column default makes new clients `Active`. If the factory sets attributes explicitly, add `'stage' => ClientStage::Active` to the base definition.)

- [ ] **Step 7: Run test, verify pass**

Run: `php artisan test tests/Feature/Prospect/ClientStageTest.php` → PASS.

- [ ] **Step 8: Pint + commit**

```bash
./vendor/bin/pint --test app/Enums/ClientStage.php app/Models/Client.php database/factories/ClientFactory.php database/migrations/2026_06_22_000000_add_stage_to_clients.php tests/Feature/Prospect/ClientStageTest.php
git add app/Enums/ClientStage.php app/Models/Client.php database/factories/ClientFactory.php database/migrations/2026_06_22_000000_add_stage_to_clients.php tests/Feature/Prospect/ClientStageTest.php
git commit -m "feat(prospect): ClientStage enum + scopeOperational"
```

---

### Task 2: Repoint exclude-prospect sites to `scopeOperational`

A prospect (`is_active=true`) currently leaks into pickers, the reseller billing roll-up, the license-sync fleet, the Huntress alert resolver, and the Stripe/QBO auto-matchers. Repoint each to `->operational()`.

**Files (verify each symbol before editing — line numbers may have drifted):**
- Modify pickers: `app/Http/Controllers/Web/TicketController.php` (~:56), `app/Http/Controllers/Web/CallController.php` (~:82, ~:259), and the `PersonController`/`AssetController` client dropdowns — change `Client::active()` → `Client::operational()`.
- Modify raw `where('is_active', true)` readers → `->operational()` (these are direct `where`, NOT the scope): `app/Services/BillingService.php` (~:161 `countResellerLicensesByType`, ~:514, ~:590), `app/Services/Huntress/HuntressService.php` (~:310, ~:318), and the license-sync fleet: `CippLicenseSyncService.php` (~:22 and its siblings ×5), `NinjaBackupSyncService.php` (~:196), `ZorusSyncService`, `ControlDSyncService`, `PrintixSyncService`, `ServositySyncService`, `MeshSyncService`, `CometBackupSyncService`, `AppRiverSyncService`. Pattern: `->whereNotNull('<vendor>_id')->where('is_active', true)` → `->whereNotNull('<vendor>_id')->operational()` (the `whereNotNull` stays).
- Modify auto-match: `app/Services/Stripe/StripeSyncService.php` (~:42), `app/Services/Qbo/QboSyncService.php` (~:47) — `Client::active()->whereNull(...)` → `Client::operational()->whereNull(...)`.
- Test: `tests/Feature/Prospect/ProspectExclusionTest.php`

**Interfaces:**
- Consumes: `Client::operational()` (Task 1).

- [ ] **Step 1: Write the failing test** (representative one per category — picker, billing roll-up, auto-match, a license sync)

```php
<?php
namespace Tests\Feature\Prospect;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class ProspectExclusionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); Bus::fake(); }

    public function test_prospect_is_absent_from_the_ticket_create_client_picker(): void
    {
        $user = User::factory()->create();
        $active = Client::factory()->create(['name' => 'Acme Active']);
        $prospect = Client::factory()->prospect()->create(['name' => 'Tirekicker Prospect']);

        $resp = $this->actingAs($user)->get(route('tickets.create'))->assertOk();
        $resp->assertSee('Acme Active', false);
        $resp->assertDontSee('Tirekicker Prospect', false);
    }

    public function test_stripe_auto_match_candidate_set_excludes_prospects(): void
    {
        Client::factory()->create(['name' => 'Real Co', 'stripe_customer_id' => null]);
        Client::factory()->prospect()->create(['name' => 'Real Co', 'stripe_customer_id' => null]);

        // The candidate query the auto-matcher uses must not contain the prospect.
        $candidates = Client::operational()->whereNull('stripe_customer_id')->pluck('name');
        $this->assertSame(1, $candidates->filter(fn ($n) => $n === 'Real Co')->count());
    }
}
```

(Add a billing-rollup test and one license-sync test in the same file mirroring the pattern: build a prospect with the relevant column set, assert the service's count/sweep omits it.)

- [ ] **Step 2: Run, verify fail** — `php artisan test tests/Feature/Prospect/ProspectExclusionTest.php` → FAIL (prospect currently appears).

- [ ] **Step 3: Repoint the sites.** Apply the `Client::active()`→`Client::operational()` and raw-`where('is_active',true)`→`->operational()` edits enumerated in **Files** above. Grep to find them all:

```bash
grep -rn "Client::active()" app/ | grep -vE "ClientController|Portal"   # pickers/syncs to repoint
grep -rn "->where('is_active', *true)" app/Services app/Http             # raw readers (Client context only)
```

For each raw reader, confirm it's querying **`Client`** (not `Person`/another model) before repointing. Leave `ClientController::index` (the client list) and any `Person`/other-model `is_active` reads untouched.

- [ ] **Step 4: Run the new test + full suite**

```bash
php artisan test tests/Feature/Prospect/ProspectExclusionTest.php   # PASS
php artisan test                                                     # no regressions
```

- [ ] **Step 5: Pint + commit** (stage exactly the files you edited)

```bash
git commit -m "feat(prospect): exclude prospects from pickers, billing rollup, syncs, auto-match"
```

---

### Task 3: Portal lockout — `Person::canAccessPortal()` routed through every grant path

The blocker. `portal_enabled` is flipped true / a session is granted by ~7 paths, none stage-aware. Centralize on one predicate and route all of them through it.

**Files (verify symbols):**
- Modify: `app/Models/Person.php` — add `canAccessPortal()`.
- Modify: `app/Http/Controllers/Portal/PortalAuthController.php` — `login` attempt (~:38), `sendAccessLink` (~:145), `verifyAccess` (~:197-208), `sendResetLink` (~:75), `resetPassword` (~:104-129, the `:119` login).
- Modify: `app/Http/Controllers/Web/PortalManagementController.php` — `invite` (~:57), `toggle` (~:96), `impersonate` (~:161).
- Modify: `app/Http/Middleware/PortalAuthenticate.php` (~:16) — defense-in-depth.
- Test: `tests/Feature/Prospect/ProspectPortalLockoutTest.php`

**Interfaces:**
- Produces: `Person::canAccessPortal(): bool` = `portal_enabled && is_active && client?->stage === ClientStage::Active`.

- [ ] **Step 1: Write the failing test** (one per public/staff path)

```php
<?php
namespace Tests\Feature\Prospect;

use App\Models\Client;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProspectPortalLockoutTest extends TestCase
{
    use RefreshDatabase;

    private function prospectContact(array $attrs = []): Person
    {
        $client = Client::factory()->prospect()->create();
        return Person::factory()->create(array_merge([
            'client_id' => $client->id,
            'email' => 'lead@example.test',
            'is_active' => true,
            'portal_enabled' => false,
            'password' => null,
        ], $attrs));
    }

    public function test_can_access_portal_is_false_for_a_prospect_contact(): void
    {
        $p = $this->prospectContact(['portal_enabled' => true, 'password' => Hash::make('x')]);
        $this->assertFalse($p->fresh()->canAccessPortal());   // stage gate beats even enabled+password
    }

    public function test_send_access_link_does_nothing_for_a_prospect_contact(): void
    {
        $p = $this->prospectContact();
        $this->post(route('portal.access.send'), ['email' => $p->email])
            ->assertSessionHasNoErrors();              // no leak that the email exists
        // Assert no token/notification was created for this person (mirror how sendAccessLink stores tokens).
    }

    public function test_password_reset_chain_is_inert_for_a_prospect_contact(): void
    {
        $p = $this->prospectContact();
        $this->post(route('portal.password.email'), ['email' => $p->email]);
        // Assert no reset token row exists for this email; resetPassword route should 403/redirect without login.
    }

    public function test_staff_invite_is_blocked_for_a_prospect_contact(): void
    {
        $staff = User::factory()->create();
        $p = $this->prospectContact();
        $this->actingAs($staff)->post(route('clients.portal.invite', $p->client), ['person_id' => $p->id])
            ->assertForbidden();
        $this->assertFalse($p->fresh()->portal_enabled);
        $this->assertNull($p->fresh()->password);
    }
}
```

(Confirm the exact route names from `routes/portal.php` + `routes/web.php`; adjust the names/params to match. Add a `login`-path test: a prospect contact with a password set somehow still cannot `POST` login.)

- [ ] **Step 2: Run, verify fail** — paths currently allow the prospect through.

- [ ] **Step 3: Add the predicate to `Person`**

```php
public function canAccessPortal(): bool
{
    return $this->portal_enabled
        && $this->is_active
        && $this->client?->stage === \App\Enums\ClientStage::Active;
}
```

- [ ] **Step 4: Route every path through stage.** Concretely:
  - `PortalAuthenticate` middleware: change the guard to also reject when the person's `client?->stage !== ClientStage::Active` (or call `!$person->canAccessPortal()`).
  - `login`: after resolving the Person but before `Auth::login`, reject if `client?->stage !== Active`.
  - `sendAccessLink` / `verifyAccess` / `sendResetLink` / `resetPassword`: add `->whereHas('client', fn ($q) => $q->where('stage', ClientStage::Active))` to the Person lookup (or guard `abort_if($person->client?->stage !== ClientStage::Active, 404)` right after the lookup, before any token mint / `portal_enabled` flip / login). The cleanest durable option (do this if low-risk): add a global scope or a constraint on the portal user provider so the broker/guard only ever see stage=Active people — then login + reset are covered structurally.
  - `PortalManagementController::invite/toggle/impersonate`: `abort_if($person->client?->stage !== ClientStage::Active, 403)` before flipping `portal_enabled` / minting a token / impersonating.

- [ ] **Step 5: Run the lockout test + full suite** → PASS / no regressions. (If you added a global scope, run the existing portal tests to confirm legitimate Active-client portal users still work.)

- [ ] **Step 6: Pint + commit** — `git commit -m "feat(prospect): lock prospects out of every client-portal grant path"`

---

### Task 4: AI-pipeline gates (provision pre-fill, triage, notifications, mining)

**Files (verify symbols):**
- Modify: `app/Http/Controllers/Web/CallController.php` (`buildTicketSuggestions` ~:477, called at form render ~:248) — skip for prospect provisioning.
- Modify: `app/Observers/TicketObserver.php` — `created()` (`notifyTicketCreated` ~:24, `RunTriagePipeline::dispatch` ~:35); gate inside the dispatched jobs is cleanest.
- Modify: `app/Jobs/RunTriagePipeline.php` and `app/Jobs/MineTicketKnowledge.php` (fired from `TicketObserver::updated` ~:63) — early-out when `stage=Prospect`.
- Test: `tests/Feature/Prospect/ProspectAiGateTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace Tests\Feature\Prospect;

use App\Jobs\MineTicketKnowledge;
use App\Jobs\RunTriagePipeline;
use App\Models\Client;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class ProspectAiGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); Bus::fake(); }

    public function test_a_prospect_ticket_does_not_dispatch_triage(): void
    {
        $prospect = Client::factory()->prospect()->create();
        Ticket::factory()->create(['client_id' => $prospect->id]);
        Bus::assertNotDispatched(RunTriagePipeline::class);
    }

    public function test_an_active_client_ticket_still_dispatches_triage(): void
    {
        $client = Client::factory()->create();           // stage=Active
        Ticket::factory()->create(['client_id' => $client->id]);
        Bus::assertDispatched(RunTriagePipeline::class);
    }
}
```

(Add: `notifyTicketCreated` not called for a prospect ticket — assert no notification rows / mock `NotificationService`. And: a resolved prospect ticket does not dispatch `MineTicketKnowledge` — drive the resolve and `Bus::assertNotDispatched`.)

- [ ] **Step 2: Run, verify fail** (triage currently dispatches for the prospect ticket).

- [ ] **Step 3: Gate the jobs/observer.** In `TicketObserver::created`, wrap the `notifyTicketCreated` + `RunTriagePipeline::dispatch` so they only run when `$ticket->client?->stage === ClientStage::Active` (re-fetch the client; a prospect ticket has one). In `MineTicketKnowledge::handle` (and/or the `updated()` dispatch) add an early `return` when the ticket's client is a prospect. In `CallController`, skip the `buildTicketSuggestions` LLM call on the prospect-provision path (use the existing `legacySubject`/default subject instead).

- [ ] **Step 4: Run test + full suite** → PASS / no regressions.

- [ ] **Step 5: Pint + commit** — `git commit -m "feat(prospect): gate AI triage/notifications/mining/prefill on stage"`

---

### Task 5: "Unknown caller" facet on the Call Log

**Files (verify symbols):**
- Modify: `app/Models/PhoneCall.php` — add `scopeUnknownCaller`.
- Modify: `app/Services/PhoneCallService.php` (`getRecentCalls` ~:472) — new filter branch.
- Modify: `resources/views/calls/index.blade.php` (the status filter ~:10) — add the option.
- Test: `tests/Feature/Prospect/UnknownCallerFacetTest.php`

- [ ] **Step 1: Write the failing test** — the key fix: an **answered** unknown caller must surface.

```php
public function test_unknown_caller_facet_includes_answered_calls_not_just_voicemails(): void
{
    $answered = PhoneCall::factory()->create([
        'client_id' => null, 'followed_up_at' => null, 'status' => \App\Enums\CallStatus::Completed,
    ]);
    $resolved = PhoneCall::factory()->create([
        'client_id' => \App\Models\Client::factory(), 'followed_up_at' => null,
    ]);
    $ids = PhoneCall::unknownCaller()->pluck('id');
    $this->assertTrue($ids->contains($answered->id));      // the bug the old status-scoped queue hid
    $this->assertFalse($ids->contains($resolved->id));
}
```

- [ ] **Step 2: Run, verify fail.**
- [ ] **Step 3: Add the scope** — `scopeUnknownCaller` = `whereNull('client_id')->whereNull('followed_up_at')` (NOT a child of `scopeUnfollowedUp`). Add the `getRecentCalls` branch + the filter-bar option ("Unknown caller — needs follow-up").
- [ ] **Step 4: Run test + full suite** → PASS.
- [ ] **Step 5: Pint + commit** — `git commit -m "feat(prospect): unknown-caller facet on the call log"`

---

### Task 6: `ProspectIntakeService` — dedup matcher + provision

**Files:**
- Create: `app/Services/Prospect/ProspectIntakeService.php`
- Test: `tests/Feature/Prospect/ProspectIntakeServiceTest.php`

**Interfaces:**
- Produces:
  - `matchByNumber(string $rawNumber): ?Client` — normalizes via `PhoneNumber::normalize`, returns the Client owning a Person with that phone/mobile, else null.
  - `provisionFromCall(PhoneCall $call, string $name): array{client: Client, person: Person, ticket: Ticket}` — creates `Client(stage=Prospect)`, a `Person` whose **`phone` = `PhoneNumber::normalize($call->from_number)`** (`portal_enabled=false`, `password=null`), and a Ticket seeded from the call (no LLM). Wrapped in a DB transaction.

- [ ] **Step 1: Write the failing test**

```php
public function test_provision_seeds_the_normalized_number_and_repeat_calls_match(): void
{
    $call = PhoneCall::factory()->create(['from_number' => '+1 (555) 010-2030', 'client_id' => null]);
    $svc = app(\App\Services\Prospect\ProspectIntakeService::class);

    $out = $svc->provisionFromCall($call, 'Cascade Dental');

    $this->assertSame(\App\Enums\ClientStage::Prospect, $out['client']->stage);
    $this->assertSame(\App\Support\PhoneNumber::normalize('+1 (555) 010-2030'), $out['person']->phone);
    $this->assertFalse($out['person']->portal_enabled);
    $this->assertNull($out['person']->password);

    // a second call from the same number resolves to the SAME prospect (no duplicate)
    $this->assertTrue($svc->matchByNumber('555-010-2030')?->is($out['client']));
}
```

- [ ] **Step 2–4:** Run-fail → implement the service (transaction; explicit `stage`, `portal_enabled=false`, `password=null`; normalized phone) → run-pass.
- [ ] **Step 5: Pint + commit** — `git commit -m "feat(prospect): intake service (dedup matcher + provision)"`

---

### Task 7: Capture UI — client-search control + "+ New client" (call page & call→ticket form)

**Files (verify symbols):**
- Modify: `resources/views/calls/show.blade.php` (the unresolved-caller branch) + `resources/views/calls/create-ticket.blade.php` (replace the dead-end client `<select>`).
- Create: `app/Http/Controllers/Web/ProspectController.php` (`store` → calls `ProspectIntakeService::provisionFromCall`, with the confirm-dedup flow) + routes.
- Modify: a small client-search endpoint if none exists (reuse `Client::scopeSearch`, `Client.php:192`).
- Test: `tests/Feature/Prospect/ProspectCaptureTest.php`

**Interfaces:** Consumes Task 6's service; `Client::scopeSearch($term)`.

- [ ] **Step 1: Write the failing test**

```php
public function test_unresolved_call_page_offers_search_first_then_new_client_fallback(): void
{
    $user = User::factory()->create();
    $call = PhoneCall::factory()->create(['client_id' => null, 'from_number' => '+15550102030']);
    $resp = $this->actingAs($user)->get(route('calls.show', $call))->assertOk();
    $resp->assertSee('name="client_search"', false);              // search control is present
    $resp->assertSee(route('prospects.store'), false);            // "+ New client" posts to provision
}

public function test_creating_a_prospect_from_a_call_provisions_client_person_ticket(): void
{
    $user = User::factory()->create();
    $call = PhoneCall::factory()->create(['client_id' => null, 'from_number' => '+15550102030']);
    $this->actingAs($user)->post(route('prospects.store'), [
        'phone_call_id' => $call->id, 'name' => 'Cascade Dental', 'confirm_new' => '1',
    ])->assertRedirect();
    $this->assertDatabaseHas('clients', ['name' => 'Cascade Dental', 'stage' => 'prospect']);
}
```

(Add a confirm-dedup test: posting without `confirm_new` when `matchByNumber` hits returns the "attach to existing?" path rather than creating.)

- [ ] **Step 2–4:** Run-fail → implement controller + routes + the Blade search control (first element; results from a `clients.search` JSON endpoint over `scopeSearch`; "+ New client '[CallerID]'" rendered below, posts to `prospects.store`; confirm step when `matchByNumber` hits) → run-pass.
- [ ] **Step 5: Pint + commit** — `git commit -m "feat(prospect): search-first capture UI + provision action"`

---

### Task 8: "Not a lead / dismiss"

**Files:** `ProspectController::dismiss` (sets `followed_up_at=now()`, creates nothing) + route + a one-click button beside Block on `calls/show.blade.php`. Test: `tests/Feature/Prospect/DismissTest.php`.

- [ ] **Step 1: Test** — dismiss sets `followed_up_at`, creates **no** Client/Person/Ticket, and the call is still present in the full Call Log (just absent from the unknown-caller facet).
- [ ] **Steps 2–4:** fail → implement → pass.
- [ ] **Step 5: Pint + commit** — `git commit -m "feat(prospect): dismiss unknown caller (no record)"`

---

### Task 9: Convert to client (guided, carries the request)

**Files:** `ProspectController::convert` (new action — `stage` is NOT mass-fillable; set it explicitly) + a convert success view that echoes the prospect's open tickets/notes and links assign-tech / create-contract / provision. Test: `tests/Feature/Prospect/ConvertTest.php`.

**Interfaces:** Consumes `ProspectIntakeService` (add `convert(Client $prospect): Client`).

- [ ] **Step 1: Write the failing test**

```php
public function test_convert_flips_stage_preserves_history_and_reenables_triage(): void
{
    Bus::fake();
    $user = User::factory()->create();
    $prospect = Client::factory()->prospect()->create();
    $ticket = Ticket::factory()->create(['client_id' => $prospect->id]);   // inert prospect ticket

    $this->actingAs($user)->post(route('prospects.convert', $prospect))->assertRedirect();

    $prospect->refresh();
    $this->assertSame(\App\Enums\ClientStage::Active, $prospect->stage);
    $this->assertTrue($ticket->fresh()->client->is($prospect));            // history preserved, same client_id

    // future ticket on the converted client now runs triage
    Ticket::factory()->create(['client_id' => $prospect->id]);
    Bus::assertDispatched(\App\Jobs\RunTriagePipeline::class);
}
```

- [ ] **Steps 2–4:** fail → implement convert (flip stage; success screen echoes `$prospect->tickets` + their notes; prompt-only onboarding links) → pass.
- [ ] **Step 5: Pint + commit** — `git commit -m "feat(prospect): convert-to-client (guided, history-preserving)"`

---

### Task 10: Reporting + Prospect badge

**Files:** repoint any customer-count/KPI that uses raw `is_active` to `scopeOperational`/`stage=Active`; add a "Prospect" badge to the client + ticket headers (`clients/show.blade.php`, `tickets/show.blade.php`) shown when `stage=Prospect`. Test: `tests/Feature/Prospect/ReportingAndBadgeTest.php`.

- [ ] **Step 1: Test** — creating a prospect does NOT increment the dashboard customer count; the client/ticket header renders a "Prospect" badge for a prospect and not for an Active client.
- [ ] **Steps 2–4:** fail → implement → pass.
- [ ] **Step 5: Pint + commit** — `git commit -m "feat(prospect): keep prospects out of customer counts + Prospect badge"`

---

## Final verification (after all tasks)

- [ ] `php artisan test` — full suite green.
- [ ] `./vendor/bin/pint --test app/ database/ tests/` — clean.
- [ ] Manual smoke on dev (`https://127.0.0.1`): an unknown inbound call → search-first → "+ New client" → prospect+ticket; the prospect is absent from a new ticket's client dropdown but present in the Clients list with a Prospect badge; portal access-link to the prospect's email does nothing; Convert flips it and the success screen shows the original request.

## Self-review notes (coverage)

Every spec section maps to a task: model/scope → T1; the kind-(b) `is_active` audit + auto-match → T2; the full portal surface → T3; the three AI gates → T4; the facet predicate fix → T5; dedup matcher + normalized-seed provision → T6; search-first capture → T7; dismiss → T8; guided history-carrying convert → T9; reporting + badge + wording → T10. The SLA "no work needed" and "no outbound sync on create" invariants need no task (confirmed inert) but are covered by the full-suite regression gate.
