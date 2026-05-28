# ScreenConnect (ConnectWise Control) Integration — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Receive ScreenConnect webhook events to sync asset data, track online/offline status, log activity events, count agents for licensing, and deep-link to remote sessions.

**Architecture:** Webhook-receive-only integration. ScreenConnect sends HTTP POSTs (JSON) to a secret-URL endpoint. We store-then-dispatch (like Level): save raw payload to `screenconnect_webhooks`, dispatch a queued job that resolves client by company name, matches asset by session ID or hostname, updates asset fields, and optionally logs activity events. No outbound API calls — ScreenConnect has no REST API.

**Tech Stack:** Laravel 12, queued jobs, MariaDB, Bootstrap 5 (CDN)

---

### Task 1: Migration — ScreenConnect Webhook Storage

**Files:**
- Create: `database/migrations/2026_03_11_210000_create_screenconnect_tables.php`

**Step 1: Create the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Raw webhook storage (store-then-dispatch pattern)
        Schema::create('screenconnect_webhooks', function (Blueprint $table) {
            $table->id();
            $table->string('event_type')->index();
            $table->string('session_id', 36)->nullable()->index();
            $table->json('payload');
            $table->string('status')->default('pending')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        // Activity audit log (commands, file transfers, elevation, etc.)
        Schema::create('screenconnect_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->string('session_id', 36)->index();
            $table->string('event_type', 50)->index();
            $table->timestamp('event_time')->nullable();
            $table->string('host', 100)->nullable();              // tech who did it
            $table->text('data')->nullable();                      // event details (command text, file name, etc.)
            $table->string('participant', 100)->nullable();        // connection participant
            $table->string('network_address', 45)->nullable();     // connection IP
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('screenconnect_events');
        Schema::dropIfExists('screenconnect_webhooks');
    }
};
```

**Step 2: Run migration**

Run: `php artisan migrate`
Expected: Both tables created successfully.

**Step 3: Commit**

```bash
git add database/migrations/2026_03_11_210000_create_screenconnect_tables.php
git commit -m "feat(screenconnect): create webhook and event tables"
```

---

### Task 2: Migration — Asset Columns for ScreenConnect

**Files:**
- Create: `database/migrations/2026_03_11_210001_add_screenconnect_columns_to_assets.php`
- Modify: `app/Models/Asset.php` — add to `$fillable` and `casts()`

**Step 1: Create the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->string('screenconnect_session_id', 36)->nullable()->unique()->after('m365_synced_at');
            $table->boolean('screenconnect_online')->nullable()->after('screenconnect_session_id');
            $table->string('screenconnect_client_version', 30)->nullable()->after('screenconnect_online');
            $table->timestamp('screenconnect_last_seen_at')->nullable()->after('screenconnect_client_version');
            $table->timestamp('screenconnect_synced_at')->nullable()->after('screenconnect_last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn([
                'screenconnect_session_id',
                'screenconnect_online',
                'screenconnect_client_version',
                'screenconnect_last_seen_at',
                'screenconnect_synced_at',
            ]);
        });
    }
};
```

**Step 2: Update Asset model**

In `app/Models/Asset.php`, add to the `$fillable` array (after the existing `m365_synced_at` entry):

```php
'screenconnect_session_id',
'screenconnect_online',
'screenconnect_client_version',
'screenconnect_last_seen_at',
'screenconnect_synced_at',
```

In `casts()`, add:

```php
'screenconnect_online' => 'boolean',
'screenconnect_last_seen_at' => 'datetime',
'screenconnect_synced_at' => 'datetime',
```

**Step 3: Run migration**

Run: `php artisan migrate`
Expected: 5 columns added to assets table.

**Step 4: Commit**

```bash
git add database/migrations/2026_03_11_210001_add_screenconnect_columns_to_assets.php app/Models/Asset.php
git commit -m "feat(screenconnect): add asset columns for session tracking"
```

---

### Task 3: Models — ScreenConnectWebhook and ScreenConnectEvent

**Files:**
- Create: `app/Models/ScreenConnectWebhook.php`
- Create: `app/Models/ScreenConnectEvent.php`

**Step 1: Create ScreenConnectWebhook model**

Follow the `LevelWebhook` pattern exactly (`app/Models/LevelWebhook.php`):

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScreenConnectWebhook extends Model
{
    protected $fillable = [
        'event_type',
        'session_id',
        'payload',
        'status',
        'attempts',
        'error',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function markProcessed(): void
    {
        $this->update([
            'status' => 'processed',
            'processed_at' => now(),
        ]);
    }

    public function markSkipped(string $reason): void
    {
        $this->update([
            'status' => 'skipped',
            'error' => $reason,
            'processed_at' => now(),
        ]);
    }

    public function markFailed(string $error): void
    {
        $this->increment('attempts');
        $this->update([
            'error' => $error,
            'status' => $this->attempts >= 3 ? 'failed' : 'pending',
        ]);
    }
}
```

**Step 2: Create ScreenConnectEvent model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScreenConnectEvent extends Model
{
    protected $fillable = [
        'asset_id',
        'session_id',
        'event_type',
        'event_time',
        'host',
        'data',
        'participant',
        'network_address',
    ];

    protected function casts(): array
    {
        return [
            'event_time' => 'datetime',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
```

**Step 3: Commit**

```bash
git add app/Models/ScreenConnectWebhook.php app/Models/ScreenConnectEvent.php
git commit -m "feat(screenconnect): add webhook and event models"
```

---

### Task 4: Config Helper — ScreenConnectConfig

**Files:**
- Create: `app/Support/ScreenConnectConfig.php`

**Step 1: Create the config class**

Follow the `ControlDConfig` pattern (`app/Support/ControlDConfig.php`):

```php
<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Str;

class ScreenConnectConfig
{
    public static function get(string $key): ?string
    {
        return match ($key) {
            'base_url' => Setting::getValue('screenconnect_base_url'),
            'webhook_secret' => Setting::getValue('screenconnect_webhook_secret'),
            default => null,
        };
    }

    public static function isEnabled(): bool
    {
        return Setting::getValue('screenconnect_enabled', '0') === '1';
    }

    public static function isConfigured(): bool
    {
        return ! empty(self::get('base_url')) && ! empty(self::get('webhook_secret'));
    }

    public static function baseUrl(): ?string
    {
        return self::get('base_url');
    }

    public static function webhookSecret(): ?string
    {
        return self::get('webhook_secret');
    }

    /**
     * Build a deep link URL to access a specific session in ScreenConnect.
     */
    public static function sessionUrl(string $sessionId): ?string
    {
        $base = self::baseUrl();

        if (! $base) {
            return null;
        }

        return rtrim($base, '/') . '/Host#Access/All%20Machines///' . $sessionId;
    }

    /**
     * Generate a random webhook secret for first-time setup.
     */
    public static function generateSecret(): string
    {
        return Str::random(48);
    }
}
```

**Step 2: Commit**

```bash
git add app/Support/ScreenConnectConfig.php
git commit -m "feat(screenconnect): add config helper"
```

---

### Task 5: Webhook Middleware and Route

**Files:**
- Create: `app/Http/Middleware/VerifyScreenConnectSecret.php`
- Modify: `routes/api.php`

**Step 1: Create the middleware**

Follow the `VerifyPlivoWebhookSecret` pattern (`app/Http/Middleware/VerifyPlivoWebhookSecret.php`):

```php
<?php

namespace App\Http\Middleware;

use App\Support\ScreenConnectConfig;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyScreenConnectSecret
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = ScreenConnectConfig::webhookSecret();

        if (! $secret) {
            abort(403, 'ScreenConnect webhook not configured');
        }

        if ($request->route('secret') !== $secret) {
            abort(403, 'Invalid webhook secret');
        }

        return $next($request);
    }
}
```

**Step 2: Add the route**

In `routes/api.php`, add after the Plivo routes (around line 39):

```php
// ScreenConnect webhooks — secret token in URL for authentication
Route::post('webhooks/screenconnect/{secret}', [App\Http\Controllers\Api\ScreenConnectWebhookController::class, 'handle'])
    ->middleware([App\Http\Middleware\VerifyScreenConnectSecret::class, 'throttle:120,1']);
```

Add the import at the top:

```php
use App\Http\Controllers\Api\ScreenConnectWebhookController;
use App\Http\Middleware\VerifyScreenConnectSecret;
```

**Step 3: Commit**

```bash
git add app/Http/Middleware/VerifyScreenConnectSecret.php routes/api.php
git commit -m "feat(screenconnect): add webhook middleware and route"
```

---

### Task 6: Webhook Controller

**Files:**
- Create: `app/Http/Controllers/Api/ScreenConnectWebhookController.php`

**Step 1: Create the controller**

Follow the `LevelWebhookController` pattern (`app/Http/Controllers/Api/LevelWebhookController.php`):

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessScreenConnectWebhook;
use App\Models\ScreenConnectWebhook;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ScreenConnectWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $payload = $request->all();

        $eventType = $payload['event_type'] ?? 'unknown';
        $sessionId = $payload['session_id'] ?? null;

        $webhook = ScreenConnectWebhook::create([
            'event_type' => $eventType,
            'session_id' => $sessionId,
            'payload' => $payload,
        ]);

        Log::debug('[ScreenConnect] Webhook received', [
            'event_type' => $eventType,
            'session_id' => $sessionId,
            'webhook_id' => $webhook->id,
        ]);

        ProcessScreenConnectWebhook::dispatch($webhook->id);

        return response()->json(['status' => 'ok']);
    }
}
```

**Step 2: Commit**

```bash
git add app/Http/Controllers/Api/ScreenConnectWebhookController.php
git commit -m "feat(screenconnect): add webhook controller"
```

---

### Task 7: Sync Service — Asset Matching and Updates

**Files:**
- Create: `app/Services/ScreenConnect/ScreenConnectSyncService.php`

This is the core logic. The job (Task 8) delegates to this service.

**Step 1: Create the sync service**

```php
<?php

namespace App\Services\ScreenConnect;

use App\Models\Asset;
use App\Models\Client;
use App\Models\ScreenConnectEvent;
use Illuminate\Support\Facades\Log;

class ScreenConnectSyncService
{
    /**
     * Event types that represent device status changes (update asset fields).
     */
    private const DEVICE_EVENTS = [
        'Connected',
        'Disconnected',
        'ModifiedName',
        'ModifiedGuestInfo',
        'CreatedSession',
    ];

    /**
     * Event types that should be logged as activity audit entries.
     */
    private const ACTIVITY_EVENTS = [
        'RanCommand',
        'SentMessage',
        'SentFiles',
        'CopiedFiles',
        'CopiedText',
        'DraggedFiles',
        'RanFiles',
        'RequestedElevation',
        'ApprovedRequest',
        'DeniedRequest',
        'SentPrintJob',
        'ReceivedPrintJob',
    ];

    /**
     * Process a single webhook payload.
     *
     * @return string Status message for logging
     */
    public function processWebhook(array $payload): string
    {
        $eventType = $payload['event_type'] ?? 'unknown';
        $sessionId = $payload['session_id'] ?? null;
        $sessionType = $payload['session_type'] ?? null;
        $company = $payload['company'] ?? null;
        $hostname = $payload['guest_machine_name'] ?? null;

        // Only process Access sessions
        if ($sessionType && $sessionType !== 'Access') {
            return "Skipped non-Access session type: {$sessionType}";
        }

        if (! $sessionId) {
            return 'Skipped: no session_id';
        }

        // Resolve client by company name
        $client = $this->resolveClient($company);

        // Resolve asset: by session ID first, then by hostname scoped to client
        $asset = $this->resolveAsset($sessionId, $hostname, $client);

        if (! $asset) {
            return "No matching asset for session {$sessionId} (host: {$hostname}, company: {$company})";
        }

        // Link session ID if not already linked
        if (! $asset->screenconnect_session_id) {
            $asset->screenconnect_session_id = $sessionId;
        }

        // Update device info on status events
        if ($this->isDeviceEvent($eventType)) {
            $this->updateAssetFromPayload($asset, $payload, $eventType);
        }

        // Log activity events
        if ($this->isActivityEvent($eventType)) {
            $this->logActivityEvent($asset, $payload);
        }

        $asset->screenconnect_synced_at = now();
        $asset->save();

        return "Processed {$eventType} for asset #{$asset->id} ({$asset->name})";
    }

    /**
     * Resolve client by exact company name match (case-insensitive).
     */
    private function resolveClient(?string $company): ?Client
    {
        if (! $company) {
            return null;
        }

        return Client::whereRaw('LOWER(name) = ?', [mb_strtolower($company)])->first();
    }

    /**
     * Resolve asset: session ID match first, then hostname match scoped to client.
     */
    private function resolveAsset(string $sessionId, ?string $hostname, ?Client $client): ?Asset
    {
        // 1. Match by session ID (already linked)
        $asset = Asset::where('screenconnect_session_id', $sessionId)->first();
        if ($asset) {
            return $asset;
        }

        // 2. Match by hostname, scoped to client
        if ($hostname && $client) {
            // Strip FQDN to short hostname for matching
            $shortHostname = explode('.', $hostname)[0];

            $asset = Asset::where('client_id', $client->id)
                ->whereRaw('LOWER(hostname) = ? OR LOWER(name) = ?', [
                    mb_strtolower($shortHostname),
                    mb_strtolower($shortHostname),
                ])
                ->first();

            if ($asset) {
                return $asset;
            }
        }

        // 3. Match by hostname without client scope (fallback)
        if ($hostname) {
            $shortHostname = explode('.', $hostname)[0];

            $asset = Asset::whereRaw('LOWER(hostname) = ? OR LOWER(name) = ?', [
                mb_strtolower($shortHostname),
                mb_strtolower($shortHostname),
            ])
                ->first();

            if ($asset) {
                return $asset;
            }
        }

        return null;
    }

    private function updateAssetFromPayload(Asset $asset, array $payload, string $eventType): void
    {
        // Online/offline from Connected/Disconnected
        if ($eventType === 'Connected') {
            $asset->screenconnect_online = true;
            $asset->screenconnect_last_seen_at = now();
        } elseif ($eventType === 'Disconnected') {
            $asset->screenconnect_online = false;
            $asset->screenconnect_last_seen_at = now();
        }

        // Agent version
        if (! empty($payload['guest_client_version'])) {
            $asset->screenconnect_client_version = $payload['guest_client_version'];
        }

        // Backfill-only fields: only update if currently null/empty
        if (empty($asset->os) && ! empty($payload['guest_os'])) {
            $asset->os = $payload['guest_os'];
        }

        if (empty($asset->cpu) && ! empty($payload['guest_processor'])) {
            $asset->cpu = $payload['guest_processor'];
        }

        if (empty($asset->ram_gb) && ! empty($payload['guest_ram_mb'])) {
            $ramMb = (int) $payload['guest_ram_mb'];
            if ($ramMb > 0) {
                $asset->ram_gb = round($ramMb / 1024, 2);
            }
        }

        // Always-update fields: these reflect real-time state
        if (! empty($payload['guest_logged_on_user'])) {
            $user = $payload['guest_logged_on_user'];
            if (! empty($payload['guest_logged_on_domain'])) {
                $user = $payload['guest_logged_on_domain'] . '\\' . $user;
            }
            $asset->last_user = $user;
        }

        if (! empty($payload['guest_network_address'])) {
            $asset->ip_address = $payload['guest_network_address'];
        }
    }

    private function logActivityEvent(Asset $asset, array $payload): void
    {
        ScreenConnectEvent::create([
            'asset_id' => $asset->id,
            'session_id' => $payload['session_id'],
            'event_type' => $payload['event_type'],
            'event_time' => $this->parseEventTime($payload['event_time'] ?? null),
            'host' => $payload['event_host'] ?? null,
            'data' => $payload['event_data'] ?? null,
            'participant' => $payload['connection_participant'] ?? null,
            'network_address' => $payload['connection_network_address'] ?? null,
        ]);
    }

    private function isDeviceEvent(string $eventType): bool
    {
        foreach (self::DEVICE_EVENTS as $prefix) {
            if (str_starts_with($eventType, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function isActivityEvent(string $eventType): bool
    {
        return in_array($eventType, self::ACTIVITY_EVENTS, true);
    }

    private function parseEventTime(?string $time): ?\Carbon\Carbon
    {
        if (! $time) {
            return null;
        }

        try {
            return \Carbon\Carbon::parse($time);
        } catch (\Throwable) {
            return null;
        }
    }
}
```

**Step 2: Commit**

```bash
git add app/Services/ScreenConnect/ScreenConnectSyncService.php
git commit -m "feat(screenconnect): add sync service with asset matching and activity logging"
```

---

### Task 8: Queued Job — ProcessScreenConnectWebhook

**Files:**
- Create: `app/Jobs/ProcessScreenConnectWebhook.php`

**Step 1: Create the job**

Follow the `ProcessLevelWebhook` pattern (`app/Jobs/ProcessLevelWebhook.php`):

```php
<?php

namespace App\Jobs;

use App\Models\ScreenConnectWebhook;
use App\Services\ScreenConnect\ScreenConnectSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessScreenConnectWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public array $backoff = [60, 300];

    public function __construct(
        public readonly int $webhookId,
    ) {}

    public function handle(ScreenConnectSyncService $sync): void
    {
        $webhook = ScreenConnectWebhook::find($this->webhookId);

        if (! $webhook || ! $webhook->isPending()) {
            return;
        }

        $result = $sync->processWebhook($webhook->payload);

        Log::debug('[ScreenConnect] Webhook processed', [
            'webhook_id' => $webhook->id,
            'event_type' => $webhook->event_type,
            'result' => $result,
        ]);

        // If result starts with "Skipped" or "No matching", mark as skipped
        if (str_starts_with($result, 'Skipped') || str_starts_with($result, 'No matching')) {
            $webhook->markSkipped($result);
        } else {
            $webhook->markProcessed();
        }
    }

    public function failed(\Throwable $e): void
    {
        $webhook = ScreenConnectWebhook::find($this->webhookId);

        if ($webhook) {
            $webhook->markFailed($e->getMessage());
            Log::warning('[ScreenConnect] Webhook processing failed', [
                'webhook_id' => $this->webhookId,
                'event_type' => $webhook->event_type,
                'session_id' => $webhook->session_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
```

**Step 2: Commit**

```bash
git add app/Jobs/ProcessScreenConnectWebhook.php
git commit -m "feat(screenconnect): add webhook processing job"
```

---

### Task 9: Settings UI — ScreenConnect Card on Integrations Page

**Files:**
- Modify: `app/Http/Controllers/Web/IntegrationsController.php`
- Modify: `resources/views/settings/integrations.blade.php`

**Step 1: Add data to the integrations controller**

In the `index()` method of `IntegrationsController`, add the ScreenConnect variables alongside other integrations. Find the existing pattern (e.g., `$levelHasApiKey`, `$levelEnabled`) and add:

```php
$screenconnectBaseUrl = Setting::getValue('screenconnect_base_url', '');
$screenconnectWebhookSecret = Setting::getValue('screenconnect_webhook_secret', '');
$screenconnectEnabled = Setting::getValue('screenconnect_enabled', '0') === '1';
$screenconnectConfigured = ! empty($screenconnectBaseUrl) && ! empty($screenconnectWebhookSecret);
```

Pass these to the view in the `return view(...)` call:

```php
'screenconnectBaseUrl' => $screenconnectBaseUrl,
'screenconnectWebhookSecret' => $screenconnectWebhookSecret,
'screenconnectEnabled' => $screenconnectEnabled,
'screenconnectConfigured' => $screenconnectConfigured,
```

**Step 2: Add the save route and method**

In `IntegrationsController`, add a new method:

```php
public function updateScreenConnect(Request $request)
{
    $validated = $request->validate([
        'base_url' => 'nullable|url|max:255',
        'generate_secret' => 'nullable|boolean',
    ]);

    if (! empty($validated['base_url'])) {
        Setting::setValue('screenconnect_base_url', rtrim($validated['base_url'], '/'));
    }

    if ($request->boolean('generate_secret')) {
        Setting::setValue('screenconnect_webhook_secret', \App\Support\ScreenConnectConfig::generateSecret());
    }

    return redirect()->route('settings.integrations')
        ->with('success', 'ScreenConnect settings saved.');
}
```

Also update the `toggle()` method's integration list to include `'screenconnect'` in the allowed integrations array/switch.

**Step 3: Add the route**

In `routes/web.php`, find the integrations route group and add:

```php
Route::post('settings/integrations/screenconnect', [IntegrationsController::class, 'updateScreenConnect'])
    ->name('settings.integrations.screenconnect.update');
```

**Step 4: Add the settings card to the Blade view**

In `resources/views/settings/integrations.blade.php`, add a ScreenConnect card in the **RMM tab** (after Level RMM card, before the closing `</div>{{-- /rmm tab --}}`). Follow the Level card pattern:

```blade
{{-- ScreenConnect Card --}}
<div class="card card-static shadow-sm mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <div>
            <i class="bi bi-display me-2"></i>ScreenConnect (ConnectWise Control)
        </div>
        @if($screenconnectConfigured && $screenconnectEnabled)
            <span class="badge bg-success">Active</span>
        @elseif($screenconnectConfigured)
            <span class="badge bg-warning text-dark">Configured (disabled)</span>
        @else
            <span class="badge bg-secondary">Not configured</span>
        @endif
    </div>
    <div class="card-body">
        <p class="text-muted small mb-3">
            Receive real-time device status, online/offline tracking, and activity audit from ScreenConnect.
            No API key needed — ScreenConnect pushes data via webhook automations.
        </p>

        <form method="POST" action="{{ route('settings.integrations.screenconnect.update') }}">
            @csrf

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="screenconnect_base_url" class="form-label">Instance URL</label>
                    <input type="url"
                           class="form-control"
                           id="screenconnect_base_url"
                           name="base_url"
                           value="{{ $screenconnectBaseUrl }}"
                           placeholder="https://yourcompany.screenconnect.com">
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Webhook Secret</label>
                    @if($screenconnectWebhookSecret)
                        <div class="input-group">
                            <input type="text" class="form-control font-monospace" value="{{ $screenconnectWebhookSecret }}" readonly>
                            <button type="button" class="btn btn-outline-secondary"
                                    onclick="navigator.clipboard.writeText('{{ $screenconnectWebhookSecret }}')">
                                <i class="bi bi-clipboard"></i>
                            </button>
                        </div>
                    @else
                        <p class="text-muted small">Click Save to generate a webhook secret.</p>
                    @endif
                    <input type="hidden" name="generate_secret" value="{{ $screenconnectWebhookSecret ? '0' : '1' }}">
                </div>
            </div>

            <button type="submit" class="btn btn-primary btn-sm">
                <i class="bi bi-check-lg me-1"></i>{{ $screenconnectWebhookSecret ? 'Save' : 'Save & Generate Secret' }}
            </button>

            @if($screenconnectWebhookSecret && !$screenconnectConfigured)
                <button type="submit" name="generate_secret" value="1" class="btn btn-outline-secondary btn-sm ms-2">
                    <i class="bi bi-arrow-clockwise me-1"></i>Regenerate Secret
                </button>
            @endif
        </form>

        @if($screenconnectConfigured)
        <div class="border-top pt-3 mt-3">
            <h6 class="small fw-bold">Webhook URL</h6>
            <p class="text-muted small mb-2">
                Use this URL when configuring ScreenConnect automations:
            </p>
            <div class="input-group mb-3">
                <input type="text" class="form-control font-monospace small"
                       value="{{ url('/api/webhooks/screenconnect/' . $screenconnectWebhookSecret) }}" readonly>
                <button type="button" class="btn btn-outline-secondary"
                        onclick="navigator.clipboard.writeText('{{ url('/api/webhooks/screenconnect/' . $screenconnectWebhookSecret) }}')">
                    <i class="bi bi-clipboard"></i>
                </button>
            </div>

            <h6 class="small fw-bold">ScreenConnect Setup</h6>
            <p class="text-muted small mb-1">
                Create two session automations in ScreenConnect (Admin &rarr; Automations):
            </p>
            <ol class="small mb-0">
                <li>Create a <strong>Session Event</strong> trigger.</li>
                <li>Filter: <code>Session.SessionType = 'Access'</code></li>
                <li>Select event types: <strong>Connected</strong>, <strong>Disconnected</strong>, <strong>ModifiedGuestInfo*</strong>,
                    <strong>RanCommand</strong>, <strong>SentMessage</strong>, <strong>SentFiles</strong>, <strong>CopiedFiles</strong>,
                    <strong>RequestedElevation</strong>, and any others you want to audit.</li>
                <li>Action: <strong>Send HTTP request</strong> — POST to the webhook URL above.</li>
                <li>Set Content-Type to <code>application/json</code>.</li>
                <li>Paste this JSON template as the body:
                    <details class="mt-1">
                        <summary class="text-primary" style="cursor:pointer;">Show JSON template</summary>
                        <pre class="bg-light p-2 mt-1 rounded small" style="white-space: pre-wrap;"><code>{
  "session_id": "#Session.SessionID#",
  "session_name": "#Session.Name#",
  "session_type": "#Session.SessionType#",
  "company": "#Session.CustomProperty1#",
  "guest_machine_name": "#Session.GuestMachineName#",
  "guest_machine_domain": "#Session.GuestMachineDomain#",
  "guest_os": "#Session.GuestOperatingSystemName#",
  "guest_os_version": "#Session.GuestOperatingSystemVersion#",
  "guest_processor": "#Session.GuestProcessorName#",
  "guest_ram_mb": "#Session.GuestSystemMemoryTotalMegabytes#",
  "guest_network_address": "#Session.GuestNetworkAddress#",
  "guest_logged_on_user": "#Session.GuestLoggedOnUserName#",
  "guest_logged_on_domain": "#Session.GuestLoggedOnUserDomain#",
  "guest_last_activity": "#Session.GuestLastActivityTime#",
  "guest_client_version": "#Session.GuestClientVersion#",
  "guest_connected_count": "#Session.GuestConnectedCount#",
  "host_connected_count": "#Session.HostConnectedCount#",
  "event_type": "#Event.EventType#",
  "event_time": "#Event.Time#",
  "event_data": "#Event.Data#",
  "event_host": "#Event.Host#",
  "connection_participant": "#Connection.ParticipantName#",
  "connection_network_address": "#Connection.NetworkAddress#"
}</code></pre>
                    </details>
                </li>
            </ol>
        </div>

        <div class="border-top pt-3 mt-3">
            <form method="POST" action="{{ route('settings.integrations.toggle') }}">
                @csrf
                <input type="hidden" name="integration" value="screenconnect">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="enabled" value="1"
                           id="screenconnect_enabled" {{ $screenconnectEnabled ? 'checked' : '' }} onchange="this.form.submit()">
                    <label class="form-check-label" for="screenconnect_enabled">Integration enabled</label>
                </div>
            </form>
        </div>
        @endif
    </div>
</div>
```

**Step 5: Commit**

```bash
git add app/Http/Controllers/Web/IntegrationsController.php resources/views/settings/integrations.blade.php routes/web.php
git commit -m "feat(screenconnect): add settings UI with webhook URL and setup instructions"
```

---

### Task 10: License Counting Command

**Files:**
- Create: `app/Console/Commands/ScreenConnectCountLicenses.php`
- Modify: `routes/console.php` — add schedule entry

**Step 1: Create the command**

Count distinct `screenconnect_session_id` per client from the assets table. Upsert into license_types/licenses.

```php
<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Models\License;
use App\Models\LicenseType;
use App\Support\ScreenConnectConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ScreenConnectCountLicenses extends Command
{
    protected $signature = 'screenconnect:count-licenses';
    protected $description = 'Count ScreenConnect Access agents per client and update license records';

    public function handle(): int
    {
        if (! ScreenConnectConfig::isConfigured()) {
            $this->error('ScreenConnect is not configured.');
            return self::FAILURE;
        }

        $licenseType = LicenseType::updateOrCreate(
            ['vendor' => 'screenconnect', 'vendor_sku_id' => 'access_agents'],
            ['name' => 'ScreenConnect Access Agents', 'is_active' => true],
        );

        // Count agents per client from assets table
        $counts = Asset::whereNotNull('screenconnect_session_id')
            ->whereNotNull('client_id')
            ->where('is_active', true)
            ->select('client_id', DB::raw('COUNT(*) as agent_count'))
            ->groupBy('client_id')
            ->pluck('agent_count', 'client_id');

        $updated = 0;
        foreach ($counts as $clientId => $count) {
            License::updateOrCreate(
                ['license_type_id' => $licenseType->id, 'client_id' => $clientId],
                ['quantity' => $count, 'status' => 'active', 'synced_at' => now()],
            );
            $updated++;
        }

        // Deactivate licenses for clients with no agents
        License::where('license_type_id', $licenseType->id)
            ->whereNotIn('client_id', $counts->keys())
            ->where('status', 'active')
            ->update(['quantity' => 0, 'status' => 'suspended', 'synced_at' => now()]);

        $this->info("Updated {$updated} client license counts.");

        Log::info('[ScreenConnect] License count updated', [
            'clients' => $updated,
            'total_agents' => $counts->sum(),
        ]);

        return self::SUCCESS;
    }
}
```

**Step 2: Add to scheduler**

In `routes/console.php`, add:

```php
Schedule::command('screenconnect:count-licenses')
    ->dailyAt('05:30')
    ->withoutOverlapping()
    ->runInBackground()
    ->when(fn () => \App\Support\ScreenConnectConfig::isConfigured());
```

**Step 3: Commit**

```bash
git add app/Console/Commands/ScreenConnectCountLicenses.php routes/console.php
git commit -m "feat(screenconnect): add license counting command with daily schedule"
```

---

### Task 11: Asset Detail — Deep Link and ScreenConnect Card

**Files:**
- Modify: `resources/views/assets/show.blade.php`

**Step 1: Add ScreenConnect deep link to the RMM row**

Find the RMM `<tr>` in the asset detail view (around line 218). After the Level link block and before the "no RMM" fallback, add:

```blade
@if($asset->screenconnect_session_id)
    @if($asset->ninja_id || $asset->level_id)
        <span class="text-muted mx-1">|</span>
    @endif
    @php $scUrl = \App\Support\ScreenConnectConfig::sessionUrl($asset->screenconnect_session_id); @endphp
    @if($scUrl)
        <a href="{{ $scUrl }}" target="_blank" class="text-decoration-none">
            <i class="bi bi-display me-1"></i>ScreenConnect
        </a>
    @else
        ScreenConnect
    @endif
@endif
```

Update the "no RMM" fallback condition to include screenconnect:

```blade
@if(!$asset->ninja_id && !$asset->level_id && !$asset->screenconnect_session_id)
```

**Step 2: Add ScreenConnect status card**

Add a new card section in the asset detail view (after existing vendor cards like Control D, Zorus). Follow the Control D card pattern:

```blade
@if($asset->screenconnect_session_id)
<div class="card shadow-sm mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <div><i class="bi bi-display me-2"></i>ScreenConnect</div>
        @if($asset->screenconnect_online)
            <span class="badge bg-success">Online</span>
        @elseif($asset->screenconnect_online === false)
            <span class="badge bg-secondary">Offline</span>
        @else
            <span class="badge bg-light text-dark">Unknown</span>
        @endif
    </div>
    <div class="card-body">
        @if($asset->screenconnect_synced_at?->lt(now()->subDays(7)))
            <div class="alert alert-warning py-1 px-2 small mb-2">
                <i class="bi bi-exclamation-triangle me-1"></i>
                No events received since {{ $asset->screenconnect_synced_at->diffForHumans() }}
            </div>
        @endif
        <table class="table table-borderless table-sm mb-0">
            <tbody>
                <tr>
                    <th class="text-muted" style="width: 140px;">Session ID</th>
                    <td class="font-monospace small">{{ Str::limit($asset->screenconnect_session_id, 20) }}</td>
                </tr>
                @if($asset->screenconnect_client_version)
                <tr>
                    <th class="text-muted">Agent Version</th>
                    <td>{{ $asset->screenconnect_client_version }}</td>
                </tr>
                @endif
                <tr>
                    <th class="text-muted">Last Seen</th>
                    <td>{{ $asset->screenconnect_last_seen_at?->diffForHumans() ?? '-' }}</td>
                </tr>
                <tr>
                    <th class="text-muted">Last Synced</th>
                    <td>{{ $asset->screenconnect_synced_at?->diffForHumans() ?? '-' }}</td>
                </tr>
            </tbody>
        </table>

        @php
            $scUrl = \App\Support\ScreenConnectConfig::sessionUrl($asset->screenconnect_session_id);
            $recentEvents = \App\Models\ScreenConnectEvent::where('asset_id', $asset->id)
                ->orderByDesc('event_time')
                ->limit(10)
                ->get();
        @endphp

        @if($scUrl)
        <div class="mt-2 pt-2 border-top">
            <a href="{{ $scUrl }}" target="_blank" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-box-arrow-up-right me-1"></i>Open in ScreenConnect
            </a>
        </div>
        @endif

        @if($recentEvents->isNotEmpty())
        <div class="mt-3 pt-3 border-top">
            <h6 class="small fw-bold mb-2">Recent Activity</h6>
            <div class="small" style="max-height: 200px; overflow-y: auto;">
                @foreach($recentEvents as $evt)
                    <div class="d-flex justify-content-between py-1 {{ !$loop->last ? 'border-bottom' : '' }}">
                        <div>
                            <span class="badge bg-light text-dark">{{ $evt->event_type }}</span>
                            @if($evt->host)
                                <span class="text-muted">by {{ $evt->host }}</span>
                            @endif
                            @if($evt->data)
                                <div class="text-muted text-truncate" style="max-width: 300px;"
                                     title="{{ $evt->data }}">{{ $evt->data }}</div>
                            @endif
                        </div>
                        <span class="text-muted text-nowrap ms-2"
                              title="{{ $evt->event_time?->toAppTz()->format('Y-m-d H:i T') }}">
                            {{ $evt->event_time?->diffForHumans() }}
                        </span>
                    </div>
                @endforeach
            </div>
        </div>
        @endif
    </div>
</div>
@endif
```

**Step 3: Commit**

```bash
git add resources/views/assets/show.blade.php
git commit -m "feat(screenconnect): add deep link and status card to asset detail"
```

---

### Task 12: Update INSTALL.md

**Files:**
- Modify: `docs/INSTALL.md`

**Step 1: Add ScreenConnect section**

Add a ScreenConnect subsection under integrations in `docs/INSTALL.md`:

```markdown
### ScreenConnect (ConnectWise Control)

Webhook-based integration for real-time device status, activity audit, and remote access deep links. No API key required.

**Settings:**
- `screenconnect_base_url` — Your ScreenConnect instance URL (e.g., `https://yourcompany.screenconnect.com`)
- `screenconnect_webhook_secret` — Auto-generated; used in the webhook URL path
- `screenconnect_enabled` — Toggle integration on/off

**Setup:**
1. Go to Settings → Integrations → ScreenConnect
2. Enter your instance URL and save to generate a webhook secret
3. Copy the displayed webhook URL
4. In ScreenConnect Admin → Automations, create a Session Event automation
5. Configure it to POST JSON to the webhook URL (template provided in settings UI)
6. Enable the integration toggle

**Scheduled commands:**
- `screenconnect:count-licenses` — daily at 05:30, counts Access agents per client
```

**Step 2: Commit**

```bash
git add docs/INSTALL.md
git commit -m "docs: add ScreenConnect integration to INSTALL.md"
```

---

### Task 13: Deploy and Test

**Step 1: Deploy**

Run `/deploy` to push and deploy.

**Step 2: Configure in production**

1. Go to Settings → Integrations → ScreenConnect
2. Enter `https://soundit.screenconnect.com` as the base URL
3. Save to generate webhook secret
4. Copy the webhook URL
5. Create the automation in ScreenConnect admin
6. Enable the integration

**Step 3: Verify**

After configuring the ScreenConnect automation, trigger a test event (connect/disconnect from a machine). Check:
- `screenconnect_webhooks` table has a new row
- The webhook was processed (status = 'processed')
- The matching asset has `screenconnect_session_id` populated
- Asset detail page shows the ScreenConnect card with online/offline status

Run: `php artisan screenconnect:count-licenses` to verify license counting works.
