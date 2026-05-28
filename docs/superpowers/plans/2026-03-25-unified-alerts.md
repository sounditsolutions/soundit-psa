# Unified Alerts System Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace vendor-specific alert handling with a unified `alerts` table, alert dashboard, and manual ticket conversion — decoupling monitoring events from ticket creation.

**Architecture:** New `Alert` model with `AlertStatus`, `AlertSource`, `AlertSeverity` enums. `AlertService` handles all CRUD operations. Each vendor's webhook service writes to `Alert` instead of creating tickets directly. A new alerts index page and dashboard card provide unified visibility. Existing `ninja_alerts` data migrated and table dropped.

**Tech Stack:** Laravel 12, PHP 8.3, Blade + Bootstrap 5.3

**Spec:** `docs/superpowers/specs/2026-03-25-unified-alerts-design.md`

---

## Chunk 1: Foundation (Model, Enums, Migration, AlertService)

### Task 1: Create Enums

**Files:**
- Create: `app/Enums/AlertStatus.php`
- Create: `app/Enums/AlertSource.php`
- Create: `app/Enums/AlertSeverity.php`

- [ ] **Step 1: Create AlertStatus enum**

Follow the pattern from `app/Enums/TicketStatus.php` (backed string enum with `label()` and `badgeClass()` methods).

```php
<?php

namespace App\Enums;

enum AlertStatus: string
{
    case Active = 'active';
    case Acknowledged = 'acknowledged';
    case Ticketed = 'ticketed';
    case Resolved = 'resolved';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Acknowledged => 'Acknowledged',
            self::Ticketed => 'Ticketed',
            self::Resolved => 'Resolved',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Active => 'bg-danger',
            self::Acknowledged => 'bg-info',
            self::Ticketed => 'bg-primary',
            self::Resolved => 'bg-secondary',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Active, self::Acknowledged, self::Ticketed]);
    }
}
```

- [ ] **Step 2: Create AlertSource enum**

```php
<?php

namespace App\Enums;

enum AlertSource: string
{
    case Tactical = 'tactical';
    case Ninja = 'ninja';
    case Comet = 'comet';
    case Huntress = 'huntress';

    public function label(): string
    {
        return match ($this) {
            self::Tactical => 'Tactical RMM',
            self::Ninja => 'NinjaRMM',
            self::Comet => 'Comet Backup',
            self::Huntress => 'Huntress',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Tactical => 'bi-hdd-network',
            self::Ninja => 'bi-hdd-network',
            self::Comet => 'bi-cloud-arrow-up',
            self::Huntress => 'bi-shield-check',
        };
    }
}
```

- [ ] **Step 3: Create AlertSeverity enum**

```php
<?php

namespace App\Enums;

enum AlertSeverity: string
{
    case Critical = 'critical';
    case Error = 'error';
    case Warning = 'warning';
    case Info = 'info';

    public function label(): string
    {
        return match ($this) {
            self::Critical => 'Critical',
            self::Error => 'Error',
            self::Warning => 'Warning',
            self::Info => 'Info',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Critical => 'bg-danger',
            self::Error => 'bg-danger',
            self::Warning => 'bg-warning text-dark',
            self::Info => 'bg-info text-dark',
        };
    }

    public function toTicketPriority(): \App\Enums\TicketPriority
    {
        return match ($this) {
            self::Critical => \App\Enums\TicketPriority::P1,
            self::Error => \App\Enums\TicketPriority::P2,
            self::Warning => \App\Enums\TicketPriority::P3,
            self::Info => \App\Enums\TicketPriority::P4,
        };
    }

    /**
     * Map vendor-specific severity strings to the unified enum.
     */
    public static function fromVendor(AlertSource $source, ?string $vendorSeverity): self
    {
        $normalized = strtolower(trim($vendorSeverity ?? ''));

        return match ($source) {
            AlertSource::Tactical => match ($normalized) {
                'error' => self::Error,
                'warning' => self::Warning,
                'info', 'informational' => self::Info,
                default => self::Warning,
            },
            AlertSource::Ninja => match ($normalized) {
                'critical' => self::Critical,
                'major' => self::Error,
                'moderate' => self::Warning,
                'minor' => self::Info,
                default => self::Warning,
            },
            AlertSource::Comet => self::Error, // Comet failures are always errors
            AlertSource::Huntress => match ($normalized) {
                'critical' => self::Critical,
                'high' => self::Error,
                'low' => self::Warning,
                default => self::Error,
            },
        };
    }
}
```

- [ ] **Step 4: Commit**

```bash
git add app/Enums/AlertStatus.php app/Enums/AlertSource.php app/Enums/AlertSeverity.php
git commit -m "Add AlertStatus, AlertSource, AlertSeverity enums"
```

---

### Task 2: Migration — Create alerts table and migrate ninja_alerts data

**Files:**
- Create: `database/migrations/2026_03_25_000001_create_alerts_table.php`

- [ ] **Step 1: Create migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->nullable()->constrained('assets')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->string('source', 20);
            $table->string('source_alert_id');
            $table->string('severity', 20);
            $table->string('status', 20)->default('active');
            $table->string('title');
            $table->text('message')->nullable();
            $table->string('hostname')->nullable();
            $table->foreignId('ticket_id')->nullable()->constrained('tickets')->nullOnDelete();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedInteger('refired_count')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamp('fired_at')->nullable();
            $table->timestamps();

            $table->unique(['source', 'source_alert_id']);
            $table->index(['client_id', 'status']);
            $table->index(['asset_id', 'status']);
            $table->index(['status', 'severity']);
            $table->index('ticket_id');
        });

        // Migrate ninja_alerts data
        if (Schema::hasTable('ninja_alerts')) {
            $ninjaAlerts = DB::table('ninja_alerts')->get();
            foreach ($ninjaAlerts as $na) {
                $clientId = null;
                if ($na->asset_id) {
                    $clientId = DB::table('assets')->where('id', $na->asset_id)->value('client_id');
                }

                // Map Ninja severity to unified
                $severity = match (strtolower($na->severity ?? '')) {
                    'critical' => 'critical',
                    'major' => 'error',
                    'moderate' => 'warning',
                    'minor' => 'info',
                    default => 'warning',
                };

                // Get hostname from asset
                $hostname = null;
                if ($na->asset_id) {
                    $hostname = DB::table('assets')->where('id', $na->asset_id)->value('hostname');
                }

                DB::table('alerts')->insert([
                    'asset_id' => $na->asset_id,
                    'client_id' => $clientId,
                    'source' => 'ninja',
                    'source_alert_id' => $na->ninja_alert_uid,
                    'severity' => $severity,
                    'status' => $na->status,
                    'title' => $na->condition_name ?? 'NinjaRMM Alert',
                    'message' => $na->message,
                    'hostname' => $hostname,
                    'ticket_id' => $na->ticket_id,
                    'resolved_at' => $na->resolved_at,
                    'metadata' => json_encode(['ninja_device_id' => $na->ninja_device_id]),
                    'fired_at' => $na->fired_at,
                    'created_at' => $na->created_at,
                    'updated_at' => $na->updated_at,
                ]);
            }

            Schema::drop('ninja_alerts');
        }
    }

    public function down(): void
    {
        // Recreate ninja_alerts if rolling back (without data)
        Schema::create('ninja_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->nullable()->constrained('assets')->nullOnDelete();
            $table->unsignedInteger('ninja_device_id')->index();
            $table->string('ninja_alert_uid')->unique();
            $table->string('severity', 20)->nullable();
            $table->string('condition_name')->nullable();
            $table->text('message')->nullable();
            $table->string('status', 20)->default('active');
            $table->foreignId('ticket_id')->nullable()->constrained('tickets')->nullOnDelete();
            $table->timestamp('fired_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['asset_id', 'status']);
            $table->index(['ticket_id']);
        });

        Schema::dropIfExists('alerts');
    }
};
```

- [ ] **Step 2: Run migration**

```bash
php artisan migrate
```

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_03_25_000001_create_alerts_table.php
git commit -m "Create alerts table and migrate ninja_alerts data"
```

---

### Task 3: Alert Model

**Files:**
- Create: `app/Models/Alert.php`

- [ ] **Step 1: Create Alert model**

Follow the pattern from `app/Models/NinjaAlert.php` but with unified fields.

```php
<?php

namespace App\Models;

use App\Enums\AlertSeverity;
use App\Enums\AlertSource;
use App\Enums\AlertStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Alert extends Model
{
    protected $fillable = [
        'asset_id',
        'client_id',
        'source',
        'source_alert_id',
        'severity',
        'status',
        'title',
        'message',
        'hostname',
        'ticket_id',
        'acknowledged_by',
        'acknowledged_at',
        'resolved_at',
        'refired_count',
        'metadata',
        'fired_at',
    ];

    protected function casts(): array
    {
        return [
            'source' => AlertSource::class,
            'severity' => AlertSeverity::class,
            'status' => AlertStatus::class,
            'metadata' => 'array',
            'fired_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function acknowledgedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    public function scopeOpen($query)
    {
        return $query->whereIn('status', [
            AlertStatus::Active,
            AlertStatus::Acknowledged,
            AlertStatus::Ticketed,
        ]);
    }

    public function scopeActive($query)
    {
        return $query->where('status', AlertStatus::Active);
    }
}
```

- [ ] **Step 2: Update Asset model relationships**

In `app/Models/Asset.php`, replace the `ninjaAlerts()` and `activeNinjaAlerts()` relationships:

```php
public function alerts(): HasMany
{
    return $this->hasMany(Alert::class);
}

public function activeAlerts(): HasMany
{
    return $this->hasMany(Alert::class)->whereIn('status', [
        \App\Enums\AlertStatus::Active,
        \App\Enums\AlertStatus::Acknowledged,
        \App\Enums\AlertStatus::Ticketed,
    ]);
}
```

Remove `ninjaAlerts()` and `activeNinjaAlerts()`. Add `use App\Models\Alert;` if not present. Update any `use App\Models\NinjaAlert;` imports.

- [ ] **Step 3: Add TicketSource::Alert**

In `app/Enums/TicketSource.php`, add:

```php
case Alert = 'alert';
```

And in the `label()` method:

```php
self::Alert => 'Alert',
```

- [ ] **Step 4: Commit**

```bash
git add app/Models/Alert.php app/Models/Asset.php app/Enums/TicketSource.php
git commit -m "Add Alert model with relationships and TicketSource::Alert"
```

---

### Task 4: AlertService

**Files:**
- Create: `app/Services/AlertService.php`

- [ ] **Step 1: Create AlertService**

This is the core service that all vendor webhook handlers will call.

```php
<?php

namespace App\Services;

use App\Enums\AlertSeverity;
use App\Enums\AlertSource;
use App\Enums\AlertStatus;
use App\Enums\NoteType;
use App\Enums\TicketSource;
use App\Enums\TicketType;
use App\Models\Alert;
use App\Models\Ticket;
use App\Models\User;
use App\Support\TriageConfig;
use Illuminate\Support\Facades\Log;

class AlertService
{
    public function __construct(
        private readonly TicketService $ticketService,
    ) {}

    /**
     * Create or update an alert (upsert with dedup).
     */
    public function upsert(
        AlertSource $source,
        string $sourceAlertId,
        array $data,
    ): Alert {
        $existing = Alert::where('source', $source)
            ->where('source_alert_id', $sourceAlertId)
            ->whereIn('status', [AlertStatus::Active, AlertStatus::Acknowledged, AlertStatus::Ticketed])
            ->first();

        if ($existing) {
            // Re-fired — update existing
            $existing->update([
                'message' => $data['message'] ?? $existing->message,
                'fired_at' => $data['fired_at'] ?? $existing->fired_at,
                'refired_count' => $existing->refired_count + 1,
                'metadata' => array_merge($existing->metadata ?? [], $data['metadata'] ?? []),
            ]);

            Log::debug("[Alert] Re-fired {$source->value} alert {$sourceAlertId}", [
                'alert_id' => $existing->id,
                'refired_count' => $existing->refired_count,
            ]);

            return $existing;
        }

        $alert = Alert::create([
            'asset_id' => $data['asset_id'] ?? null,
            'client_id' => $data['client_id'] ?? null,
            'source' => $source,
            'source_alert_id' => $sourceAlertId,
            'severity' => $data['severity'],
            'status' => AlertStatus::Active,
            'title' => $data['title'],
            'message' => $data['message'] ?? null,
            'hostname' => $data['hostname'] ?? null,
            'metadata' => $data['metadata'] ?? null,
            'fired_at' => $data['fired_at'] ?? now(),
        ]);

        Log::info("[Alert] Created {$source->value} alert", [
            'alert_id' => $alert->id,
            'severity' => $data['severity']->value ?? $data['severity'],
            'title' => $data['title'],
            'hostname' => $data['hostname'] ?? null,
        ]);

        return $alert;
    }

    /**
     * Acknowledge an alert.
     */
    public function acknowledge(Alert $alert, User $user): void
    {
        if ($alert->status === AlertStatus::Resolved) {
            return;
        }

        $alert->update([
            'status' => AlertStatus::Acknowledged,
            'acknowledged_by' => $user->id,
            'acknowledged_at' => now(),
        ]);
    }

    /**
     * Convert an alert to a ticket.
     */
    public function createTicket(Alert $alert, ?int $userId = null): ?Ticket
    {
        if ($alert->ticket_id) {
            return $alert->ticket;
        }

        $priority = $alert->severity->toTicketPriority();
        $subject = "[{$alert->source->label()}] {$alert->severity->label()} — {$alert->title} on {$alert->hostname}";

        // Resolve contact from asset's primary user
        $contactId = null;
        if ($alert->asset) {
            $primaryUser = $alert->asset->primaryUser();
            if ($primaryUser) {
                $contactId = $primaryUser->id;
            }
        }

        // Build description
        $descLines = ["**{$alert->source->label()} Alert**"];
        $descLines[] = "- Device: {$alert->hostname}";
        $descLines[] = "- Severity: {$alert->severity->label()}";
        $descLines[] = "- Alert: {$alert->title}";
        if ($alert->fired_at) {
            $descLines[] = "- Fired: {$alert->fired_at->toDateTimeString()}";
        }
        if ($alert->message) {
            $descLines[] = '';
            $descLines[] = '**Details:**';
            $descLines[] = '```';
            $descLines[] = substr($alert->message, 0, 3000);
            $descLines[] = '```';
        }

        $ticket = $this->ticketService->createTicket([
            'subject' => $subject,
            'description' => implode("\n", $descLines),
            'client_id' => $alert->client_id,
            'contact_id' => $contactId,
            'priority' => $priority->value,
            'type' => TicketType::Incident->value,
            'source' => TicketSource::Alert->value,
            'source_ref' => (string) $alert->id,
        ], $userId);

        if ($ticket && $alert->asset_id) {
            $ticket->assets()->syncWithoutDetaching([$alert->asset_id]);
        }

        $alert->update([
            'status' => AlertStatus::Ticketed,
            'ticket_id' => $ticket?->id,
        ]);

        Log::info("[Alert] Ticket created from alert", [
            'alert_id' => $alert->id,
            'ticket_id' => $ticket?->id,
        ]);

        return $ticket;
    }

    /**
     * Resolve an alert (from RMM or manual).
     */
    public function resolve(Alert $alert, ?string $reason = null): void
    {
        if ($alert->status === AlertStatus::Resolved) {
            return;
        }

        $alert->update([
            'status' => AlertStatus::Resolved,
            'resolved_at' => now(),
        ]);

        // Add note to linked ticket if exists
        if ($alert->ticket_id) {
            $ticket = $alert->ticket;
            if ($ticket && $ticket->status->isOpen()) {
                $systemUserId = TriageConfig::systemUserId() ?? User::orderBy('id')->value('id');
                if ($systemUserId) {
                    $noteBody = $reason ?? "Alert resolved by {$alert->source->label()} monitoring.";
                    $this->ticketService->addNote(
                        $ticket,
                        $noteBody,
                        NoteType::System,
                        true,
                        $systemUserId,
                    );
                }
            }
        }

        Log::info("[Alert] Resolved", [
            'alert_id' => $alert->id,
            'source' => $alert->source->value,
            'title' => $alert->title,
        ]);
    }

    /**
     * Bulk acknowledge alerts.
     */
    public function bulkAcknowledge(array $alertIds, User $user): int
    {
        return Alert::whereIn('id', $alertIds)
            ->where('status', AlertStatus::Active)
            ->update([
                'status' => AlertStatus::Acknowledged,
                'acknowledged_by' => $user->id,
                'acknowledged_at' => now(),
            ]);
    }

    /**
     * Bulk create tickets from alerts.
     */
    public function bulkCreateTickets(array $alertIds, ?int $userId = null): int
    {
        $alerts = Alert::whereIn('id', $alertIds)
            ->whereNull('ticket_id')
            ->whereIn('status', [AlertStatus::Active, AlertStatus::Acknowledged])
            ->get();

        $count = 0;
        foreach ($alerts as $alert) {
            if ($this->createTicket($alert, $userId)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Bulk resolve alerts.
     */
    public function bulkResolve(array $alertIds): int
    {
        $alerts = Alert::whereIn('id', $alertIds)
            ->whereIn('status', [AlertStatus::Active, AlertStatus::Acknowledged, AlertStatus::Ticketed])
            ->get();

        foreach ($alerts as $alert) {
            $this->resolve($alert, 'Manually resolved.');
        }

        return $alerts->count();
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Services/AlertService.php
git commit -m "Add AlertService with upsert, acknowledge, ticket creation, and resolve"
```

---

## Chunk 2: Vendor Service Refactors

### Task 5: Refactor TacticalAlertService

**Files:**
- Modify: `app/Services/Tactical/TacticalAlertService.php`

- [ ] **Step 1: Refactor to use AlertService**

Read the current file first. Replace ticket creation with alert upsert. Key changes:

- Constructor: inject `AlertService` instead of (or alongside) `TicketService`
- `handleAlertFailure()`: extract alert data, call `$this->alertService->upsert()` instead of `$this->ticketService->createTicket()`
- `handleAlertResolved()`: find alert by `source=tactical, source_alert_id=alert_id`, call `$this->alertService->resolve()`
- Keep ALL existing noise filters (transient errors, empty output, severity threshold, overdue workstations)
- Return the `Alert` instead of `?Ticket`

The method signatures change:
- `handleAlertFailure(array $data): ?Alert` (was `?Ticket`)
- `handleAlertResolved(array $data): ?Alert` (was `?Ticket`)

Update the `TacticalWebhookController` to handle the new return type (it returns `ticket_id` which should now come from `$alert->ticket_id`).

- [ ] **Step 2: Update TacticalWebhookController**

The controller currently returns `$ticket?->id`. Since the handler now returns an `Alert`, update to return `$alert?->id` as `alert_id` instead:

```php
return response()->json([
    'status' => 'ok',
    'alert_id' => $alert?->id,
]);
```

- [ ] **Step 3: Commit**

```bash
git add app/Services/Tactical/TacticalAlertService.php app/Http/Controllers/Api/TacticalWebhookController.php
git commit -m "Refactor TacticalAlertService to write to unified Alert model"
```

---

### Task 6: Refactor NinjaAlertService

**Files:**
- Modify: `app/Services/Ninja/NinjaAlertService.php`

- [ ] **Step 1: Refactor to use AlertService**

Read the current file first. Key changes:

- Constructor: inject `AlertService`
- `handleTriggered()`: call `$this->alertService->upsert()` with `source=AlertSource::Ninja`, `source_alert_id=seriesUid`
- `handleReset()`: find alert by `source=ninja, source_alert_id=seriesUid`, call `$this->alertService->resolve()`
- Remove all ticket creation logic (CRITICAL/MAJOR no longer auto-create tickets)
- Return `Alert` instead of `?Ticket`
- Keep the severity extraction and condition name parsing logic

- [ ] **Step 2: Commit**

```bash
git add app/Services/Ninja/NinjaAlertService.php
git commit -m "Refactor NinjaAlertService to write to unified Alert model"
```

---

### Task 7: Refactor CometAlertService

**Files:**
- Modify: `app/Services/Comet/CometAlertService.php`

- [ ] **Step 1: Refactor to use AlertService**

Read the current file first. Key changes:

- Constructor: inject `AlertService`
- `handleJobFailure()`: call `$this->alertService->upsert()` with `source=AlertSource::Comet`, `source_alert_id={DeviceID}:{Classification}`
- `handleJobSuccess()`: find alert by source+sourceAlertId, call `$this->alertService->resolve()`
- Remove all ticket creation logic
- Return `Alert` instead of `?Ticket`

- [ ] **Step 2: Update CometWebhookController**

Update return value from `ticket_id` to `alert_id`:

```php
return response()->json([
    'status' => 'processed',
    'alert_id' => $alert?->id,
]);
```

- [ ] **Step 3: Commit**

```bash
git add app/Services/Comet/CometAlertService.php app/Http/Controllers/Api/CometWebhookController.php
git commit -m "Refactor CometAlertService to write to unified Alert model"
```

---

### Task 8: Refactor HuntressService

**Files:**
- Modify: `app/Services/Huntress/HuntressService.php`

- [ ] **Step 1: Refactor to create Alert alongside Ticket**

Read the current file first. Huntress is special — it uses a CW compat shim and expects ticket IDs back. So:

- Add `AlertService` injection
- In the ticket creation method: AFTER creating the ticket, also create an `Alert` with `status=AlertStatus::Ticketed` and `ticket_id` already set
- `source_alert_id` = incident report URL (already used for dedup)
- On post-remediation (status update to Resolved): also resolve the linked alert

This preserves the CW compat contract while adding alert tracking.

- [ ] **Step 2: Commit**

```bash
git add app/Services/Huntress/HuntressService.php
git commit -m "Add alert tracking alongside Huntress CW compat ticket creation"
```

---

## Chunk 3: UI — Alerts Dashboard and Index

### Task 9: AlertController

**Files:**
- Create: `app/Http/Controllers/Web/AlertController.php`

- [ ] **Step 1: Create AlertController**

```php
<?php

namespace App\Http\Controllers\Web;

use App\Enums\AlertSeverity;
use App\Enums\AlertSource;
use App\Enums\AlertStatus;
use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Services\AlertService;
use Illuminate\Http\Request;

class AlertController extends Controller
{
    public function __construct(
        private readonly AlertService $alertService,
    ) {}

    public function index(Request $request)
    {
        $query = Alert::with(['asset', 'client', 'ticket'])
            ->orderByDesc('fired_at');

        // Filters
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        } else {
            // Default: show open alerts only
            $query->open();
        }

        if ($request->filled('severity')) {
            $query->where('severity', $request->input('severity'));
        }

        if ($request->filled('source')) {
            $query->where('source', $request->input('source'));
        }

        if ($request->filled('client_id')) {
            $query->where('client_id', $request->input('client_id'));
        }

        $alerts = $query->paginate(50);

        // Counts for the summary bar
        $counts = Alert::open()
            ->selectRaw("severity, count(*) as count")
            ->groupBy('severity')
            ->pluck('count', 'severity');

        return view('alerts.index', [
            'alerts' => $alerts,
            'counts' => $counts,
            'statuses' => AlertStatus::cases(),
            'severities' => AlertSeverity::cases(),
            'sources' => AlertSource::cases(),
            'filters' => $request->only(['status', 'severity', 'source', 'client_id']),
        ]);
    }

    public function acknowledge(Alert $alert)
    {
        $this->alertService->acknowledge($alert, auth()->user());

        return back()->with('success', "Alert acknowledged: {$alert->title}");
    }

    public function createTicket(Alert $alert)
    {
        $ticket = $this->alertService->createTicket($alert, auth()->id());

        if ($ticket) {
            return redirect()->route('tickets.show', $ticket)
                ->with('success', "Ticket #{$ticket->id} created from alert.");
        }

        return back()->with('error', 'Failed to create ticket from alert.');
    }

    public function resolve(Alert $alert)
    {
        $this->alertService->resolve($alert, 'Manually resolved by ' . auth()->user()->name);

        return back()->with('success', "Alert resolved: {$alert->title}");
    }

    public function bulkAcknowledge(Request $request)
    {
        $ids = $request->input('alert_ids', []);
        $count = $this->alertService->bulkAcknowledge($ids, auth()->user());

        return back()->with('success', "{$count} alert(s) acknowledged.");
    }

    public function bulkCreateTickets(Request $request)
    {
        $ids = $request->input('alert_ids', []);
        $count = $this->alertService->bulkCreateTickets($ids, auth()->id());

        return back()->with('success', "{$count} ticket(s) created.");
    }

    public function bulkResolve(Request $request)
    {
        $ids = $request->input('alert_ids', []);
        $count = $this->alertService->bulkResolve($ids);

        return back()->with('success', "{$count} alert(s) resolved.");
    }
}
```

- [ ] **Step 2: Add routes**

In `routes/web.php`, add inside the auth middleware group:

```php
// Alerts
Route::get('/alerts', [AlertController::class, 'index'])->name('alerts.index');
Route::post('/alerts/{alert}/acknowledge', [AlertController::class, 'acknowledge'])->name('alerts.acknowledge');
Route::post('/alerts/{alert}/create-ticket', [AlertController::class, 'createTicket'])->name('alerts.create-ticket');
Route::post('/alerts/{alert}/resolve', [AlertController::class, 'resolve'])->name('alerts.resolve');
Route::post('/alerts/bulk-acknowledge', [AlertController::class, 'bulkAcknowledge'])->name('alerts.bulk-acknowledge');
Route::post('/alerts/bulk-create-tickets', [AlertController::class, 'bulkCreateTickets'])->name('alerts.bulk-create-tickets');
Route::post('/alerts/bulk-resolve', [AlertController::class, 'bulkResolve'])->name('alerts.bulk-resolve');
```

Add `use App\Http\Controllers\Web\AlertController;` to the imports.

- [ ] **Step 3: Add nav link**

Find the main navigation in the layout blade file. Add an "Alerts" link alongside Tickets, Clients, Assets:

```blade
<li class="nav-item">
    <a class="nav-link {{ request()->routeIs('alerts.*') ? 'active' : '' }}" href="{{ route('alerts.index') }}">
        <i class="bi bi-bell me-1"></i>Alerts
        @php $openAlertCount = \App\Models\Alert::open()->count(); @endphp
        @if($openAlertCount > 0)
            <span class="badge bg-danger">{{ $openAlertCount }}</span>
        @endif
    </a>
</li>
```

- [ ] **Step 4: Commit**

```bash
git add app/Http/Controllers/Web/AlertController.php routes/web.php resources/views/layouts/*.blade.php
git commit -m "Add AlertController with routes and nav link"
```

---

### Task 10: Alerts Index View

**Files:**
- Create: `resources/views/alerts/index.blade.php`

- [ ] **Step 1: Create alerts index view**

Follow the pattern from `resources/views/tickets/index.blade.php`. Create a full-page view with:

- Summary bar showing counts by severity
- Filter dropdowns (status, severity, source, client)
- Table with columns: checkbox, severity badge, source icon, hostname (linked), title, client, time, status, actions
- Row actions: Acknowledge, Create Ticket, Resolve (as small buttons or dropdown)
- Bulk action bar (shown when checkboxes selected)
- Pagination

This is a standard Blade view — the implementer should read `tickets/index.blade.php` and `tickets/_list.blade.php` for the exact patterns used in this codebase (table structure, filter forms, bulk action JavaScript, pagination).

- [ ] **Step 2: Commit**

```bash
git add resources/views/alerts/index.blade.php
git commit -m "Add alerts index view with filters, bulk actions, and severity summary"
```

---

### Task 11: Dashboard Alerts Card

**Files:**
- Create: `resources/views/dashboard/_alerts-card.blade.php`
- Modify: dashboard view to include the card

- [ ] **Step 1: Create dashboard alerts card**

A compact card showing:
- "Active Alerts" header with total count
- Severity breakdown (badges: X critical, Y error, Z warning)
- List of 5-10 most recent/critical alerts: severity badge, source icon, hostname, title, relative time
- "View All" link to `/alerts`

- [ ] **Step 2: Include card in dashboard**

Find the main dashboard view and add `@include('dashboard._alerts-card')` in an appropriate position (before or alongside the existing ticket activity cards).

- [ ] **Step 3: Commit**

```bash
git add resources/views/dashboard/_alerts-card.blade.php resources/views/dashboard/*.blade.php
git commit -m "Add alerts summary card to dashboard"
```

---

## Chunk 4: Integration Updates and Cleanup

### Task 12: Update Asset Detail View

**Files:**
- Modify: `resources/views/assets/show.blade.php`

- [ ] **Step 1: Replace Ninja alerts section with unified alerts**

Find the section that displays `activeNinjaAlerts` and replace it with alerts from the unified model. Show all active alerts for the asset regardless of source:

- Source icon + severity badge
- Title, message (truncated)
- Time fired
- Status badge
- Action buttons (Acknowledge, Create Ticket)

Also update the AssetController to load `activeAlerts` instead of `activeNinjaAlerts`.

- [ ] **Step 2: Commit**

```bash
git add resources/views/assets/show.blade.php app/Http/Controllers/Web/AssetController.php
git commit -m "Replace Ninja alerts with unified alerts on asset detail page"
```

---

### Task 13: Update ContextBuilder and JunkDetector

**Files:**
- Modify: `app/Services/Triage/ContextBuilder.php`
- Modify: `app/Services/Triage/JunkDetector.php`

- [ ] **Step 1: Update ContextBuilder**

Find where `activeNinjaAlerts` is used in `buildAssetSection()`. Replace with:

```php
$activeAlerts = $asset->activeAlerts;
if ($activeAlerts->count() > 0) {
    $info .= "\n  Active Alerts: " . $activeAlerts->count();
    foreach ($activeAlerts->take(3) as $alert) {
        $info .= "\n    - [{$alert->severity->label()}] {$alert->title}: {$alert->message}";
    }
}
```

- [ ] **Step 2: Update JunkDetector**

Find the `TicketSource::NinjaAlert` exemption and update to also exempt `TicketSource::Alert`:

```php
if ($ticket->source === TicketSource::NinjaAlert || $ticket->source === TicketSource::Alert) {
    return true;
}
```

- [ ] **Step 3: Commit**

```bash
git add app/Services/Triage/ContextBuilder.php app/Services/Triage/JunkDetector.php
git commit -m "Update ContextBuilder and JunkDetector for unified alerts"
```

---

### Task 14: Update Reconciliation Commands

**Files:**
- Modify: `app/Console/Commands/TacticalReconcileAlerts.php`
- Modify: `app/Console/Commands/NinjaReconcileAlerts.php`

- [ ] **Step 1: Update TacticalReconcileAlerts**

Read the current file. Change it to:
- Query open `Alert` records where `source = tactical` instead of open tickets with `source_ref`
- Match against Tactical API alerts by `source_alert_id`
- Call `AlertService::resolve()` for resolved alerts

- [ ] **Step 2: Update NinjaReconcileAlerts**

Read the current file. Change it to:
- Query open `Alert` records where `source = ninja` instead of `NinjaAlert`
- Match against NinjaRMM API alerts by `source_alert_id`
- Call `AlertService::resolve()` for stale alerts

- [ ] **Step 3: Commit**

```bash
git add app/Console/Commands/TacticalReconcileAlerts.php app/Console/Commands/NinjaReconcileAlerts.php
git commit -m "Update reconciliation commands to use unified Alert model"
```

---

### Task 15: Remove NinjaAlert Model and Final Cleanup

**Files:**
- Delete: `app/Models/NinjaAlert.php`
- Modify: any remaining references

- [ ] **Step 1: Delete NinjaAlert model**

```bash
rm app/Models/NinjaAlert.php
```

- [ ] **Step 2: Search for remaining NinjaAlert references**

```bash
grep -r "NinjaAlert\|ninja_alerts\|activeNinjaAlerts\|ninjaAlerts" --include="*.php" --include="*.blade.php" -l
```

Fix any remaining references — update imports, replace with `Alert` model usage.

- [ ] **Step 3: Commit**

```bash
git add -A
git commit -m "Remove NinjaAlert model and all remaining references"
```

---

### Task 16: Final Verification

- [ ] **Step 1: Run migrations**

```bash
php artisan migrate:fresh --seed  # On dev only
```

Or verify with `php artisan migrate` if incremental.

- [ ] **Step 2: Verify routes**

```bash
php artisan route:list --path=alerts
```

- [ ] **Step 3: Test webhook flow**

Trigger a Tactical alert failure webhook and verify it creates an Alert record (not a ticket).

- [ ] **Step 4: Test alert-to-ticket conversion**

Navigate to `/alerts`, find the test alert, click "Create Ticket", verify ticket is created and alert moves to `ticketed` status.

- [ ] **Step 5: Update INSTALL.md**

Add the alerts dashboard to the documentation. Note the behavior change: monitoring alerts no longer auto-create tickets.

- [ ] **Step 6: Commit**

```bash
git add docs/INSTALL.md
git commit -m "Document unified alerts system in INSTALL.md"
```
