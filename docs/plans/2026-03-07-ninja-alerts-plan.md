# NinjaRMM Alert Integration — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Ingest NinjaRMM monitoring alerts into the PSA — store all alerts against assets, create tickets for Critical/Major severity, auto-resolve when alerts clear.

**Architecture:** Ninja sends webhooks (CONDITION type, TRIGGERED/RESET statusCode) to the existing `NinjaWebhookController`. `ProcessNinjaWebhook` delegates to a new `NinjaAlertService` which manages alert records, ticket creation, and auto-resolution. Asset detail page shows an alerts card.

**Tech Stack:** Laravel 12, MariaDB, Blade + Bootstrap 5.3

**Design doc:** `docs/plans/2026-03-07-ninja-alerts-design.md`

---

### Task 1: Migration — `ninja_alerts` table

**Files:**
- Create: `database/migrations/xxxx_create_ninja_alerts_table.php`

**Step 1: Create migration**

```php
Schema::create('ninja_alerts', function (Blueprint $table) {
    $table->id();
    $table->foreignId('asset_id')->nullable()->constrained('assets')->nullOnDelete();
    $table->unsignedInteger('ninja_device_id')->index();
    $table->string('ninja_alert_uid')->unique();
    $table->string('severity', 20)->nullable(); // CRITICAL, MAJOR, MODERATE, MINOR
    $table->string('condition_name')->nullable();
    $table->text('message')->nullable();
    $table->string('status', 20)->default('active'); // active, resolved
    $table->foreignId('ticket_id')->nullable()->constrained('tickets')->nullOnDelete();
    $table->timestamp('fired_at')->nullable();
    $table->timestamp('resolved_at')->nullable();
    $table->timestamps();

    $table->index(['asset_id', 'status']); // Active alerts per asset
    $table->index(['ticket_id']); // Lookup alert by ticket
});
```

**Step 2: Run migration**

Run: `php artisan migrate`
Expected: Table created successfully

**Step 3: Commit**

```
feat: Add ninja_alerts migration
```

---

### Task 2: NinjaAlert model + Asset relationship

**Files:**
- Create: `app/Models/NinjaAlert.php`
- Modify: `app/Models/Asset.php`

**Step 1: Create NinjaAlert model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NinjaAlert extends Model
{
    protected $fillable = [
        'asset_id',
        'ninja_device_id',
        'ninja_alert_uid',
        'severity',
        'condition_name',
        'message',
        'status',
        'ticket_id',
        'fired_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'fired_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function severityBadgeClass(): string
    {
        return match (strtoupper($this->severity ?? '')) {
            'CRITICAL' => 'bg-danger',
            'MAJOR' => 'bg-warning text-dark',
            'MODERATE' => 'bg-info text-dark',
            'MINOR' => 'bg-secondary',
            default => 'bg-secondary',
        };
    }
}
```

**Step 2: Add relationship to Asset model**

In `app/Models/Asset.php`, add:

```php
use Illuminate\Database\Eloquent\Relations\HasMany;

public function ninjaAlerts(): HasMany
{
    return $this->hasMany(NinjaAlert::class);
}

public function activeNinjaAlerts(): HasMany
{
    return $this->hasMany(NinjaAlert::class)->where('status', 'active');
}
```

**Step 3: Commit**

```
feat: Add NinjaAlert model and Asset relationship
```

---

### Task 3: Add `TicketSource::NinjaAlert` enum value

**Files:**
- Modify: `app/Enums/TicketSource.php`

**Step 1: Add enum case and label**

Add to the enum:
```php
case NinjaAlert = 'ninja_alert';
```

Add to `label()`:
```php
self::NinjaAlert => 'Ninja Alert',
```

**Step 2: Commit**

```
feat: Add NinjaAlert ticket source enum
```

---

### Task 4: NinjaAlertService — core alert processing

**Files:**
- Create: `app/Services/Ninja/NinjaAlertService.php`

**Step 1: Create the service**

This service handles both TRIGGERED and RESET webhooks. Key methods:

- `handleTriggered(array $payload)` — creates/updates alert record, creates ticket if Critical/Major
- `handleReset(array $payload)` — resolves alert, auto-resolves ticket if untouched
- `isHumanTouched(Ticket $ticket): bool` — checks for non-system notes or manual changes

**Payload field reference** (from captured webhooks):
- `$payload['seriesUid']` — unique alert instance ID (dedup key)
- `$payload['deviceId']` — Ninja device ID
- `$payload['severity']` — `CRITICAL`, `MAJOR`, `MODERATE`, `MINOR` (nullable — not all conditions have severity)
- `$payload['priority']` — `CRITICAL`, `HIGH`, `MODERATE`, `LOW` (nullable)
- `$payload['sourceName']` — condition name (nullable in RESET)
- `$payload['data']['message']['params']['condition_name']` — condition name (alternative, present in some)
- `$payload['message']` — full human-readable alert text

```php
<?php

namespace App\Services\Ninja;

use App\Enums\NoteType;
use App\Enums\TicketPriority;
use App\Enums\TicketSource;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Enums\WhoType;
use App\Models\Asset;
use App\Models\NinjaAlert;
use App\Models\Ticket;
use App\Services\TicketService;
use App\Support\TriageConfig;
use Illuminate\Support\Facades\Log;

class NinjaAlertService
{
    public function __construct(
        private readonly TicketService $ticketService,
    ) {}

    public function handleTriggered(array $payload): void
    {
        $seriesUid = $payload['seriesUid'] ?? null;
        $deviceId = $payload['deviceId'] ?? null;

        if (! $seriesUid || ! $deviceId) {
            Log::warning('[NinjaAlert] TRIGGERED webhook missing seriesUid or deviceId', [
                'seriesUid' => $seriesUid,
                'deviceId' => $deviceId,
            ]);
            return;
        }

        $asset = Asset::where('ninja_id', $deviceId)->first();
        $severity = $payload['severity'] ?? null; // CRITICAL, MAJOR, MODERATE, MINOR or null
        $conditionName = $payload['sourceName']
            ?? $payload['data']['message']['params']['condition_name']
            ?? null;
        $message = $payload['message'] ?? null;
        $firedAt = isset($payload['activityTime'])
            ? \Carbon\Carbon::createFromTimestamp($payload['activityTime'])
            : now();

        // Upsert alert record
        $alert = NinjaAlert::updateOrCreate(
            ['ninja_alert_uid' => $seriesUid],
            [
                'asset_id' => $asset?->id,
                'ninja_device_id' => $deviceId,
                'severity' => $severity ? strtoupper($severity) : null,
                'condition_name' => $conditionName,
                'message' => $message,
                'status' => 'active',
                'fired_at' => $firedAt,
                'resolved_at' => null,
            ]
        );

        Log::info('[NinjaAlert] Alert triggered', [
            'alert_id' => $alert->id,
            'device_id' => $deviceId,
            'asset_id' => $asset?->id,
            'severity' => $severity,
            'condition' => $conditionName,
        ]);

        // Only create tickets for CRITICAL or MAJOR
        if (! in_array(strtoupper($severity ?? ''), ['CRITICAL', 'MAJOR'])) {
            return;
        }

        // If alert already has an open ticket, add a note instead of creating a new one
        if ($alert->ticket_id) {
            $existingTicket = Ticket::find($alert->ticket_id);
            if ($existingTicket && ! in_array($existingTicket->status, [TicketStatus::Resolved, TicketStatus::Closed])) {
                $systemUserId = TriageConfig::systemUserId();
                if ($systemUserId) {
                    $this->ticketService->addNote(
                        $existingTicket,
                        "**Alert re-fired in NinjaRMM monitoring.**\n\n{$message}",
                        NoteType::System,
                        true,
                        $systemUserId,
                    );
                }
                Log::info('[NinjaAlert] Alert re-fired, added note to existing ticket', [
                    'alert_id' => $alert->id,
                    'ticket_id' => $existingTicket->id,
                ]);
                return;
            }
        }

        // Create ticket
        $hostname = $asset?->hostname ?? "Device {$deviceId}";
        $priority = strtoupper($severity) === 'CRITICAL'
            ? TicketPriority::P1
            : TicketPriority::P2;

        $description = "**NinjaRMM Alert:** {$conditionName}\n\n"
            . "**Device:** {$hostname}\n"
            . "**Severity:** {$severity}\n\n"
            . ($message ?? 'No additional details.');

        $ticket = $this->ticketService->createTicket([
            'subject' => "[Ninja Alert] {$conditionName} on {$hostname}",
            'description' => $description,
            'type' => TicketType::Incident->value,
            'source' => TicketSource::NinjaAlert->value,
            'priority' => $priority->value,
            'client_id' => $asset?->client_id,
        ], TriageConfig::systemUserId());

        // Link asset to ticket
        if ($asset) {
            $ticket->assets()->syncWithoutDetaching([$asset->id]);
        }

        // Store ticket_id on alert
        $alert->update(['ticket_id' => $ticket->id]);

        Log::info('[NinjaAlert] Ticket created from alert', [
            'alert_id' => $alert->id,
            'ticket_id' => $ticket->id,
            'severity' => $severity,
            'hostname' => $hostname,
        ]);
    }

    public function handleReset(array $payload): void
    {
        $seriesUid = $payload['seriesUid'] ?? null;

        if (! $seriesUid) {
            Log::warning('[NinjaAlert] RESET webhook missing seriesUid');
            return;
        }

        $alert = NinjaAlert::where('ninja_alert_uid', $seriesUid)->first();

        if (! $alert) {
            // Alert fired before we started tracking — just log and skip
            Log::info('[NinjaAlert] RESET for unknown alert, skipping', ['seriesUid' => $seriesUid]);
            return;
        }

        $resolvedAt = isset($payload['activityTime'])
            ? \Carbon\Carbon::createFromTimestamp($payload['activityTime'])
            : now();

        $alert->update([
            'status' => 'resolved',
            'resolved_at' => $resolvedAt,
        ]);

        Log::info('[NinjaAlert] Alert resolved', [
            'alert_id' => $alert->id,
            'condition' => $alert->condition_name,
        ]);

        // Auto-resolve linked ticket if untouched by humans
        if (! $alert->ticket_id) {
            return;
        }

        $ticket = Ticket::find($alert->ticket_id);
        if (! $ticket || in_array($ticket->status, [TicketStatus::Resolved, TicketStatus::Closed])) {
            return;
        }

        $systemUserId = TriageConfig::systemUserId();
        if (! $systemUserId) {
            return;
        }

        if ($this->isHumanTouched($ticket)) {
            // Human is working on it — just add a note
            $this->ticketService->addNote(
                $ticket,
                '**Alert cleared in NinjaRMM monitoring.** Leaving ticket open for technician review.',
                NoteType::System,
                true,
                $systemUserId,
            );
            Log::info('[NinjaAlert] Alert cleared but ticket is human-touched, added note only', [
                'alert_id' => $alert->id,
                'ticket_id' => $ticket->id,
            ]);
        } else {
            // Auto-resolve
            $this->ticketService->addNote(
                $ticket,
                '**Alert cleared in NinjaRMM monitoring.** Auto-resolving ticket.',
                NoteType::System,
                true,
                $systemUserId,
            );
            $ticket->update([
                'status' => TicketStatus::Resolved,
                'resolved_at' => now(),
                'resolution' => 'Alert auto-cleared in NinjaRMM monitoring.',
            ]);
            Log::info('[NinjaAlert] Ticket auto-resolved from alert reset', [
                'alert_id' => $alert->id,
                'ticket_id' => $ticket->id,
            ]);
        }
    }

    /**
     * Check if a ticket has been touched by a human (non-system notes or manual status changes).
     */
    private function isHumanTouched(Ticket $ticket): bool
    {
        $humanNoteTypes = collect(NoteType::cases())
            ->reject(fn (NoteType $t) => $t->isSystemGenerated())
            ->pluck('value');

        return $ticket->notes()
            ->whereIn('note_type', $humanNoteTypes)
            ->exists();
    }
}
```

**Step 2: Commit**

```
feat: Add NinjaAlertService for alert processing and ticket creation
```

---

### Task 5: Wire alerts into ProcessNinjaWebhook

**Files:**
- Modify: `app/Jobs/ProcessNinjaWebhook.php`

**Step 1: Add CONDITION handling**

After the `CLIENT_*` block (line ~93) and before the end-user block, add:

```php
// --- Condition/Alert events ---
if (in_array($type, ['TRIGGERED', 'RESET'])) {
    $activityType = $webhook->payload['activityType'] ?? null;
    if ($activityType === 'CONDITION') {
        $alertService = app(NinjaAlertService::class);

        if ($type === 'TRIGGERED') {
            $alertService->handleTriggered($webhook->payload);
        } else {
            $alertService->handleReset($webhook->payload);
        }

        $webhook->markProcessed();
        return;
    }

    $webhook->markSkipped("Non-condition alert event: {$activityType}");
    return;
}
```

Add the import at the top:
```php
use App\Services\Ninja\NinjaAlertService;
```

**Step 2: Commit**

```
feat: Wire NinjaRMM alert webhooks into ProcessNinjaWebhook
```

---

### Task 6: JunkDetector — skip NinjaAlert tickets

**Files:**
- Modify: `app/Services/Triage/JunkDetector.php`

**Step 1: Add skip for NinjaAlert source**

In `shouldSkip()` method, after the Portal check (line ~191), add:

```php
// Ninja alert tickets are machine-generated monitoring events — not junk
if ($ticket->source === \App\Enums\TicketSource::NinjaAlert) {
    return true;
}
```

**Step 2: Commit**

```
feat: Skip junk detection for NinjaAlert tickets
```

---

### Task 7: ContextBuilder — include active alerts in asset section

**Files:**
- Modify: `app/Services/Triage/ContextBuilder.php`

**Step 1: Add alert info after existing asset fields**

In `buildAssetSection()`, after the M365/Intune block (line ~388) and before the length truncation check, add:

```php
// Active NinjaRMM alerts
$activeAlerts = $asset->activeNinjaAlerts()->get();
if ($activeAlerts->isNotEmpty()) {
    $info .= ' | Active Alerts: ' . $activeAlerts->count();
    foreach ($activeAlerts->take(3) as $alert) {
        $sev = $alert->severity ? "[{$alert->severity}]" : '';
        $info .= "\n  - {$sev} {$alert->condition_name}: " . mb_substr($alert->message ?? '', 0, 200);
    }
}
```

**Step 2: Commit**

```
feat: Include active NinjaRMM alerts in triage context
```

---

### Task 8: Asset detail page — Alerts card

**Files:**
- Modify: `resources/views/assets/show.blade.php`
- Modify: `app/Http/Controllers/Web/AssetController.php` (eager load alerts)

**Step 1: Eager load alerts in AssetController::show()**

In the `show()` method, ensure `activeNinjaAlerts` is loaded. Find where the asset is loaded (should be `Asset::withTrashed()->findOrFail($asset)`) and add eager loading:

```php
$asset = Asset::withTrashed()->findOrFail($asset);
$asset->load(['activeNinjaAlerts']);
```

**Step 2: Add Alerts card to asset show view**

Add after the Sync Info / Client row (around line 207, before the DNS Security section). Use a full-width card:

```blade
{{-- NinjaRMM Alerts --}}
@if($asset->ninja_id)
@php
    $activeAlerts = $asset->activeNinjaAlerts;
    $resolvedAlerts = $asset->ninjaAlerts()->where('status', 'resolved')->latest('resolved_at')->limit(10)->get();
@endphp
@if($activeAlerts->isNotEmpty() || $resolvedAlerts->isNotEmpty())
<div class="row mt-4">
    <div class="col-12">
        <div class="card shadow-sm {{ $activeAlerts->isNotEmpty() ? 'border-warning' : '' }}">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>
                    <i class="bi bi-bell me-2"></i>NinjaRMM Alerts
                    @if($activeAlerts->isNotEmpty())
                        <span class="badge bg-danger ms-1">{{ $activeAlerts->count() }} active</span>
                    @endif
                </span>
                @if($resolvedAlerts->isNotEmpty())
                    <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#resolvedAlerts">
                        Show resolved
                    </button>
                @endif
            </div>
            <div class="card-body p-0">
                @if($activeAlerts->isNotEmpty())
                <table class="table table-sm mb-0">
                    <thead>
                        <tr>
                            <th>Severity</th>
                            <th>Condition</th>
                            <th>Message</th>
                            <th>Fired</th>
                            <th>Ticket</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($activeAlerts as $alert)
                        <tr>
                            <td>
                                @if($alert->severity)
                                    <span class="badge {{ $alert->severityBadgeClass() }}">{{ $alert->severity }}</span>
                                @else
                                    <span class="badge bg-secondary">—</span>
                                @endif
                            </td>
                            <td>{{ $alert->condition_name ?? '—' }}</td>
                            <td class="small">{{ Str::limit($alert->message, 120) }}</td>
                            <td class="text-nowrap small">{{ $alert->fired_at?->toAppTz()->format('M j, g:i A') }}</td>
                            <td>
                                @if($alert->ticket_id)
                                    <a href="{{ route('tickets.show', $alert->ticket_id) }}">
                                        #{{ $alert->ticket?->display_id }}
                                    </a>
                                @endif
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
                @else
                    <p class="text-muted small p-3 mb-0">No active alerts.</p>
                @endif

                @if($resolvedAlerts->isNotEmpty())
                <div class="collapse" id="resolvedAlerts">
                    <hr class="my-0">
                    <table class="table table-sm mb-0 table-light">
                        <thead>
                            <tr>
                                <th>Severity</th>
                                <th>Condition</th>
                                <th>Message</th>
                                <th>Fired</th>
                                <th>Resolved</th>
                                <th>Ticket</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($resolvedAlerts as $alert)
                            <tr class="text-muted">
                                <td>
                                    @if($alert->severity)
                                        <span class="badge {{ $alert->severityBadgeClass() }}">{{ $alert->severity }}</span>
                                    @else
                                        <span class="badge bg-secondary">—</span>
                                    @endif
                                </td>
                                <td>{{ $alert->condition_name ?? '—' }}</td>
                                <td class="small">{{ Str::limit($alert->message, 120) }}</td>
                                <td class="text-nowrap small">{{ $alert->fired_at?->toAppTz()->format('M j, g:i A') }}</td>
                                <td class="text-nowrap small">{{ $alert->resolved_at?->toAppTz()->format('M j, g:i A') }}</td>
                                <td>
                                    @if($alert->ticket_id)
                                        <a href="{{ route('tickets.show', $alert->ticket_id) }}">
                                            #{{ $alert->ticket?->display_id }}
                                        </a>
                                    @endif
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @endif
            </div>
        </div>
    </div>
</div>
@endif
@endif
```

**Step 3: Commit**

```
feat: Add NinjaRMM Alerts card to asset detail page
```

---

### Task 9: Handle alerts without severity

**Files:**
- Already handled in `NinjaAlertService`

Alerts without severity configured in Ninja arrive with `severity: null`. The service stores them as informational records (no ticket created since `null` is not in `['CRITICAL', 'MAJOR']`). They appear on the asset page with a grey "—" badge. No extra work needed — just documenting the behavior.

---

### Task 10: Process existing skipped TRIGGERED/RESET webhooks

**Files:**
- No code changes — run a one-time artisan tinker command after deployment

After deploying, reprocess the webhooks that were skipped before the handler existed:

```php
// In tinker:
$skipped = \App\Models\NinjaWebhook::whereIn('activity_type', ['TRIGGERED', 'RESET'])
    ->where('status', 'skipped')
    ->get();

foreach ($skipped as $webhook) {
    $webhook->update(['status' => 'pending']);
    \App\Jobs\ProcessNinjaWebhook::dispatch($webhook->id);
}

echo "Requeued {$skipped->count()} webhooks\n";
```

---

## Commit Summary

1. `feat: Add ninja_alerts migration`
2. `feat: Add NinjaAlert model and Asset relationship`
3. `feat: Add NinjaAlert ticket source enum`
4. `feat: Add NinjaAlertService for alert processing and ticket creation`
5. `feat: Wire NinjaRMM alert webhooks into ProcessNinjaWebhook`
6. `feat: Skip junk detection for NinjaAlert tickets`
7. `feat: Include active NinjaRMM alerts in triage context`
8. `feat: Add NinjaRMM Alerts card to asset detail page`
