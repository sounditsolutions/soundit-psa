# AI Technician — Phase 1C (Notify + Digest + Dead-Man's-Switch) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the away operator *show up* and *trust the silence*: a daily, operator-timezone digest of what's awaiting approval and what the AI did, pushed to Teams (a PSA-native webhook) with email as the guaranteed fallback — and a dead-man's-switch heartbeat that turns a silent failure (the Technician worker dies, or the digest stops) into a loud alert.

**Architecture:** An `OperatorNotifier` abstraction with two sinks — a `TeamsNotifier` that `Http::post`s a card to a configured Teams **webhook URL** (incoming-webhook / Power Automate "Workflow" — because app-only Graph chat-posting is a Microsoft Protected API), and the existing `EmailService::sendNew` as the always-on fallback. A `DigestBuilder` reads the tested 1A/1B read models (`CockpitQuery`, `TechnicianActionLog`) into a digest body; a scheduled `technician:digest` command delivers it at the operator's local time and records `technician_last_digest_at`. `RunTechnicianLoop` writes a `technician_worker_last_seen` heartbeat each run; a scheduled `technician:heartbeat` command (running on the web/cron scheduler, not the Technician worker) alerts if the worker is stale or a digest was missed. The dedicated `technician` queue worker already shipped in 1A.

**Tech Stack:** PHP 8.3, Laravel 12, `Illuminate\Support\Facades\Http` (webhook), the existing `EmailService`→`GraphClient` (email), `Setting`-backed config, `routes/console.php` `Schedule::command`, `AppTimezone`, PHPUnit on sqlite `:memory:` (`Http::fake`, `$this->artisan(...)`, Mockery service doubles), Pint.

## Global Constraints

These apply to **every** task; each task's requirements implicitly include this section.

- **Builds on 1A + 1B (PRs #57 merged, #58).** Branch off `main` after #58 merges, or off `feat/ai-technician-phase-1b`. `CockpitQuery`, `TechnicianRun`/`TechnicianRunState` (incl. `Denied`/`Superseded`), `TechnicianActionLog`, `TechnicianConfig`, `RunTechnicianLoop`, `EmailService::sendNew`, `AppTimezone` all exist.
- **Runtime:** PHP 8.3 / Laravel 12. Tests: sqlite `:memory:` (`RefreshDatabase`); `Setting::setValue`/`setEncrypted`; mock outbound transports (`Http::fake()` for the webhook, `$this->mock(EmailService::class)` for email, `$this->mock(OperatorNotifier::class)` to isolate commands). **No `Mail::fake()`** — all mail is `EmailService`→`GraphClient`. Pint-clean before each commit.
- **Teams is a webhook URL, not Graph chat-posting (verified).** `POST /chats/{id}/messages` app-only is a Microsoft **Protected API** (pre-approval required); the realistic PSA-native path is a configured Teams **incoming-webhook / Power Automate Workflow URL** the operator provisions once. `TeamsNotifier` is a plain `Http::post($url, $card)` — the `GraphClient` is NOT involved. Build it behind the `OperatorNotifier` seam so a future Graph/Bot sender can replace it.
- **Email is the guaranteed fallback.** Every operator notification is delivered by email (`EmailService::sendNew($notifyEmail, …)`) AND, if a webhook is configured, to Teams. A missing/failed Teams webhook never loses the notification.
- **Fail-soft, never crash the scheduler.** A webhook or email failure is caught + logged; a notify failure never fails the `schedule:run` tick or the Loop.
- **Dormant-safe + enabled-gated.** The digest + heartbeat schedules are `->when(fn () => TechnicianConfig::enabled())`. Merging while disabled sends nothing.
- **The dead-man's-switch is the point.** The scariest failure is *silent* (worker down / digest stopped). The heartbeat command runs on the web/cron scheduler so it fires even when the Technician queue worker is down, and self-throttles its alert (no spam).
- **Emergencies are Phase 2.** The 1C digest summarizes pending approvals + actions taken + the "needs you" count. Emergency raising/escalation is NOT in 1C.

---

## File Structure

**Created:**

| Path | Responsibility |
|------|----------------|
| `app/Services/Technician/Notify/TeamsNotifier.php` | `post(string $title, string $body): bool` — `Http::post` a simple card to the configured Teams webhook URL. Fail-soft. The only Teams-touching code. |
| `app/Services/Technician/Notify/OperatorNotifier.php` | `notify(string $subject, string $body): void` — deliver to Teams (if a webhook is set) AND email (if a notify email is set), each fail-soft. The single notify seam every command uses. |
| `app/Services/Technician/Notify/DigestBuilder.php` | `build(): TechnicianDigest` — read `CockpitQuery` + `TechnicianActionLog` into a `{subject, body, isEmpty}` digest. Pure (no I/O beyond reads). |
| `app/Console/Commands/TechnicianDigest.php` | `technician:digest` — build + notify + record `technician_last_digest_at`. |
| `app/Console/Commands/TechnicianHeartbeat.php` | `technician:heartbeat` — the dead-man's-switch: alert (throttled) if the worker is stale or a digest was missed. |
| `tests/Feature/Technician/Notify/TeamsNotifierTest.php` | Tests Task 1. |
| `tests/Feature/Technician/Notify/OperatorNotifierTest.php` | Tests Task 2. |
| `tests/Feature/Technician/Notify/DigestBuilderTest.php` | Tests Task 3. |
| `tests/Feature/Technician/Notify/TechnicianDigestCommandTest.php` | Tests Task 4 + 5. |
| `tests/Feature/Technician/Notify/TechnicianHeartbeatCommandTest.php` | Tests Task 6 + 7. |

**Modified:**

| Path | Change |
|------|--------|
| `app/Support/TechnicianConfig.php` | Add `teamsWebhookUrl()`, `notifyEmail()`, `digestEnabled()`, `digestTimeLocal()`, `heartbeatIntervalMinutes()`, `lastDigestAt()`/`recordDigestSent()`, `workerLastSeen()`/`recordWorkerSeen()`. |
| `app/Jobs/RunTechnicianLoop.php` | Write the worker heartbeat (`recordWorkerSeen()`) at the top of `handle()`. |
| `routes/console.php` | Schedule `technician:digest` (operator-local, enabled-gated) + `technician:heartbeat` (every minute, enabled-gated, self-throttled). |
| `resources/views/settings/integrations.blade.php` + `app/Http/Controllers/Web/IntegrationsController.php` | Extend the "AI Technician" card with the webhook URL, notify email, digest toggle/time, heartbeat interval. |

---

## Tasks

### Task 1: `TeamsNotifier` — post a card to the configured webhook

**Files:**
- Create: `app/Services/Technician/Notify/TeamsNotifier.php`
- Modify: `app/Support/TechnicianConfig.php` (add `teamsWebhookUrl()`)
- Test: `tests/Feature/Technician/Notify/TeamsNotifierTest.php`

**Interfaces:**
- Consumes: `Illuminate\Support\Facades\Http`; `App\Support\TechnicianConfig::teamsWebhookUrl(): ?string`; `App\Models\Setting`.
- Produces:
  - `TechnicianConfig::teamsWebhookUrl(): ?string` — Setting `technician_teams_webhook_url`, null/empty → null.
  - `App\Services\Technician\Notify\TeamsNotifier::post(string $title, string $body): bool` — if no webhook configured → return false (not an error); else `Http::timeout(10)->post($url, <MessageCard JSON>)`; return true on a 2xx, false otherwise; never throws (catch `\Throwable`, log, return false).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Technician\Notify;

use App\Models\Setting;
use App\Services\Technician\Notify\TeamsNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TeamsNotifierTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_webhook_configured_is_a_noop_false(): void
    {
        Http::fake();
        $this->assertFalse(app(TeamsNotifier::class)->post('Subject', 'Body'));
        Http::assertNothingSent();
    }

    public function test_posts_a_card_to_the_configured_webhook(): void
    {
        Setting::setValue('technician_teams_webhook_url', 'https://example.webhook.office.com/hook');
        Http::fake(['*' => Http::response('', 200)]);

        $this->assertTrue(app(TeamsNotifier::class)->post('Daily digest', 'You have 3 drafts.'));

        Http::assertSent(fn ($req) => $req->url() === 'https://example.webhook.office.com/hook'
            && str_contains(json_encode($req->data()), 'You have 3 drafts.'));
    }

    public function test_a_non_2xx_or_throw_returns_false_and_does_not_crash(): void
    {
        Setting::setValue('technician_teams_webhook_url', 'https://example.webhook.office.com/hook');
        Http::fake(['*' => Http::response('nope', 500)]);

        $this->assertFalse(app(TeamsNotifier::class)->post('S', 'B'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=TeamsNotifierTest`
Expected: FAIL — class + config method not found.

- [ ] **Step 3: Add `teamsWebhookUrl()` to `TechnicianConfig`**

```php
    public static function teamsWebhookUrl(): ?string
    {
        $value = Setting::getValue('technician_teams_webhook_url');

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
```

- [ ] **Step 4: Write the notifier**

Create `app/Services/Technician/Notify/TeamsNotifier.php`:

```php
<?php

namespace App\Services\Technician\Notify;

use App\Support\TechnicianConfig;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Posts a notification card to the operator's configured Teams webhook URL
 * (incoming-webhook / Power Automate Workflow). App-only Graph chat-posting is a
 * Microsoft Protected API, so a webhook the operator provisions once is the
 * realistic PSA-native path. Fail-soft: a missing/failing webhook never throws.
 */
class TeamsNotifier
{
    public function post(string $title, string $body): bool
    {
        $url = TechnicianConfig::teamsWebhookUrl();
        if ($url === null) {
            return false;
        }

        try {
            $response = Http::timeout(10)->post($url, [
                '@type' => 'MessageCard',
                '@context' => 'https://schema.org/extensions',
                'summary' => $title,
                'themeColor' => '0F6CBD',
                'title' => $title,
                'text' => $body,
            ]);

            return $response->successful();
        } catch (\Throwable $e) {
            Log::warning('[Technician] Teams webhook post failed', ['error' => $e->getMessage()]);

            return false;
        }
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=TeamsNotifierTest`
Expected: PASS (3 tests).

- [ ] **Step 6: Commit**

```bash
./vendor/bin/pint --dirty
git add app/Services/Technician/Notify/TeamsNotifier.php app/Support/TechnicianConfig.php tests/Feature/Technician/Notify/TeamsNotifierTest.php
git commit -m "feat(technician): TeamsNotifier — post a card to the configured webhook

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: `OperatorNotifier` — Teams + email fallback (the notify seam)

**Files:**
- Create: `app/Services/Technician/Notify/OperatorNotifier.php`
- Modify: `app/Support/TechnicianConfig.php` (add `notifyEmail()`)
- Test: `tests/Feature/Technician/Notify/OperatorNotifierTest.php`

**Interfaces:**
- Consumes: `TeamsNotifier::post(string,string): bool`; `App\Services\EmailService::sendNew(string $to, string $subject, string $bodyText, ?string $toName = null, ?array $cc = null, ?int $userId = null): \App\Models\Email`; `TechnicianConfig::notifyEmail(): ?string`.
- Produces:
  - `TechnicianConfig::notifyEmail(): ?string` — Setting `technician_notify_email`, null/empty → null.
  - `App\Services\Technician\Notify\OperatorNotifier::__construct(private TeamsNotifier $teams, private EmailService $email)`.
  - `OperatorNotifier::notify(string $subject, string $body): void` — calls `$this->teams->post($subject, $body)` (fail-soft) AND, if `notifyEmail()` is set, `$this->email->sendNew($notifyEmail, $subject, $body)` inside try/catch. Both independent; neither failure stops the other.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Technician\Notify;

use App\Models\Setting;
use App\Services\EmailService;
use App\Services\Technician\Notify\OperatorNotifier;
use App\Services\Technician\Notify\TeamsNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class OperatorNotifierTest extends TestCase
{
    use RefreshDatabase;

    public function test_delivers_to_teams_and_email_when_both_configured(): void
    {
        Setting::setValue('technician_notify_email', 'ops@example.com');
        $this->mock(TeamsNotifier::class, fn (MockInterface $m) => $m->shouldReceive('post')->once()->andReturnTrue());
        $this->mock(EmailService::class, fn (MockInterface $m) => $m->shouldReceive('sendNew')
            ->once()->with('ops@example.com', 'S', 'B', \Mockery::any(), \Mockery::any(), \Mockery::any())->andReturnNull());

        app(OperatorNotifier::class)->notify('S', 'B');
    }

    public function test_email_skipped_when_no_notify_email_but_teams_still_fires(): void
    {
        $this->mock(TeamsNotifier::class, fn (MockInterface $m) => $m->shouldReceive('post')->once()->andReturnTrue());
        $this->mock(EmailService::class, fn (MockInterface $m) => $m->shouldReceive('sendNew')->never());

        app(OperatorNotifier::class)->notify('S', 'B');
    }

    public function test_an_email_throw_does_not_stop_or_crash(): void
    {
        Setting::setValue('technician_notify_email', 'ops@example.com');
        $this->mock(TeamsNotifier::class, fn (MockInterface $m) => $m->shouldReceive('post')->once()->andReturnFalse());
        $this->mock(EmailService::class, fn (MockInterface $m) => $m->shouldReceive('sendNew')->once()->andThrow(new \RuntimeException('graph down')));

        app(OperatorNotifier::class)->notify('S', 'B'); // must not throw
        $this->assertTrue(true);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=OperatorNotifierTest`
Expected: FAIL — class + `notifyEmail()` not found.

- [ ] **Step 3: Add `notifyEmail()` to `TechnicianConfig`**

```php
    public static function notifyEmail(): ?string
    {
        $value = Setting::getValue('technician_notify_email');

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
```

- [ ] **Step 4: Write the notifier**

Create `app/Services/Technician/Notify/OperatorNotifier.php`:

```php
<?php

namespace App\Services\Technician\Notify;

use App\Services\EmailService;
use App\Support\TechnicianConfig;
use Illuminate\Support\Facades\Log;

/**
 * The single seam every Technician notification flows through. Delivers to Teams
 * (if a webhook is configured) AND to the operator's email (if set) — independently
 * and fail-soft, so a Teams outage never loses the notification and an email outage
 * never stops Teams. Email is the guaranteed always-on fallback.
 */
class OperatorNotifier
{
    public function __construct(
        private readonly TeamsNotifier $teams,
        private readonly EmailService $email,
    ) {}

    public function notify(string $subject, string $body): void
    {
        $this->teams->post($subject, $body); // fail-soft internally

        $to = TechnicianConfig::notifyEmail();
        if ($to !== null) {
            try {
                $this->email->sendNew($to, $subject, $body);
            } catch (\Throwable $e) {
                Log::warning('[Technician] Operator notify email failed', ['error' => $e->getMessage()]);
            }
        }
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=OperatorNotifierTest`
Expected: PASS (3 tests).

- [ ] **Step 6: Commit**

```bash
./vendor/bin/pint --dirty
git add app/Services/Technician/Notify/OperatorNotifier.php app/Support/TechnicianConfig.php tests/Feature/Technician/Notify/OperatorNotifierTest.php
git commit -m "feat(technician): OperatorNotifier — Teams + email fallback notify seam

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: `DigestBuilder` — summarize what's awaiting + what the AI did

**Files:**
- Create: `app/Services/Technician/Notify/DigestBuilder.php`
- Test: `tests/Feature/Technician/Notify/DigestBuilderTest.php`

**Interfaces:**
- Consumes: `App\Services\Technician\Cockpit\CockpitQuery::{pendingDrafts(), needsAttention()}`; `App\Models\TechnicianActionLog`; `App\Enums\TechnicianRunState`.
- Produces:
  - `App\Services\Technician\Notify\TechnicianDigest` (readonly DTO): `{string $subject, string $body, bool $isEmpty}`.
  - `App\Services\Technician\Notify\DigestBuilder::__construct(private CockpitQuery $cockpit)`.
  - `DigestBuilder::build(): TechnicianDigest` — pending = `$cockpit->pendingDrafts()` (count + oldest 5 as "client — subject (age)"); needsYou = `$cockpit->needsAttention()->count()`; done = `TechnicianActionLog::where('result_status','executed')->where('created_at','>=',now()->subDay())->count()`. `isEmpty` when pending + needsYou + done are all 0. Body is plain text.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Technician\Notify;

use App\Enums\TechnicianRunState;
use App\Models\Client;
use App\Models\Setting;
use App\Models\TechnicianActionLog;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Services\Technician\Notify\DigestBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DigestBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_when_nothing_pending_or_done(): void
    {
        $digest = app(DigestBuilder::class)->build();
        $this->assertTrue($digest->isEmpty);
    }

    public function test_summarizes_pending_and_actions_taken(): void
    {
        $client = Client::factory()->create(['name' => 'Acme']);
        $ticket = Ticket::factory()->create(['client_id' => $client->id, 'subject' => 'VPN down']);
        TechnicianRun::create([
            'ticket_id' => $ticket->id, 'client_id' => $client->id, 'action_type' => 'send_reply',
            'content_hash' => str_repeat('a', 64), 'state' => TechnicianRunState::AwaitingApproval, 'proposed_content' => 'd',
        ]);
        TechnicianActionLog::create([
            'actor_label' => 'ai-technician', 'action_type' => 'send_ack', 'tier' => 'auto',
            'result_status' => 'executed', 'content_hash' => str_repeat('b', 64), 'summary' => 'ack', 'correlation_id' => 'x',
        ]);

        $digest = app(DigestBuilder::class)->build();

        $this->assertFalse($digest->isEmpty);
        $this->assertStringContainsString('1', $digest->body);        // 1 awaiting
        $this->assertStringContainsString('VPN down', $digest->body); // the pending item
        $this->assertStringContainsString('Acme', $digest->body);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=DigestBuilderTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the DTO + builder**

Create `app/Services/Technician/Notify/DigestBuilder.php`:

```php
<?php

namespace App\Services\Technician\Notify;

use App\Models\TechnicianActionLog;
use App\Services\Technician\Cockpit\CockpitQuery;

final class TechnicianDigest
{
    public function __construct(
        public readonly string $subject,
        public readonly string $body,
        public readonly bool $isEmpty,
    ) {}
}

/**
 * Builds the operator's daily digest from the tested 1A/1B read models: pending
 * approvals (oldest-first), how many tickets need a human, and what the AI executed
 * in the last 24h. Pure reads — no side effects. (Emergencies are Phase 2.)
 */
class DigestBuilder
{
    public function __construct(private readonly CockpitQuery $cockpit) {}

    public function build(): TechnicianDigest
    {
        $pending = $this->cockpit->pendingDrafts();
        $needsYou = $this->cockpit->needsAttention()->count();
        $done = TechnicianActionLog::where('result_status', 'executed')
            ->where('created_at', '>=', now()->subDay())
            ->count();

        $isEmpty = $pending->isEmpty() && $needsYou === 0 && $done === 0;

        $lines = [
            "AI Technician — daily summary",
            "",
            "Awaiting your approval: {$pending->count()}",
            "Need a human (couldn't draft): {$needsYou}",
            "Handled autonomously (last 24h): {$done}",
        ];

        if ($pending->isNotEmpty()) {
            $lines[] = "";
            $lines[] = "Oldest awaiting:";
            foreach ($pending->take(5) as $run) {
                $client = $run->ticket?->client?->name ?? 'Unknown client';
                $subject = $run->ticket?->subject ?? "Ticket #{$run->ticket_id}";
                $age = optional($run->created_at)->diffForHumans() ?? '';
                $lines[] = "• {$client} — {$subject} ({$age})";
            }
        }

        return new TechnicianDigest('AI Technician — daily summary', implode("\n", $lines), $isEmpty);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=DigestBuilderTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint --dirty
git add app/Services/Technician/Notify/DigestBuilder.php tests/Feature/Technician/Notify/DigestBuilderTest.php
git commit -m "feat(technician): DigestBuilder — daily summary from the cockpit read models

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 4: `technician:digest` command + operator-local schedule

**Files:**
- Create: `app/Console/Commands/TechnicianDigest.php`
- Modify: `app/Support/TechnicianConfig.php` (add `digestEnabled()`, `digestTimeLocal()`, `lastDigestAt()`, `recordDigestSent()`)
- Modify: `routes/console.php` (schedule it)
- Test: `tests/Feature/Technician/Notify/TechnicianDigestCommandTest.php`

**Interfaces:**
- Consumes: `DigestBuilder::build(): TechnicianDigest`; `OperatorNotifier::notify(string,string): void`; `TechnicianConfig::{enabled(), digestEnabled(), recordDigestSent()}`; `App\Support\AppTimezone::get()`.
- Produces:
  - `TechnicianConfig::digestEnabled(): bool` — Setting `technician_digest_enabled`, default **true**.
  - `TechnicianConfig::digestTimeLocal(): string` — Setting `technician_digest_time`, default `'08:00'`.
  - `TechnicianConfig::lastDigestAt(): ?\Illuminate\Support\Carbon` — parse Setting `technician_last_digest_at` (ISO), else null. `recordDigestSent(): void` — `Setting::setValue('technician_last_digest_at', now()->toIso8601String())`.
  - `App\Console\Commands\TechnicianDigest` (`signature = 'technician:digest'`): if `! enabled() || ! digestEnabled()` → return `self::SUCCESS`; else build, `OperatorNotifier::notify($digest->subject, $digest->body)`, `recordDigestSent()`. (Sends even when "empty" — its arrival is the operator's all-quiet heartbeat.)

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Technician\Notify;

use App\Models\Setting;
use App\Services\Technician\Notify\OperatorNotifier;
use App\Support\TechnicianConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class TechnicianDigestCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_disabled_technician_sends_nothing(): void
    {
        $this->mock(OperatorNotifier::class, fn (MockInterface $m) => $m->shouldReceive('notify')->never());
        $this->artisan('technician:digest')->assertSuccessful();
    }

    public function test_enabled_builds_notifies_and_records(): void
    {
        Setting::setValue('technician_enabled', '1');
        $this->mock(OperatorNotifier::class, fn (MockInterface $m) => $m->shouldReceive('notify')->once());

        $this->assertNull(TechnicianConfig::lastDigestAt());
        $this->artisan('technician:digest')->assertSuccessful();
        $this->assertNotNull(TechnicianConfig::lastDigestAt());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=TechnicianDigestCommandTest`
Expected: FAIL — command not registered.

- [ ] **Step 3: Add the config methods**

In `app/Support/TechnicianConfig.php` (add a `use Illuminate\Support\Carbon;` import):

```php
    public static function digestEnabled(): bool
    {
        $value = Setting::getValue('technician_digest_enabled');

        return $value === null || (bool) $value; // default true
    }

    public static function digestTimeLocal(): string
    {
        $value = Setting::getValue('technician_digest_time');

        return is_string($value) && preg_match('/^\d{2}:\d{2}$/', $value) ? $value : '08:00';
    }

    public static function lastDigestAt(): ?Carbon
    {
        $value = Setting::getValue('technician_last_digest_at');

        return is_string($value) && $value !== '' ? Carbon::parse($value) : null;
    }

    public static function recordDigestSent(): void
    {
        Setting::setValue('technician_last_digest_at', now()->toIso8601String());
    }
```

- [ ] **Step 4: Write the command**

Create `app/Console/Commands/TechnicianDigest.php`:

```php
<?php

namespace App\Console\Commands;

use App\Services\Technician\Notify\DigestBuilder;
use App\Services\Technician\Notify\OperatorNotifier;
use App\Support\TechnicianConfig;
use Illuminate\Console\Command;

class TechnicianDigest extends Command
{
    protected $signature = 'technician:digest';

    protected $description = 'Send the AI Technician daily digest to the operator (Teams + email).';

    public function handle(DigestBuilder $builder, OperatorNotifier $notifier): int
    {
        if (! TechnicianConfig::enabled() || ! TechnicianConfig::digestEnabled()) {
            return self::SUCCESS;
        }

        $digest = $builder->build();
        $notifier->notify($digest->subject, $digest->body);
        TechnicianConfig::recordDigestSent();

        return self::SUCCESS;
    }
}
```

- [ ] **Step 5: Schedule it (operator-local, once/day, enabled-gated)**

In `routes/console.php`, add (mirroring the `triage:review-open` self-throttle idiom; the prod cron already runs `schedule:run`):

```php
use App\Support\AppTimezone;
use App\Support\TechnicianConfig;

Schedule::command('technician:digest')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->runInBackground()
    ->when(function () {
        if (! TechnicianConfig::enabled() || ! TechnicianConfig::digestEnabled()) {
            return false;
        }
        // Fire only at the operator-local digest minute, and only once per local day.
        $localNow = now()->setTimezone(AppTimezone::get());
        if ($localNow->format('H:i') !== TechnicianConfig::digestTimeLocal()) {
            return false;
        }
        $last = TechnicianConfig::lastDigestAt();

        return $last === null || $last->setTimezone(AppTimezone::get())->toDateString() !== $localNow->toDateString();
    });
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=TechnicianDigestCommandTest`
Expected: PASS (2 tests).

- [ ] **Step 7: Commit**

```bash
./vendor/bin/pint --dirty
git add app/Console/Commands/TechnicianDigest.php app/Support/TechnicianConfig.php routes/console.php tests/Feature/Technician/Notify/TechnicianDigestCommandTest.php
git commit -m "feat(technician): daily digest command + operator-local schedule

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 5: Worker-liveness ping (idle-safe heartbeat)

A heartbeat written only when a real ticket is processed goes stale during quiet periods → a false "worker down". Instead, dispatch a tiny job onto the `technician` queue on a schedule; the worker processing it proves it's *draining its queue* even when idle, and writes the heartbeat.

**Files:**
- Create: `app/Jobs/TechnicianPing.php`
- Modify: `app/Support/TechnicianConfig.php` (`workerLastSeen()`, `recordWorkerSeen()`)
- Modify: `app/Jobs/RunTechnicianLoop.php` (also record the heartbeat at the top of `handle()`)
- Modify: `routes/console.php` (dispatch the ping every 5 min, enabled-gated)
- Test: `tests/Feature/Technician/Notify/TechnicianHeartbeatCommandTest.php` (a ping-records-heartbeat test; the command tests come in Task 6 — create the file here)

**Interfaces:**
- Consumes: `TechnicianConfig::{workerLastSeen(), recordWorkerSeen()}`; `Illuminate\Contracts\Queue\ShouldQueue`.
- Produces:
  - `TechnicianConfig::recordWorkerSeen(): void` — `Setting::setValue('technician_worker_last_seen', now()->toIso8601String())`. `workerLastSeen(): ?Carbon` — parse it, else null.
  - `App\Jobs\TechnicianPing implements ShouldQueue` (uses `Queueable`), `__construct()` sets `$this->onQueue('technician')`; `handle(): void` → `TechnicianConfig::recordWorkerSeen()`.
  - `RunTechnicianLoop::handle()` begins with `TechnicianConfig::recordWorkerSeen();` (real work also refreshes the heartbeat).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Technician\Notify;

use App\Jobs\TechnicianPing;
use App\Support\TechnicianConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TechnicianHeartbeatCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_ping_records_the_worker_heartbeat(): void
    {
        $this->assertNull(TechnicianConfig::workerLastSeen());
        (new TechnicianPing)->handle();
        $this->assertNotNull(TechnicianConfig::workerLastSeen());
        $this->assertTrue(TechnicianConfig::workerLastSeen()->greaterThan(now()->subMinute()));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=TechnicianHeartbeatCommandTest`
Expected: FAIL — `TechnicianPing` not found.

- [ ] **Step 3: Add the config methods**

```php
    public static function workerLastSeen(): ?Carbon
    {
        $value = Setting::getValue('technician_worker_last_seen');

        return is_string($value) && $value !== '' ? Carbon::parse($value) : null;
    }

    public static function recordWorkerSeen(): void
    {
        Setting::setValue('technician_worker_last_seen', now()->toIso8601String());
    }
```

- [ ] **Step 4: Write the ping job + add the heartbeat to the Loop**

Create `app/Jobs/TechnicianPing.php`:

```php
<?php

namespace App\Jobs;

use App\Support\TechnicianConfig;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * A tiny liveness ping dispatched onto the dedicated 'technician' queue on a
 * schedule. The worker processing it proves it is draining its queue even when no
 * tickets are flowing; it records the heartbeat the dead-man's-switch watches.
 */
class TechnicianPing implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('technician');
    }

    public function handle(): void
    {
        TechnicianConfig::recordWorkerSeen();
    }
}
```

In `app/Jobs/RunTechnicianLoop.php`, at the very top of `handle()` (before the ticket load), add:

```php
        TechnicianConfig::recordWorkerSeen();
```

(`TechnicianConfig` is already imported in that file.)

- [ ] **Step 5: Schedule the ping**

In `routes/console.php`:

```php
Schedule::job(new \App\Jobs\TechnicianPing)
    ->everyFiveMinutes()
    ->when(fn () => \App\Support\TechnicianConfig::enabled());
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=TechnicianHeartbeatCommandTest`
Expected: PASS (1 test).

- [ ] **Step 7: Commit**

```bash
./vendor/bin/pint --dirty
git add app/Jobs/TechnicianPing.php app/Jobs/RunTechnicianLoop.php app/Support/TechnicianConfig.php routes/console.php tests/Feature/Technician/Notify/TechnicianHeartbeatCommandTest.php
git commit -m "feat(technician): worker-liveness ping + heartbeat write (idle-safe)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 6: `technician:heartbeat` command — the dead-man's-switch

The scariest failure is *silent*: the worker dies or the digest stops and the operator doesn't know to look. This command runs on the **web/cron scheduler** (not the Technician worker, so it fires even when that worker is down), checks the worker heartbeat + the digest freshness, and alerts the operator — self-throttled so it doesn't spam.

**Files:**
- Create: `app/Console/Commands/TechnicianHeartbeat.php`
- Modify: `app/Support/TechnicianConfig.php` (`heartbeatIntervalMinutes()`, `lastHeartbeatAlertAt()`, `recordHeartbeatAlert()`)
- Modify: `routes/console.php` (schedule it every minute, enabled-gated)
- Test: `tests/Feature/Technician/Notify/TechnicianHeartbeatCommandTest.php` (append)

**Interfaces:**
- Consumes: `TechnicianConfig::{enabled(), workerLastSeen(), heartbeatIntervalMinutes(), lastHeartbeatAlertAt(), recordHeartbeatAlert()}`; `OperatorNotifier::notify(string,string): void`.
- Produces:
  - `TechnicianConfig::heartbeatIntervalMinutes(): int` — Setting `technician_heartbeat_interval`, default `15`.
  - `TechnicianConfig::lastHeartbeatAlertAt(): ?Carbon` + `recordHeartbeatAlert(): void` (Setting `technician_last_heartbeat_alert_at`).
  - `App\Console\Commands\TechnicianHeartbeat` (`signature = 'technician:heartbeat'`): if `! enabled()` → SUCCESS. Compute `$stale` = `workerLastSeen() === null` OR `workerLastSeen()->lt(now()->subMinutes(heartbeatIntervalMinutes()))`. If `$stale`: throttle — if `lastHeartbeatAlertAt()` is within the last `heartbeatIntervalMinutes()`, skip; else `OperatorNotifier::notify('AI Technician — worker not responding', '...')` + `recordHeartbeatAlert()`. Returns SUCCESS.

- [ ] **Step 1: Write the failing test (append)**

```php
    public function test_heartbeat_alerts_when_the_worker_is_stale_and_throttles(): void
    {
        \App\Models\Setting::setValue('technician_enabled', '1');
        \App\Models\Setting::setValue('technician_worker_last_seen', now()->subHour()->toIso8601String()); // stale

        $this->mock(\App\Services\Technician\Notify\OperatorNotifier::class,
            fn (\Mockery\MockInterface $m) => $m->shouldReceive('notify')->once()); // alerts ONCE despite two runs

        $this->artisan('technician:heartbeat')->assertSuccessful();
        $this->artisan('technician:heartbeat')->assertSuccessful(); // throttled — no second alert
    }

    public function test_heartbeat_silent_when_worker_is_fresh(): void
    {
        \App\Models\Setting::setValue('technician_enabled', '1');
        \App\Models\Setting::setValue('technician_worker_last_seen', now()->toIso8601String()); // fresh

        $this->mock(\App\Services\Technician\Notify\OperatorNotifier::class,
            fn (\Mockery\MockInterface $m) => $m->shouldReceive('notify')->never());

        $this->artisan('technician:heartbeat')->assertSuccessful();
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=TechnicianHeartbeatCommandTest`
Expected: FAIL — command not registered.

- [ ] **Step 3: Add the config methods**

```php
    public static function heartbeatIntervalMinutes(): int
    {
        $value = Setting::getValue('technician_heartbeat_interval');

        return is_numeric($value) ? max(1, (int) $value) : 15;
    }

    public static function lastHeartbeatAlertAt(): ?Carbon
    {
        $value = Setting::getValue('technician_last_heartbeat_alert_at');

        return is_string($value) && $value !== '' ? Carbon::parse($value) : null;
    }

    public static function recordHeartbeatAlert(): void
    {
        Setting::setValue('technician_last_heartbeat_alert_at', now()->toIso8601String());
    }
```

- [ ] **Step 4: Write the command**

Create `app/Console/Commands/TechnicianHeartbeat.php`:

```php
<?php

namespace App\Console\Commands;

use App\Services\Technician\Notify\OperatorNotifier;
use App\Support\TechnicianConfig;
use Illuminate\Console\Command;

class TechnicianHeartbeat extends Command
{
    protected $signature = 'technician:heartbeat';

    protected $description = "Dead-man's-switch: alert the operator if the AI Technician worker is not responding.";

    public function handle(OperatorNotifier $notifier): int
    {
        if (! TechnicianConfig::enabled()) {
            return self::SUCCESS;
        }

        $seen = TechnicianConfig::workerLastSeen();
        $interval = TechnicianConfig::heartbeatIntervalMinutes();
        $stale = $seen === null || $seen->lt(now()->subMinutes($interval));

        if (! $stale) {
            return self::SUCCESS;
        }

        // Throttle: at most one alert per interval, so a sustained outage doesn't spam.
        $lastAlert = TechnicianConfig::lastHeartbeatAlertAt();
        if ($lastAlert !== null && $lastAlert->gt(now()->subMinutes($interval))) {
            return self::SUCCESS;
        }

        $notifier->notify(
            'AI Technician — worker not responding',
            "The AI Technician queue worker hasn't checked in for over {$interval} minutes. "
            ."Inbound tickets may not be getting acknowledged or drafted. Check the soundit-psa-technician-queue worker.",
        );
        TechnicianConfig::recordHeartbeatAlert();

        return self::SUCCESS;
    }
}
```

- [ ] **Step 5: Schedule it**

In `routes/console.php`:

```php
Schedule::command('technician:heartbeat')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground()
    ->when(fn () => \App\Support\TechnicianConfig::enabled());
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=TechnicianHeartbeatCommandTest`
Expected: PASS (3 tests).

- [ ] **Step 7: Commit**

```bash
./vendor/bin/pint --dirty
git add app/Console/Commands/TechnicianHeartbeat.php app/Support/TechnicianConfig.php routes/console.php tests/Feature/Technician/Notify/TechnicianHeartbeatCommandTest.php
git commit -m "feat(technician): dead-man's-switch heartbeat command (worker-down alert)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 7: Settings UI — notify configuration

**Files:**
- Modify: `app/Http/Controllers/Web/IntegrationsController.php` (extend `updateTechnician` + the `index` view vars)
- Modify: `resources/views/settings/integrations.blade.php` (extend the "AI Technician" card)
- Test: `tests/Feature/Technician/Notify/TechnicianDigestCommandTest.php` (append a settings-save test) — OR a small controller test; keep it where the digest config lives.

**Interfaces:**
- Consumes: `Setting::setValue`; the `settings.integrations.technician.update` route (exists from 1A).
- Produces: `updateTechnician` also persists `technician_teams_webhook_url`, `technician_notify_email`, `technician_digest_enabled`, `technician_digest_time`, `technician_heartbeat_interval`; the card renders inputs for them.

- [ ] **Step 1: Write the failing test (append)**

```php
    public function test_settings_save_persists_notify_config(): void
    {
        $user = \App\Models\User::factory()->create();

        $this->actingAs($user)->post(route('settings.integrations.technician.update'), [
            'technician_enabled' => '1',
            'technician_teams_webhook_url' => 'https://x.webhook.office.com/h',
            'technician_notify_email' => 'ops@example.com',
            'technician_digest_time' => '07:30',
            'technician_heartbeat_interval' => '20',
        ])->assertRedirect();

        $this->assertSame('https://x.webhook.office.com/h', \App\Support\TechnicianConfig::teamsWebhookUrl());
        $this->assertSame('ops@example.com', \App\Support\TechnicianConfig::notifyEmail());
        $this->assertSame('07:30', \App\Support\TechnicianConfig::digestTimeLocal());
        $this->assertSame(20, \App\Support\TechnicianConfig::heartbeatIntervalMinutes());
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter="TechnicianDigestCommandTest::test_settings_save_persists_notify_config"`
Expected: FAIL — `updateTechnician` doesn't persist the new keys.

- [ ] **Step 3: Extend `updateTechnician`**

In `app/Http/Controllers/Web/IntegrationsController.php::updateTechnician`, after the existing `Setting::setValue` calls, add:

```php
        Setting::setValue('technician_teams_webhook_url', trim((string) $request->input('technician_teams_webhook_url', '')));
        Setting::setValue('technician_notify_email', trim((string) $request->input('technician_notify_email', '')));
        Setting::setValue('technician_digest_enabled', $request->has('technician_digest_enabled') ? '1' : '0');
        $time = (string) $request->input('technician_digest_time', '08:00');
        Setting::setValue('technician_digest_time', preg_match('/^\d{2}:\d{2}$/', $time) ? $time : '08:00');
        Setting::setValue('technician_heartbeat_interval', (string) max(1, (int) $request->input('technician_heartbeat_interval', 15)));
```

And in `IntegrationsController::index`, alongside the existing `$technicianEnabled`/`$technicianAutoAck`, add view vars (used by the card):

```php
        $technicianTeamsWebhook = \App\Support\TechnicianConfig::teamsWebhookUrl();
        $technicianNotifyEmail = \App\Support\TechnicianConfig::notifyEmail();
        $technicianDigestEnabled = \App\Support\TechnicianConfig::digestEnabled();
        $technicianDigestTime = \App\Support\TechnicianConfig::digestTimeLocal();
        $technicianHeartbeatInterval = \App\Support\TechnicianConfig::heartbeatIntervalMinutes();
```

(add these to the `compact(...)` passed to the view.)

- [ ] **Step 4: Extend the Blade card**

In `resources/views/settings/integrations.blade.php`, inside the AI Technician card's `<form>` (before the submit button), add the inputs:

```blade
<hr class="my-3">
<h6 class="text-muted text-uppercase small mb-2">Notify (Plan 1C)</h6>
<div class="mb-3">
    <label class="form-label small" for="technician_teams_webhook_url">Teams webhook URL</label>
    <input type="url" class="form-control" id="technician_teams_webhook_url" name="technician_teams_webhook_url"
           value="{{ $technicianTeamsWebhook }}" placeholder="https://…webhook.office.com/… (or a Power Automate Workflow URL)">
    <div class="form-text">Paste an incoming-webhook / Power Automate Workflow URL for the operator chat. Optional — email is the fallback.</div>
</div>
<div class="mb-3">
    <label class="form-label small" for="technician_notify_email">Notify email (fallback)</label>
    <input type="email" class="form-control" id="technician_notify_email" name="technician_notify_email" value="{{ $technicianNotifyEmail }}">
</div>
<div class="form-check form-switch mb-2">
    <input class="form-check-input" type="checkbox" id="technician_digest_enabled" name="technician_digest_enabled" {{ $technicianDigestEnabled ? 'checked' : '' }}>
    <label class="form-check-label" for="technician_digest_enabled">Send a daily digest</label>
</div>
<div class="row">
    <div class="col mb-3">
        <label class="form-label small" for="technician_digest_time">Digest time (local)</label>
        <input type="time" class="form-control" id="technician_digest_time" name="technician_digest_time" value="{{ $technicianDigestTime }}">
    </div>
    <div class="col mb-3">
        <label class="form-label small" for="technician_heartbeat_interval">Worker-down alert after (min)</label>
        <input type="number" min="1" class="form-control" id="technician_heartbeat_interval" name="technician_heartbeat_interval" value="{{ $technicianHeartbeatInterval }}">
    </div>
</div>
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=TechnicianDigestCommandTest`
Expected: PASS.

- [ ] **Step 6: Run the full Technician suite**

Run: `php artisan test --filter=Technician`
Expected: PASS (1A + 1B + 1C all green).

- [ ] **Step 7: Commit**

```bash
./vendor/bin/pint --dirty
git add app/Http/Controllers/Web/IntegrationsController.php resources/views/settings/integrations.blade.php tests/Feature/Technician/Notify/TechnicianDigestCommandTest.php
git commit -m "feat(technician): notify settings UI (webhook, email, digest, heartbeat)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Plan Self-Review

**Spec coverage (Phase 1 "safe core" §9 digest + §13 dead-man's-switch + §4.5 Teams notify):**
- *Teams one-way notify (digest + reports)* → Tasks 1–2 (`TeamsNotifier` webhook + `OperatorNotifier` with email fallback). The webhook realizes "PSA-native, on the PSA server, no external bot" given app-only Graph chat-posting is a Microsoft Protected API.
- *Operator-timezone daily digest; pending approvals oldest-first + actions taken; already-actioned excluded* → Tasks 3–4 (`DigestBuilder` reuses the tested `CockpitQuery`; the schedule fires at the operator-local minute, once/day).
- *Dead-man's-switch (no digest / worker down = something's wrong → alert)* → Tasks 5–6 (idle-safe liveness ping + the throttled heartbeat command that runs on the cron scheduler so it fires when the Technician worker is down).
- *Settings without a deploy* → Task 7 (the Integrations card).

**Deferred / not 1C (correctly):** emergency raising + escalation + storm grouping (Phase 2); the "approved-but-not-completed → honest interim" aging update (it can ride the same notify seam later); the in-Teams approval spike (July-20). The dedicated `technician` worker shipped in 1A.

**Forest check — does 1C make the operator show up + trust the silence?** Yes: the digest is the daily tap on the shoulder (so the cockpit gets opened), delivered at the operator's local time over Teams + guaranteed email; the dead-man's-switch turns the scariest failure — *silent* death of the worker or the digest — into a loud, throttled alert. Combined with 1B (the cockpit) this gives a usable, trustworthy away-coverage loop; **Phase 2** (the deterministic emergency backstop) remains the separate "safe to be wrong" net and is the last trip-critical piece.

**Placeholder scan:** none — every step has real code + commands. Implementer-confirm notes: the `Schedule::job(new TechnicianPing)` form + `AppTimezone::get()` usage are verified against the recon; confirm the `IntegrationsController::index` `compact(...)` includes the new vars and the card's existing `<form>` is the right insertion point.

**Type consistency:** the Setting keys (`technician_teams_webhook_url`, `technician_notify_email`, `technician_digest_enabled`, `technician_digest_time`, `technician_last_digest_at`, `technician_worker_last_seen`, `technician_heartbeat_interval`, `technician_last_heartbeat_alert_at`), the `OperatorNotifier::notify(subject, body)` signature, the `TechnicianDigest{subject,body,isEmpty}` DTO, and `TechnicianConfig`'s new methods are used identically across Tasks 1–7. The webhook lives behind `OperatorNotifier` so a future Graph/Bot Teams sender can replace `TeamsNotifier` without touching the commands.

