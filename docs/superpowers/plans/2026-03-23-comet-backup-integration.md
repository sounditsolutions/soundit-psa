# Comet Backup Integration Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Integrate Comet Backup into Sound PSA — device sync, license counts, backup job history, alert webhooks for failed backups, and AI triage tools.

**Architecture:** Follows the exact same integration pattern as Tactical RMM and NinjaRMM. CometConfig (static helper) → CometClient (PHP SDK wrapper) → CometBackupSyncService (daily sync) + CometAlertService (webhook-driven alerts) + CometJobService (on-demand job history) + triage tools. All backup storage fields are vendor-agnostic (reused from Ninja).

**Tech Stack:** Laravel 12, PHP 8.3, `cometbackup/comet-php-sdk` (Composer package), Bootstrap 5.3

**Spec:** `docs/superpowers/specs/2026-03-23-comet-backup-integration-design.md`

---

## Chunk 1: Foundation

### Task 1: Install Comet PHP SDK

**Files:**
- Modify: `composer.json`

- [ ] **Step 1: Install the SDK**

```bash
cd ~/repos/soundit-psa && composer require cometbackup/comet-php-sdk
```

- [ ] **Step 2: Verify installation**

```bash
php -r "require 'vendor/autoload.php'; echo class_exists('\Comet\Server') ? 'OK' : 'FAIL';"
```

Expected: `OK`

- [ ] **Step 3: Commit**

```bash
git add composer.json composer.lock
git commit -m "Add cometbackup/comet-php-sdk dependency"
```

---

### Task 2: Database Migrations

**Files:**
- Create: `database/migrations/2026_03_23_000001_add_comet_fields_to_assets.php`
- Create: `database/migrations/2026_03_23_000002_add_comet_org_id_to_clients.php`

- [ ] **Step 1: Create asset columns migration**

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
            $table->string('comet_username')->nullable()->after('backup_synced_at');
            $table->string('comet_device_id')->nullable()->unique()->after('comet_username');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn(['comet_username', 'comet_device_id']);
        });
    }
};
```

- [ ] **Step 2: Create client mapping migration**

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
            $table->string('comet_org_id')->nullable()->after('tactical_site_id');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('comet_org_id');
        });
    }
};
```

- [ ] **Step 3: Run migrations**

```bash
php artisan migrate
```

- [ ] **Step 4: Update Asset model**

Add `comet_username` and `comet_device_id` to the `$fillable` array in `app/Models/Asset.php`. Find the existing backup fields and add after them:

```php
'comet_username',
'comet_device_id',
```

No casts needed — both are plain strings.

- [ ] **Step 5: Update Client model**

Add `comet_org_id` to the `$fillable` array in `app/Models/Client.php`. Find the other vendor mapping IDs (like `tactical_site_id`, `ninja_org_id`) and add alongside them:

```php
'comet_org_id',
```

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_03_23_*.php app/Models/Asset.php app/Models/Client.php
git commit -m "Add Comet backup fields to assets and comet_org_id to clients"
```

---

### Task 3: CometConfig

**Files:**
- Create: `app/Support/CometConfig.php`

Follow the exact pattern from `app/Support/TacticalConfig.php`.

- [ ] **Step 1: Create CometConfig**

```php
<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Str;

class CometConfig
{
    private static array $encryptedFields = [
        'comet_admin_user',
        'comet_admin_password',
        'comet_webhook_key',
    ];

    public static function get(string $key): ?string
    {
        return match (true) {
            in_array($key, self::$encryptedFields) => Setting::getEncrypted($key),
            default => Setting::getValue($key),
        };
    }

    public static function isConfigured(): bool
    {
        return self::get('comet_server_url')
            && self::get('comet_admin_user')
            && self::get('comet_admin_password');
    }

    public static function isEnabled(): bool
    {
        return self::isConfigured();
    }

    public static function serverUrl(): ?string
    {
        return self::get('comet_server_url');
    }

    public static function alertsEnabled(): bool
    {
        if (!self::isConfigured()) {
            return false;
        }

        return (bool) (self::get('comet_alert_enabled') ?? true);
    }

    public static function generateWebhookKey(): string
    {
        $key = Str::random(64);
        Setting::setEncrypted('comet_webhook_key', $key);

        return $key;
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Support/CometConfig.php
git commit -m "Add CometConfig static helper"
```

---

### Task 4: CometClient and Exception

**Files:**
- Create: `app/Services/Comet/CometClientException.php`
- Create: `app/Services/Comet/CometClient.php`

- [ ] **Step 1: Create CometClientException**

```php
<?php

namespace App\Services\Comet;

class CometClientException extends \RuntimeException
{
}
```

- [ ] **Step 2: Create CometClient**

This wraps the `cometbackup/comet-php-sdk` package. The SDK's `\Comet\Server` class handles authentication (admin username/password as POST params) and JSON response parsing internally.

```php
<?php

namespace App\Services\Comet;

use App\Support\CometConfig;
use Illuminate\Support\Facades\Log;

class CometClient
{
    private \Comet\Server $server;

    public function __construct()
    {
        $url = rtrim(CometConfig::serverUrl(), '/');
        $user = CometConfig::get('comet_admin_user');
        $password = CometConfig::get('comet_admin_password');

        $this->server = new \Comet\Server($url, $user, $password);
    }

    /**
     * Get all user profiles with device details and storage stats.
     *
     * @return array<string, \Comet\UserProfileConfig>
     */
    public function listUsersFull(): array
    {
        try {
            return $this->server->AdminListUsersFull();
        } catch (\Exception $e) {
            Log::error("[Comet] listUsersFull failed: {$e->getMessage()}");
            throw new CometClientException("Comet API error: {$e->getMessage()}", $e->getCode(), $e);
        }
    }

    /**
     * Get a single user's profile.
     */
    public function getUserProfile(string $username): \Comet\UserProfileConfig
    {
        try {
            return $this->server->AdminGetUserProfile($username);
        } catch (\Exception $e) {
            Log::error("[Comet] getUserProfile({$username}) failed: {$e->getMessage()}");
            throw new CometClientException("Comet API error: {$e->getMessage()}", $e->getCode(), $e);
        }
    }

    /**
     * Get backup jobs for a user.
     *
     * @return \Comet\BackupJobDetail[]
     */
    public function getJobsForUser(string $username): array
    {
        try {
            return $this->server->AdminGetJobsForUser($username);
        } catch (\Exception $e) {
            Log::error("[Comet] getJobsForUser({$username}) failed: {$e->getMessage()}");
            throw new CometClientException("Comet API error: {$e->getMessage()}", $e->getCode(), $e);
        }
    }

    /**
     * Get backup jobs in a date range.
     *
     * @return \Comet\BackupJobDetail[]
     */
    public function getJobsForDateRange(int $startTimestamp, int $endTimestamp): array
    {
        try {
            return $this->server->AdminGetJobsForDateRange($startTimestamp, $endTimestamp);
        } catch (\Exception $e) {
            Log::error("[Comet] getJobsForDateRange failed: {$e->getMessage()}");
            throw new CometClientException("Comet API error: {$e->getMessage()}", $e->getCode(), $e);
        }
    }

    /**
     * List live connected devices.
     *
     * @return array<string, \Comet\LiveUserConnection[]>
     */
    public function listActive(): array
    {
        try {
            return $this->server->AdminDispatcherListActive();
        } catch (\Exception $e) {
            Log::error("[Comet] listActive failed: {$e->getMessage()}");
            throw new CometClientException("Comet API error: {$e->getMessage()}", $e->getCode(), $e);
        }
    }

    /**
     * List tenant organizations.
     *
     * @return array<string, \Comet\Organization>
     */
    public function getOrganizations(): array
    {
        try {
            return $this->server->AdminOrganizationList();
        } catch (\Exception $e) {
            Log::error("[Comet] getOrganizations failed: {$e->getMessage()}");
            throw new CometClientException("Comet API error: {$e->getMessage()}", $e->getCode(), $e);
        }
    }

    /**
     * Test connection by fetching server version.
     */
    public function isHealthy(): bool
    {
        try {
            $this->server->AdminMetaVersion();
            return true;
        } catch (\Exception) {
            return false;
        }
    }
}
```

- [ ] **Step 3: Commit**

```bash
git add app/Services/Comet/CometClientException.php app/Services/Comet/CometClient.php
git commit -m "Add CometClient SDK wrapper and exception"
```

---

### Task 5: TicketSource Enum

**Files:**
- Modify: `app/Enums/TicketSource.php`

- [ ] **Step 1: Add CometBackup case**

Add a new case to the `TicketSource` enum. Find the existing cases (like `TacticalRmm = 'tactical_rmm'`) and add after:

```php
case CometBackup = 'comet_backup';
```

Then add the label in the `label()` method:

```php
self::CometBackup => 'Comet Backup',
```

- [ ] **Step 2: Commit**

```bash
git add app/Enums/TicketSource.php
git commit -m "Add CometBackup ticket source"
```

---

## Chunk 2: Sync Service & Command

### Task 6: CometBackupSyncService

**Files:**
- Create: `app/Services/Comet/CometBackupSyncService.php`

This follows `app/Services/Ninja/NinjaBackupSyncService.php` exactly.

- [ ] **Step 1: Create CometBackupSyncService**

```php
<?php

namespace App\Services\Comet;

use App\Models\Asset;
use App\Models\Client;
use App\Models\License;
use App\Models\LicenseType;
use App\Services\SyncResult;
use Illuminate\Support\Facades\Log;

class CometBackupSyncService
{
    public function __construct(
        private readonly CometClient $client,
    ) {}

    /**
     * Sync backup usage from Comet for all mapped clients.
     */
    public function syncBackupUsage(?int $clientId = null, bool $dryRun = false): SyncResult
    {
        $result = new SyncResult();

        // Load mapped clients
        $query = Client::whereNotNull('comet_org_id');
        if ($clientId) {
            $query->where('id', $clientId);
        }
        $mappedClients = $query->get();

        if ($mappedClients->isEmpty()) {
            Log::info('[Comet Sync] No clients mapped to Comet organizations');
            return $result;
        }

        // Build org_id -> client lookup
        $orgToClient = $mappedClients->keyBy('comet_org_id');

        try {
            $allUsers = $this->client->listUsersFull();
        } catch (CometClientException $e) {
            $result->recordError("Failed to fetch users: {$e->getMessage()}");
            return $result;
        }

        // Track seen device IDs for stale cleanup
        $seenDeviceIds = [];

        // Per-client backup stats for license counting
        $clientStats = []; // client_id => ['servers' => N, 'workstations' => N, 'cloud_bytes' => N]

        foreach ($allUsers as $username => $userProfile) {
            // Determine which client this user belongs to
            $orgId = $userProfile->Organization ?? null;
            if (!$orgId || !isset($orgToClient[$orgId])) {
                continue;
            }

            $client = $orgToClient[$orgId];

            // Get devices from user profile
            $devices = $userProfile->Devices ?? [];
            foreach ($devices as $deviceId => $deviceConfig) {
                $hostname = $deviceConfig->FriendlyName ?? null;
                if (!$hostname) {
                    continue;
                }

                // Match asset by hostname (case-insensitive, scoped to client)
                $asset = Asset::where('client_id', $client->id)
                    ->whereRaw('LOWER(hostname) = ?', [strtolower($hostname)])
                    ->first();

                if (!$asset) {
                    Log::debug("[Comet Sync] No asset match for hostname '{$hostname}' (client: {$client->name})");
                    continue;
                }

                $seenDeviceIds[] = $deviceId;

                // Calculate storage from destination (storage vault) statistics
                // Note: Comet storage vaults are per-user, not per-device. If a user has
                // multiple devices, each asset gets the user's total storage. This is
                // acceptable since MSP users typically have one device per Comet account.
                $cloudBytes = 0;
                $localBytes = 0;
                $destinations = $userProfile->Destinations ?? [];
                foreach ($destinations as $destId => $destConfig) {
                    $stats = $destConfig->Statistics ?? null;
                    if ($stats) {
                        // ClientProvidedSize is the total protected data size
                        $size = $stats->ClientProvidedSize ?? 0;
                        // Determine if cloud or local based on destination type
                        $destType = $destConfig->DestinationType ?? 0;
                        if (in_array($destType, [1000, 1003, 1005, 1008, 1009])) {
                            // S3, Comet Storage, Azure, B2, Storj = cloud
                            $cloudBytes += $size;
                        } else {
                            // Local copy, SFTP, FTP, SMB, WebDAV = local
                            $localBytes += $size;
                        }
                    }
                }

                if (!$dryRun) {
                    $asset->update([
                        'comet_username' => $username,
                        'comet_device_id' => $deviceId,
                        'backup_cloud_bytes' => $cloudBytes ?: null,
                        'backup_local_bytes' => $localBytes ?: null,
                        'backup_revisions_bytes' => null,
                        'backup_synced_at' => now(),
                    ]);
                }

                $result->updated++;

                // Track stats for license counting
                if ($cloudBytes > 0 || $localBytes > 0) {
                    if (!isset($clientStats[$client->id])) {
                        $clientStats[$client->id] = [
                            'servers' => 0,
                            'workstations' => 0,
                            'cloud_bytes' => 0,
                            'comet_org_id' => $client->comet_org_id,
                        ];
                    }
                    if (strtolower($asset->asset_type ?? '') === 'server') {
                        $clientStats[$client->id]['servers']++;
                    } else {
                        $clientStats[$client->id]['workstations']++;
                    }
                    $clientStats[$client->id]['cloud_bytes'] += $cloudBytes;
                }
            }
        }

        // Clear stale backup data for assets that were previously linked but no longer in Comet
        if (!$dryRun && !empty($seenDeviceIds)) {
            $staleQuery = Asset::whereNotNull('comet_device_id')
                ->whereNotIn('comet_device_id', $seenDeviceIds);
            // When syncing a single client, only clean up that client's assets
            if ($clientId) {
                $staleQuery->where('client_id', $clientId);
            }
            $staleCount = $staleQuery->update([
                'comet_username' => null,
                'comet_device_id' => null,
                'backup_cloud_bytes' => null,
                'backup_local_bytes' => null,
                'backup_revisions_bytes' => null,
                'backup_synced_at' => null,
            ]);
            $result->deactivated += $staleCount;
        }

        // Sync license counts
        if (!$dryRun) {
            $this->syncLicenseCounts($clientStats, $mappedClients, $result);
        }

        Log::info("[Comet Sync] {$result->summary()}");

        return $result;
    }

    private function syncLicenseCounts(array $clientStats, $mappedClients, SyncResult $result): void
    {
        // Ensure license types exist
        $workstationType = LicenseType::updateOrCreate(
            ['vendor' => 'comet', 'vendor_sku_id' => 'backup_workstation'],
            ['name' => 'Comet Backup — Workstation']
        );

        $serverType = LicenseType::updateOrCreate(
            ['vendor' => 'comet', 'vendor_sku_id' => 'backup_server'],
            ['name' => 'Comet Backup — Server']
        );

        $usageType = LicenseType::updateOrCreate(
            ['vendor' => 'comet', 'vendor_sku_id' => 'cloud_usage_gb'],
            ['name' => 'Comet Backup Usage (GB)']
        );

        foreach ($mappedClients as $client) {
            $stats = $clientStats[$client->id] ?? null;

            $wsQty = $stats['workstations'] ?? 0;
            $srvQty = $stats['servers'] ?? 0;
            $cloudGb = $stats ? (int) round(($stats['cloud_bytes'] ?? 0) / (1024 ** 3)) : 0;

            License::updateOrCreate(
                ['client_id' => $client->id, 'license_type_id' => $workstationType->id, 'vendor_ref' => $client->comet_org_id],
                ['quantity' => $wsQty, 'status' => $wsQty > 0 ? 'active' : 'suspended', 'synced_at' => now()]
            );

            License::updateOrCreate(
                ['client_id' => $client->id, 'license_type_id' => $serverType->id, 'vendor_ref' => $client->comet_org_id],
                ['quantity' => $srvQty, 'status' => $srvQty > 0 ? 'active' : 'suspended', 'synced_at' => now()]
            );

            License::updateOrCreate(
                ['client_id' => $client->id, 'license_type_id' => $usageType->id, 'vendor_ref' => $client->comet_org_id],
                ['quantity' => $cloudGb, 'status' => $cloudGb > 0 ? 'active' : 'suspended', 'synced_at' => now()]
            );
        }

        // Deactivate orphaned licenses for clients that lost their mapping
        License::deactivateOrphaned('comet', 'comet_org_id');
    }
}
```

**Note on storage calculation:** The Comet PHP SDK returns `UserProfileConfig` objects. Storage vault stats come from the `Destinations` property. Destination types 1000 (S3), 1003 (Comet Storage), 1005 (Azure), 1008 (B2), 1009 (Storj) are cloud; others (1001 SFTP, 1002 local, 1004 FTP, 1010 WebDAV, 1011 SMB) are local. The `ClientProvidedSize` in `Statistics` gives total protected data size. During implementation, verify the SDK's actual property names by checking `vendor/cometbackup/comet-php-sdk/src/` — the classes are well-documented.

- [ ] **Step 2: Commit**

```bash
git add app/Services/Comet/CometBackupSyncService.php
git commit -m "Add CometBackupSyncService for device and license sync"
```

---

### Task 7: Artisan Command and Schedule

**Files:**
- Create: `app/Console/Commands/CometSyncBackup.php`
- Modify: `routes/console.php`

- [ ] **Step 1: Create CometSyncBackup command**

Follow the pattern from `app/Console/Commands/TacticalSyncDevices.php`.

```php
<?php

namespace App\Console\Commands;

use App\Services\Comet\CometBackupSyncService;
use App\Services\Comet\CometClient;
use App\Support\CometConfig;
use Illuminate\Console\Command;

class CometSyncBackup extends Command
{
    protected $signature = 'comet:sync-backup
        {--client= : Sync a single client by ID}
        {--dry-run : Log changes without writing to database}';

    protected $description = 'Sync backup usage and license counts from Comet Backup';

    public function handle(): int
    {
        if (!CometConfig::isConfigured()) {
            $this->error('Comet Backup is not configured. Set server URL and credentials in Settings > Integrations.');
            return self::FAILURE;
        }

        $clientId = $this->option('client') ? (int) $this->option('client') : null;
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN — no changes will be written');
        }

        $service = new CometBackupSyncService(new CometClient());
        $result = $service->syncBackupUsage($clientId, $dryRun);

        $this->info("Done: {$result->summary()}");

        foreach ($result->errorMessages as $error) {
            $this->warn("  Error: {$error}");
        }

        return $result->errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
```

- [ ] **Step 2: Add schedule entry**

In `routes/console.php`, add after the existing sync commands (find a spot near the other daily sync jobs around the 05:xx block):

```php
Schedule::command('comet:sync-backup')
    ->dailyAt('05:40')
    ->withoutOverlapping()
    ->runInBackground()
    ->when(fn () => \App\Support\CometConfig::isConfigured()
        && \App\Models\Client::whereNotNull('comet_org_id')->exists());
```

- [ ] **Step 3: Verify command registers**

```bash
php artisan list | grep comet
```

Expected: `comet:sync-backup`

- [ ] **Step 4: Commit**

```bash
git add app/Console/Commands/CometSyncBackup.php routes/console.php
git commit -m "Add comet:sync-backup command with daily schedule at 05:40"
```

---

## Chunk 3: Alert Webhooks

### Task 8: VerifyCometWebhookKey Middleware

**Files:**
- Create: `app/Http/Middleware/VerifyCometWebhookKey.php`

Follow the pattern from `app/Http/Middleware/VerifyTacticalWebhookKey.php`.

- [ ] **Step 1: Create middleware**

```php
<?php

namespace App\Http\Middleware;

use App\Support\CometConfig;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class VerifyCometWebhookKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $storedKey = CometConfig::get('comet_webhook_key');

        if (!$storedKey) {
            Log::warning('[Comet Webhook] No webhook key configured');
            return response()->json(['error' => 'Webhook not configured'], 401);
        }

        // Accept Bearer token or X-Webhook-Key header
        $providedKey = null;

        $authHeader = $request->header('Authorization', '');
        if (str_starts_with($authHeader, 'Bearer ')) {
            $providedKey = substr($authHeader, 7);
        }

        if (!$providedKey) {
            $providedKey = $request->header('X-Webhook-Key');
        }

        if (!$providedKey || !hash_equals($storedKey, $providedKey)) {
            Log::warning('[Comet Webhook] Invalid webhook key', [
                'ip' => $request->ip(),
            ]);
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Http/Middleware/VerifyCometWebhookKey.php
git commit -m "Add VerifyCometWebhookKey middleware"
```

---

### Task 9: CometAlertService

**Files:**
- Create: `app/Services/Comet/CometAlertService.php`

Follow the pattern from `app/Services/Tactical/TacticalAlertService.php`.

- [ ] **Step 1: Create CometAlertService**

```php
<?php

namespace App\Services\Comet;

use App\Enums\TicketPriority;
use App\Enums\TicketSource;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Models\Asset;
use App\Models\Ticket;
use App\Services\TicketService;
use App\Support\CometConfig;
use Illuminate\Support\Facades\Log;

class CometAlertService
{
    // Comet job status codes
    private const STATUS_SUCCESS = 5000;
    private const STATUS_RUNNING = 6001;
    private const STATUS_WARNING = 7001;
    private const STATUS_ERROR = 7002;
    private const STATUS_CANCELLED = 7005;

    // Comet job classification codes
    private const CLASS_BACKUP = 4;
    private const CLASS_RESTORE = 5;
    private const CLASS_RETENTION = 7;

    public function __construct(
        private readonly TicketService $ticketService,
    ) {}

    /**
     * Handle a backup job failure from Comet webhook.
     */
    public function handleJobFailure(array $data): ?Ticket
    {
        if (!CometConfig::alertsEnabled()) {
            Log::debug('[Comet Alert] Alerts disabled, ignoring');
            return null;
        }

        $username = $data['Username'] ?? null;
        $deviceId = $data['DeviceID'] ?? null;
        $status = $data['Status'] ?? null;
        $classification = $data['Classification'] ?? null;
        $fileErrors = $data['FileErrors'] ?? null;
        $startTime = $data['StartTime'] ?? null;
        $endTime = $data['EndTime'] ?? null;
        $totalSize = $data['TotalSize'] ?? null;

        // Only alert on errors (not warnings or cancellations)
        if ($status !== self::STATUS_ERROR) {
            Log::debug('[Comet Alert] Non-error status, ignoring', ['status' => $status]);
            return null;
        }

        // Only alert on backup jobs (not restore or retention)
        if ($classification !== self::CLASS_BACKUP) {
            Log::debug('[Comet Alert] Non-backup classification, ignoring', ['classification' => $classification]);
            return null;
        }

        // Resolve asset
        $asset = $deviceId ? Asset::where('comet_device_id', $deviceId)->first() : null;
        $clientId = $asset?->client_id;

        if (!$clientId) {
            Log::info('[Comet Alert] No client match for device', [
                'username' => $username,
                'device_id' => $deviceId,
            ]);
            return null;
        }

        $hostname = $asset->hostname ?? $username ?? 'Unknown';
        $jobType = $this->classificationLabel($classification);

        // Build subject
        $subject = "[Backup Alert] Failed - {$jobType} on {$hostname}";

        // Dedup: check for existing open ticket with same subject
        $existing = Ticket::where('source', TicketSource::CometBackup->value)
            ->where('client_id', $clientId)
            ->where('subject', $subject)
            ->whereNotIn('status', [TicketStatus::Closed, TicketStatus::Resolved])
            ->first();

        if ($existing) {
            Log::debug('[Comet Alert] Open ticket already exists, skipping', [
                'ticket_id' => $existing->id,
                'subject' => $subject,
            ]);
            return $existing;
        }

        // Build description
        $descLines = ['**Comet Backup Alert**'];
        $descLines[] = "- Device: {$hostname}";
        $descLines[] = "- Job type: {$jobType}";
        $descLines[] = '- Status: Failed';
        if ($startTime) {
            $descLines[] = '- Started: ' . date('Y-m-d H:i:s', $startTime);
        }
        if ($endTime) {
            $descLines[] = '- Ended: ' . date('Y-m-d H:i:s', $endTime);
        }
        if ($totalSize) {
            $descLines[] = '- Total size: ' . number_format($totalSize / (1024 ** 3), 2) . ' GB';
        }
        if ($fileErrors) {
            $descLines[] = '';
            $descLines[] = '**Errors:**';
            $descLines[] = '```';
            $descLines[] = substr($fileErrors, 0, 2000);
            $descLines[] = '```';
        }

        // Resolve contact from asset's primary user
        $contactId = null;
        $primaryUser = $asset->primaryUser();
        if ($primaryUser) {
            $contactId = $primaryUser->id;
        }

        $ticket = $this->ticketService->createTicket([
            'subject' => $subject,
            'description' => implode("\n", $descLines),
            'client_id' => $clientId,
            'contact_id' => $contactId,
            'priority' => TicketPriority::P3->value,
            'type' => TicketType::Incident->value,
            'source' => TicketSource::CometBackup->value,
        ], null);

        // Link asset to ticket
        if ($ticket) {
            $ticket->assets()->syncWithoutDetaching([$asset->id]);
        }

        Log::info('[Comet Alert] Ticket created', [
            'ticket_id' => $ticket?->id,
            'subject' => $subject,
            'client_id' => $clientId,
        ]);

        return $ticket;
    }

    /**
     * Handle a backup job success — resolve matching open ticket if found.
     */
    public function handleJobSuccess(array $data): ?Ticket
    {
        $deviceId = $data['DeviceID'] ?? null;
        $classification = $data['Classification'] ?? null;

        // Only auto-resolve for backup jobs
        if ($classification !== self::CLASS_BACKUP) {
            return null;
        }

        $asset = $deviceId ? Asset::where('comet_device_id', $deviceId)->first() : null;
        $clientId = $asset?->client_id;

        if (!$clientId) {
            return null;
        }

        $hostname = $asset->hostname ?? 'Unknown';
        $jobType = $this->classificationLabel($classification);
        $subject = "[Backup Alert] Failed - {$jobType} on {$hostname}";

        $ticket = Ticket::where('source', TicketSource::CometBackup->value)
            ->where('client_id', $clientId)
            ->where('subject', $subject)
            ->whereNotIn('status', [TicketStatus::Closed, TicketStatus::Resolved])
            ->latest()
            ->first();

        if (!$ticket) {
            return null;
        }

        $ticket->update(['status' => TicketStatus::Resolved]);

        // Add resolution note
        $systemUserId = \App\Support\TriageConfig::systemUserId() ?? \App\Models\User::orderBy('id')->value('id');
        if ($systemUserId) {
            $this->ticketService->addNote(
                $ticket,
                'Backup completed successfully. Alert auto-resolved.',
                \App\Enums\NoteType::System,
                true,
                $systemUserId,
            );
        }

        Log::info('[Comet Alert] Ticket resolved', [
            'ticket_id' => $ticket->id,
            'subject' => $subject,
        ]);

        return $ticket;
    }

    private function classificationLabel(int $classification): string
    {
        return match ($classification) {
            self::CLASS_BACKUP => 'Backup',
            self::CLASS_RESTORE => 'Restore',
            self::CLASS_RETENTION => 'Retention',
            default => 'Job',
        };
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Services/Comet/CometAlertService.php
git commit -m "Add CometAlertService for backup failure tickets and auto-resolve"
```

---

### Task 10: CometWebhookController and Route

**Files:**
- Create: `app/Http/Controllers/Api/CometWebhookController.php`
- Modify: `routes/api.php`

- [ ] **Step 1: Create CometWebhookController**

Follow the pattern from `app/Http/Controllers/Api/TacticalWebhookController.php`.

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Comet\CometAlertService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CometWebhookController extends Controller
{
    public function __construct(
        private readonly CometAlertService $alertService,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $data = $request->json()->all();

        Log::debug('[Comet Webhook] Received', ['type' => $data['Type'] ?? 'unknown']);

        try {
            $type = $data['Type'] ?? null;

            if ($type === 'job.completed') {
                $jobData = $data['Data'] ?? $data;
                $status = $jobData['Status'] ?? null;

                if ($status === 7002) {
                    // Error — create/dedup ticket
                    $ticket = $this->alertService->handleJobFailure($jobData);
                    return response()->json([
                        'status' => 'processed',
                        'ticket_id' => $ticket?->id,
                    ]);
                }

                if ($status === 5000) {
                    // Success — auto-resolve if matching open ticket
                    $ticket = $this->alertService->handleJobSuccess($jobData);
                    return response()->json([
                        'status' => 'processed',
                        'ticket_id' => $ticket?->id,
                    ]);
                }

                // Other statuses (warning, cancelled, running) — acknowledge but don't act
                Log::debug('[Comet Webhook] Ignoring job status', ['status' => $status]);
                return response()->json(['status' => 'ignored']);
            }

            // Other event types — acknowledge
            Log::debug('[Comet Webhook] Ignoring event type', ['type' => $type]);
            return response()->json(['status' => 'ignored']);

        } catch (\Exception $e) {
            Log::error('[Comet Webhook] Error processing webhook', [
                'error' => $e->getMessage(),
            ]);
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}
```

- [ ] **Step 2: Add route**

In `routes/api.php`, find the existing webhook routes (e.g., the tactical webhook route) and add nearby:

```php
Route::post('webhooks/comet', [\App\Http\Controllers\Api\CometWebhookController::class, 'handle'])
    ->middleware([\App\Http\Middleware\VerifyCometWebhookKey::class, 'throttle:120,1']);
```

- [ ] **Step 3: Verify route registers**

```bash
php artisan route:list --path=webhooks/comet
```

Expected: Shows the POST route.

- [ ] **Step 4: Commit**

```bash
git add app/Http/Controllers/Api/CometWebhookController.php routes/api.php
git commit -m "Add Comet webhook endpoint with rate limiting"
```

---

## Chunk 4: Settings UI

### Task 11: IntegrationsController Methods

**Files:**
- Modify: `app/Http/Controllers/Web/IntegrationsController.php`

Add methods following the pattern of `updateTactical()`, `testTactical()`, `syncTacticalDevices()`.

- [ ] **Step 1: Add updateComet method**

Find the Tactical methods in IntegrationsController and add similar methods after them:

```php
public function updateComet(Request $request)
{
    $validated = $request->validate([
        'comet_server_url' => 'nullable|url',
        'comet_admin_user' => 'nullable|string',
        'comet_admin_password' => 'nullable|string',
        'comet_alert_enabled' => 'nullable|boolean',
        'generate_webhook_key' => 'nullable|boolean',
    ]);

    if (isset($validated['comet_server_url'])) {
        Setting::setValue('comet_server_url', $validated['comet_server_url']);
    }
    if (isset($validated['comet_admin_user'])) {
        Setting::setEncrypted('comet_admin_user', $validated['comet_admin_user']);
    }
    if (isset($validated['comet_admin_password']) && $validated['comet_admin_password'] !== '••••••••' && $validated['comet_admin_password'] !== '') {
        Setting::setEncrypted('comet_admin_password', $validated['comet_admin_password']);
    }
    if ($request->has('comet_alert_enabled')) {
        Setting::setValue('comet_alert_enabled', $validated['comet_alert_enabled'] ? '1' : '0');
    }

    if ($request->input('generate_webhook_key')) {
        CometConfig::generateWebhookKey();
    }

    return redirect()->route('settings.integrations')
        ->with('success', 'Comet Backup settings updated.');
}

public function testComet()
{
    if (!CometConfig::isConfigured()) {
        return response()->json(['success' => false, 'message' => 'Comet is not configured']);
    }

    try {
        $client = new \App\Services\Comet\CometClient();
        $healthy = $client->isHealthy();

        if ($healthy) {
            Setting::setValue('comet_connected_at', now()->toDateTimeString());
            return response()->json(['success' => true, 'message' => 'Connected to Comet server']);
        }

        return response()->json(['success' => false, 'message' => 'Connection failed']);
    } catch (\Exception $e) {
        return response()->json(['success' => false, 'message' => $e->getMessage()]);
    }
}

public function syncCometBackup()
{
    if (!CometConfig::isConfigured()) {
        return back()->with('error', 'Comet is not configured.');
    }

    $client = new \App\Services\Comet\CometClient();
    $service = new \App\Services\Comet\CometBackupSyncService($client);
    $result = $service->syncBackupUsage();

    return back()->with('success', "Comet sync complete: {$result->summary()}");
}
```

Add `use App\Support\CometConfig;` and `use App\Models\Setting;` to the imports if not already present.

- [ ] **Step 2: Add routes**

In `routes/web.php`, find the existing settings.integrations routes (near the Tactical ones) and add:

```php
Route::post('/settings/integrations/comet', [IntegrationsController::class, 'updateComet'])->name('settings.integrations.comet.update');
Route::post('/settings/integrations/comet/test', [IntegrationsController::class, 'testComet'])->name('settings.integrations.comet.test');
Route::post('/settings/integrations/comet/sync-backup', [IntegrationsController::class, 'syncCometBackup'])->name('settings.integrations.comet.sync-backup');
```

- [ ] **Step 3: Commit**

```bash
git add app/Http/Controllers/Web/IntegrationsController.php routes/web.php
git commit -m "Add Comet settings controller methods and routes"
```

---

### Task 12: Integrations Settings View

**Files:**
- Modify: `resources/views/settings/integrations.blade.php`

Add a Comet Backup card following the same pattern as the Tactical RMM card.

- [ ] **Step 1: Add Comet section**

Find the end of the Tactical section (or another appropriate position) in `integrations.blade.php` and add a new card. Follow the same card structure as the Tactical section (icon, title, status badge, form, test connection button, webhook setup).

Place this card in the **RMM & Monitoring** tab section (same category as Tactical RMM and NinjaRMM).

```blade
{{-- Comet Backup --}}
<div class="card mb-4" id="comet-section">
    <div class="card-header d-flex align-items-center justify-content-between">
        <div>
            <i class="bi bi-cloud-arrow-up me-2"></i>
            <strong>Comet Backup</strong>
        </div>
        @if(\App\Support\CometConfig::isConfigured())
            <span class="badge bg-success">Connected</span>
        @else
            <span class="badge bg-secondary">Not Configured</span>
        @endif
    </div>
    <div class="card-body">
        <form action="{{ route('settings.integrations.comet.update') }}" method="POST">
            @csrf
            <div class="row g-3 mb-3">
                <div class="col-md-12">
                    <label class="form-label">Server URL</label>
                    <input type="url" name="comet_server_url" class="form-control"
                           value="{{ \App\Support\CometConfig::get('comet_server_url') }}"
                           placeholder="https://your-server.comet.backup">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Admin Username</label>
                    <input type="text" name="comet_admin_user" class="form-control"
                           value="{{ \App\Support\CometConfig::get('comet_admin_user') }}"
                           placeholder="admin">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Admin Password</label>
                    <input type="password" name="comet_admin_password" class="form-control"
                           value="{{ \App\Support\CometConfig::get('comet_admin_password') ? '••••••••' : '' }}"
                           placeholder="Enter password">
                    <small class="text-muted">Leave blank to keep existing password</small>
                </div>
            </div>
            <div class="d-flex gap-2 mb-3">
                <button type="submit" class="btn btn-primary btn-sm">Save</button>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="test-comet-btn" onclick="testConnection('comet')">
                    Test Connection
                </button>
                <div id="test-result-comet" class="mt-2" style="display: none;"></div>
            </div>
        </form>

        @if(\App\Support\CometConfig::isConfigured())
            {{-- Sync --}}
            <hr>
            <form action="{{ route('settings.integrations.comet.sync-backup') }}" method="POST" class="d-inline">
                @csrf
                <button type="submit" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-arrow-repeat me-1"></i>Sync Backup Usage
                </button>
            </form>

            {{-- Alert toggle --}}
            <hr>
            <form action="{{ route('settings.integrations.comet.update') }}" method="POST" class="d-inline-flex align-items-center gap-2">
                @csrf
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="comet_alert_enabled" value="1"
                           {{ \App\Support\CometConfig::alertsEnabled() ? 'checked' : '' }}
                           onchange="this.form.submit()">
                    <label class="form-check-label">Create tickets for failed backups</label>
                </div>
            </form>

            {{-- Webhook Setup --}}
            <hr>
            <h6>Webhook Setup</h6>
            @php $cometWebhookKey = \App\Support\CometConfig::get('comet_webhook_key'); @endphp
            @if($cometWebhookKey)
                <div class="mb-3">
                    <label class="form-label">Webhook Key</label>
                    <div class="input-group">
                        <input type="text" class="form-control font-monospace" value="{{ $cometWebhookKey }}" readonly>
                        <button class="btn btn-outline-secondary btn-sm" type="button"
                                onclick="navigator.clipboard.writeText(this.closest('.input-group').querySelector('input').value)">
                            <i class="bi bi-clipboard"></i>
                        </button>
                    </div>
                </div>
                <div class="alert alert-info small">
                    <strong>Configure in Comet Server:</strong>
                    <ol class="mb-0 mt-1">
                        <li>Go to Settings → Event Streamers in your Comet server admin panel</li>
                        <li>Add a new Webhook streamer</li>
                        <li>URL: <code>{{ url('/api/webhooks/comet') }}</code></li>
                        <li>Add header: <code>Authorization: Bearer {{ $cometWebhookKey }}</code></li>
                        <li>Enable event type: <strong>Job completed</strong></li>
                    </ol>
                </div>
            @else
                <form action="{{ route('settings.integrations.comet.update') }}" method="POST">
                    @csrf
                    <input type="hidden" name="generate_webhook_key" value="1">
                    <button type="submit" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-key me-1"></i>Generate Webhook Key
                    </button>
                </form>
            @endif

            {{-- Client Mapping --}}
            <hr>
            <h6>Organization Mapping</h6>
            <p class="text-muted small">Map PSA clients to Comet organizations. Run sync after mapping.</p>
            @php
                $cometOrgs = [];
                try {
                    if (\App\Support\CometConfig::isConfigured()) {
                        $cometOrgs = (new \App\Services\Comet\CometClient())->getOrganizations();
                    }
                } catch (\Exception $e) {
                    // Silently fail — orgs will be empty
                }
                $clients = \App\Models\Client::where('is_active', true)->orderBy('name')->get();
            @endphp
            @if(empty($cometOrgs))
                <p class="text-muted small">No organizations found. Verify connection and try again.</p>
            @else
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>PSA Client</th>
                                <th>Comet Organization</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($clients as $client)
                                <tr>
                                    <td>{{ $client->name }}</td>
                                    <td>
                                        <select class="form-select form-select-sm comet-org-select" data-client-id="{{ $client->id }}"
                                                style="max-width: 300px;">
                                            <option value="">— Not mapped —</option>
                                            @foreach($cometOrgs as $orgId => $org)
                                                <option value="{{ $orgId }}"
                                                    {{ $client->comet_org_id === (string) $orgId ? 'selected' : '' }}>
                                                    {{ $org->Name ?? $orgId }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <button type="button" class="btn btn-primary btn-sm" onclick="saveCometMappings()">
                    Save Mappings
                </button>
            @endif
        @endif
    </div>
</div>
```

- [ ] **Step 2: Add JavaScript for test connection and mapping save**

Add to the existing script section at the bottom of the integrations view. The `testConnection()` function already exists — it uses a `routes` object mapping service names to URLs. Find the routes object (around line 3110-3115) and add the `comet` entry:

```javascript
comet: '{{ route("settings.integrations.comet.test") }}',
```

For the mapping save, add:

```javascript
function saveCometMappings() {
    const selects = document.querySelectorAll('.comet-org-select');
    const mappings = {};
    selects.forEach(select => {
        mappings[select.dataset.clientId] = select.value;
    });

    fetch('{{ route("settings.integrations.comet.update") }}', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            'Accept': 'application/json',
        },
        body: JSON.stringify({ comet_org_mappings: mappings }),
    })
    .then(r => r.json())
    .then(data => {
        location.reload();
    })
    .catch(err => alert('Failed to save mappings'));
}
```

- [ ] **Step 3: Add mapping save handling in updateComet**

Back in IntegrationsController's `updateComet()` method, add handling for org mapping saves:

```php
if ($request->has('comet_org_mappings')) {
    foreach ($request->input('comet_org_mappings') as $clientId => $orgId) {
        \App\Models\Client::where('id', $clientId)->update([
            'comet_org_id' => $orgId ?: null,
        ]);
    }
    return response()->json(['success' => true]);
}
```

Add this at the top of the method, before the settings validation, since it's a separate JSON request.

- [ ] **Step 4: Commit**

```bash
git add resources/views/settings/integrations.blade.php app/Http/Controllers/Web/IntegrationsController.php
git commit -m "Add Comet Backup settings UI with org mapping and webhook setup"
```

---

## Chunk 5: Asset Detail UI

### Task 13: CometJobService

**Files:**
- Create: `app/Services/Comet/CometJobService.php`

- [ ] **Step 1: Create CometJobService**

```php
<?php

namespace App\Services\Comet;

use App\Models\Asset;
use Illuminate\Support\Facades\Log;

class CometJobService
{
    public function __construct(
        private readonly CometClient $client,
    ) {}

    /**
     * Get recent backup jobs for an asset.
     *
     * @return array{
     *     last_success: ?array,
     *     last_failure: ?array,
     *     jobs: array
     * }
     */
    public function getRecentJobs(Asset $asset, int $days = 7): array
    {
        if (!$asset->comet_username) {
            return ['last_success' => null, 'last_failure' => null, 'jobs' => []];
        }

        try {
            $allJobs = $this->client->getJobsForUser($asset->comet_username);
        } catch (CometClientException $e) {
            Log::warning("[Comet Jobs] Failed to fetch jobs for {$asset->comet_username}: {$e->getMessage()}");
            return ['last_success' => null, 'last_failure' => null, 'jobs' => []];
        }

        $cutoff = now()->subDays($days)->timestamp;
        $deviceId = $asset->comet_device_id;

        $jobs = [];
        $lastSuccess = null;
        $lastFailure = null;

        foreach ($allJobs as $job) {
            // Filter to this device and recent timeframe
            $jobDeviceId = $job->DeviceID ?? null;
            $startTime = $job->StartTime ?? 0;

            if ($deviceId && $jobDeviceId !== $deviceId) {
                continue;
            }

            $status = $job->Status ?? null;
            $endTime = $job->EndTime ?? null;
            $classification = $job->Classification ?? null;

            $formatted = [
                'status' => $this->statusLabel($status),
                'status_code' => $status,
                'classification' => $this->classificationLabel($classification),
                'started' => $startTime ? date('Y-m-d H:i:s', $startTime) : null,
                'ended' => $endTime ? date('Y-m-d H:i:s', $endTime) : null,
                'duration_seconds' => ($startTime && $endTime) ? ($endTime - $startTime) : null,
                'total_size' => $job->TotalSize ?? null,
                'upload_size' => $job->UploadSize ?? null,
                'total_files' => $job->TotalFiles ?? null,
                'error' => isset($job->FileErrors) ? substr($job->FileErrors, 0, 500) : null,
            ];

            // Track last success/failure (across all time, not just recent)
            if ($status === 5000 && (!$lastSuccess || $startTime > $lastSuccess['started_ts'])) {
                $lastSuccess = $formatted + ['started_ts' => $startTime];
            }
            if ($status === 7002 && (!$lastFailure || $startTime > $lastFailure['started_ts'])) {
                $lastFailure = $formatted + ['started_ts' => $startTime];
            }

            // Only include recent jobs in the list
            if ($startTime >= $cutoff) {
                $jobs[] = $formatted;
            }
        }

        // Sort by start time descending
        usort($jobs, fn ($a, $b) => ($b['started'] ?? '') <=> ($a['started'] ?? ''));

        return [
            'last_success' => $lastSuccess,
            'last_failure' => $lastFailure,
            'jobs' => $jobs,
        ];
    }

    private function statusLabel(?int $status): string
    {
        return match ($status) {
            5000 => 'Completed',
            6001 => 'Running',
            7001 => 'Warning',
            7002 => 'Failed',
            7005 => 'Cancelled',
            default => 'Unknown',
        };
    }

    private function classificationLabel(?int $classification): string
    {
        return match ($classification) {
            4 => 'Backup',
            5 => 'Restore',
            7 => 'Retention',
            default => 'Other',
        };
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Services/Comet/CometJobService.php
git commit -m "Add CometJobService for on-demand backup job history"
```

---

### Task 14: Asset Detail Backup Tab

**Files:**
- Modify: `resources/views/assets/show.blade.php`

The backup tab currently shows only when `$asset->ninja_id` is set. Update the tab logic to also show for `$asset->comet_device_id`.

- [ ] **Step 1: Update tab visibility condition**

Find the backup tab's visibility condition (around line 1239). It currently checks for `ninja_id`. Change it to show for either Ninja or Comet:

```blade
{{-- Backup tab link in the nav-tabs --}}
@if($asset->ninja_id || $asset->comet_device_id)
    <li class="nav-item">
        <a class="nav-link" id="tab-backup-tab" data-bs-toggle="tab" href="#tab-backup">Backup</a>
    </li>
@endif
```

- [ ] **Step 2: Add Comet job data to AssetController**

In `app/Http/Controllers/Web/AssetController.php`, find the `show()` method. Look for where Ninja backup job data is fetched (e.g., `$backupJobs`). Add Comet job fetching nearby:

```php
// Comet backup jobs
$cometJobData = null;
if ($asset->comet_device_id) {
    try {
        $cometJobService = new \App\Services\Comet\CometJobService(new \App\Services\Comet\CometClient());
        $cometJobData = $cometJobService->getRecentJobs($asset);
    } catch (\Exception $e) {
        // Silently fail — job data is optional
    }
}
```

Pass `$cometJobData` to the view in the `return view(...)` call:

```php
'cometJobData' => $cometJobData,
```

- [ ] **Step 3: Add Comet backup tab content**

Inside the backup tab pane in `resources/views/assets/show.blade.php`, wrap the existing Ninja backup content in a conditional and add the Comet section before it. The structure should be:

```blade
@if($asset->comet_device_id)
    {{-- Comet Backup Storage --}}
    <div class="card mb-3">
        <div class="card-header"><i class="bi bi-cloud-arrow-up me-2"></i>Backup Storage (Comet)</div>
        <div class="card-body">
            @if($asset->backup_synced_at)
                <div class="row">
                    <div class="col-md-4">
                        <strong>Cloud Storage</strong><br>
                        {{ $asset->backup_cloud_bytes ? \App\Support\Format::bytes($asset->backup_cloud_bytes) : '—' }}
                    </div>
                    <div class="col-md-4">
                        <strong>Local Storage</strong><br>
                        {{ $asset->backup_local_bytes ? \App\Support\Format::bytes($asset->backup_local_bytes) : '—' }}
                    </div>
                    <div class="col-md-4">
                        <strong>Last Synced</strong><br>
                        <span title="{{ $asset->backup_synced_at->toAppTz()->format('Y-m-d H:i:s T') }}">
                            {{ $asset->backup_synced_at->diffForHumans() }}
                        </span>
                    </div>
                </div>
            @else
                <p class="text-muted mb-0">No backup data synced yet.</p>
            @endif
        </div>
    </div>

    {{-- Comet Backup Jobs --}}
    @if($cometJobData && !empty($cometJobData['jobs']))
        <div class="card mb-3">
            <div class="card-header">
                <i class="bi bi-clock-history me-2"></i>Recent Backup Jobs
                @if($cometJobData['last_success'])
                    <span class="badge bg-success ms-2">Last success: {{ $cometJobData['last_success']['started'] }}</span>
                @endif
                @if($cometJobData['last_failure'])
                    <span class="badge bg-danger ms-2">Last failure: {{ $cometJobData['last_failure']['started'] }}</span>
                @endif
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Status</th>
                                <th>Type</th>
                                <th>Started</th>
                                <th>Duration</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($cometJobData['jobs'] as $job)
                                <tr>
                                    <td>
                                        @php
                                            $badgeClass = match($job['status']) {
                                                'Completed' => 'bg-success',
                                                'Failed' => 'bg-danger',
                                                'Warning' => 'bg-warning text-dark',
                                                'Running' => 'bg-info',
                                                'Cancelled' => 'bg-secondary',
                                                default => 'bg-secondary',
                                            };
                                        @endphp
                                        <span class="badge {{ $badgeClass }}">{{ $job['status'] }}</span>
                                    </td>
                                    <td>{{ $job['classification'] }}</td>
                                    <td>{{ $job['started'] }}</td>
                                    <td>
                                        @if($job['duration_seconds'])
                                            {{ gmdate('H:i:s', $job['duration_seconds']) }}
                                        @else
                                            —
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @elseif($cometJobData)
        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-clock-history me-2"></i>Recent Backup Jobs</div>
            <div class="card-body">
                <p class="text-muted mb-0">No recent backup jobs found.</p>
            </div>
        </div>
    @endif

@elseif($asset->ninja_id)
    {{-- Existing Ninja backup tab content stays here unchanged --}}
    {{-- ... keep all existing Ninja backup HTML ... --}}
@endif
```

The key points:
- Wrap the entire existing Ninja backup section in `@elseif($asset->ninja_id)` ... the existing `@endif` at the end of the Ninja section becomes the closing `@endif` for the outer `@if/$elseif`
- Comet takes precedence when both are linked (unlikely but safe)
- The nav-tab visibility condition should also be updated: `@if($asset->ninja_id || $asset->comet_device_id)`

- [ ] **Step 4: Commit**

```bash
git add resources/views/assets/show.blade.php app/Http/Controllers/Web/AssetController.php
git commit -m "Add Comet backup tab to asset detail page"
```

---

## Chunk 6: AI Triage Integration

### Task 15: ContextBuilder Backup Enrichment

**Files:**
- Modify: `app/Services/Triage/ContextBuilder.php`

- [ ] **Step 1: Add Comet backup context to buildAssetSection**

Add `use App\Support\CometConfig;`, `use App\Services\Comet\CometClient;`, and `use App\Services\Comet\CometJobService;` to the imports at the top of ContextBuilder.

Find the `buildAssetSection()` method. Look for where other vendor data is appended (Tactical, Control D, Zorus sections). Add a Comet backup section after them:

```php
// Comet Backup
if ($asset->comet_device_id && $asset->backup_synced_at) {
    $lines[] = '  Backup (Comet):';
    if ($asset->backup_cloud_bytes) {
        $lines[] = '    Cloud storage: ' . \App\Support\Format::bytes($asset->backup_cloud_bytes);
    }
    $lines[] = '    Last synced: ' . $asset->backup_synced_at->diffForHumans();

    // Check last job status
    try {
        $cometClient = new \App\Services\Comet\CometClient();
        $jobService = new \App\Services\Comet\CometJobService($cometClient);
        $jobData = $jobService->getRecentJobs($asset, 3);
        if ($jobData['last_success']) {
            $lines[] = '    Last successful backup: ' . $jobData['last_success']['started'];
        }
        if ($jobData['last_failure'] && (!$jobData['last_success'] || $jobData['last_failure']['started'] > $jobData['last_success']['started'])) {
            $lines[] = '    ⚠ Last backup FAILED: ' . $jobData['last_failure']['started'];
        }
        $daysSinceBackup = $jobData['last_success']
            ? now()->diffInDays(\Carbon\Carbon::parse($jobData['last_success']['started']))
            : null;
        if ($daysSinceBackup !== null && $daysSinceBackup > 2) {
            $lines[] = "    ⚠ No successful backup in {$daysSinceBackup} days";
        }
    } catch (\Exception $e) {
        // Silently fail — backup context is supplementary
    }
}
```

- [ ] **Step 2: Update buildIntegrationAvailabilitySection**

Find the `buildIntegrationAvailabilitySection()` method in ContextBuilder. This lists available integrations for the AI. Add a Comet entry alongside the existing vendor checks:

```php
if (CometConfig::isConfigured()) {
    $available[] = 'Comet Backup (comet_get_backup_status, comet_get_backup_jobs)';
}
```

- [ ] **Step 3: Commit**

```bash
git add app/Services/Triage/ContextBuilder.php
git commit -m "Add Comet backup context to triage ContextBuilder"
```

---

### Task 16: Triage Tool Definitions

**Files:**
- Modify: `app/Services/Triage/TriageToolDefinitions.php`

- [ ] **Step 1: Add Comet availability check and tools**

Add `use App\Support\CometConfig;` to the imports at the top of TriageToolDefinitions.

Find the `getTools()` method. Look for the conditional blocks that use `array_merge` with separate static methods (e.g., `if (self::isTacticalAvailable()) { $tools = array_merge($tools, self::tacticalTools()); }`). Add a similar block for Comet:

```php
if (self::isCometAvailable()) {
    $tools = array_merge($tools, self::cometTools());
}
```

Then add the availability check and tools methods (alongside the existing ones):

```php
public static function isCometAvailable(): bool
{
    return CometConfig::isConfigured();
}

private static function cometTools(): array
{
    return [
        [
            'name' => 'comet_get_backup_status',
            'description' => 'Get backup health status for a device. Returns last job status, last success time, storage usage, and days since last successful backup.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'hostname' => [
                        'type' => 'string',
                        'description' => 'Device hostname to check backup status for',
                    ],
                ],
                'required' => ['hostname'],
            ],
        ],
        [
            'name' => 'comet_get_backup_jobs',
            'description' => 'Get recent backup job history for a device. Shows job status, type, timestamps, duration, and error details.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'hostname' => [
                        'type' => 'string',
                        'description' => 'Device hostname to get backup jobs for',
                    ],
                    'days' => [
                        'type' => 'integer',
                        'description' => 'Number of days of history to retrieve (default: 7)',
                    ],
                ],
                'required' => ['hostname'],
            ],
        ],
    ];
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Services/Triage/TriageToolDefinitions.php
git commit -m "Add Comet backup triage tool definitions"
```

---

### Task 17: Triage Tool Executor

**Files:**
- Modify: `app/Services/Triage/TriageToolExecutor.php`

- [ ] **Step 1: Add Comet tool execution**

Add these imports at the top of `TriageToolExecutor.php`:

```php
use App\Services\Comet\CometClient;
use App\Services\Comet\CometJobService;
```

Find the `execute()` method's dispatch logic (likely a match/switch on `$toolName`). Add cases for the two Comet tools:

```php
'comet_get_backup_status' => $this->executeCometGetBackupStatus($input),
'comet_get_backup_jobs' => $this->executeCometGetBackupJobs($input),
```

Then add the implementation methods:

```php
private function resolveCometAsset(string $hostname): ?\App\Models\Asset
{
    return \App\Models\Asset::where('client_id', $this->clientId)
        ->whereNotNull('comet_device_id')
        ->whereRaw('LOWER(hostname) = ?', [strtolower($hostname)])
        ->first();
}

private function executeCometGetBackupStatus(array $input): array
{
    $hostname = $input['hostname'] ?? null;
    if (!$hostname) {
        return ['error' => 'hostname is required'];
    }

    $asset = $this->resolveCometAsset($hostname);
    if (!$asset) {
        return ['error' => "No Comet-linked asset found for hostname '{$hostname}' in this client"];
    }

    $client = new CometClient();
    $jobService = new CometJobService($client);
    $jobData = $jobService->getRecentJobs($asset, 30);

    $daysSinceBackup = null;
    if ($jobData['last_success']) {
        $daysSinceBackup = now()->diffInDays(\Carbon\Carbon::parse($jobData['last_success']['started']));
    }

    return [
        'hostname' => $asset->hostname,
        'comet_username' => $asset->comet_username,
        'cloud_storage_bytes' => $asset->backup_cloud_bytes,
        'local_storage_bytes' => $asset->backup_local_bytes,
        'last_synced' => $asset->backup_synced_at?->toDateTimeString(),
        'last_success' => $jobData['last_success']['started'] ?? null,
        'last_failure' => $jobData['last_failure']['started'] ?? null,
        'days_since_last_success' => $daysSinceBackup,
    ];
}

private function executeCometGetBackupJobs(array $input): array
{
    $hostname = $input['hostname'] ?? null;
    if (!$hostname) {
        return ['error' => 'hostname is required'];
    }

    $asset = $this->resolveCometAsset($hostname);
    if (!$asset) {
        return ['error' => "No Comet-linked asset found for hostname '{$hostname}' in this client"];
    }

    $days = $input['days'] ?? 7;
    $client = new CometClient();
    $jobService = new CometJobService($client);
    $jobData = $jobService->getRecentJobs($asset, $days);

    return [
        'hostname' => $asset->hostname,
        'job_count' => count($jobData['jobs']),
        'jobs' => array_slice($jobData['jobs'], 0, 20), // Limit to 20 for token budget
    ];
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Services/Triage/TriageToolExecutor.php
git commit -m "Add Comet backup triage tool execution"
```

---

### Task 18: Final Verification and Documentation

- [ ] **Step 1: Run migrations on dev**

```bash
php artisan migrate
```

- [ ] **Step 2: Verify all routes**

```bash
php artisan route:list --path=comet
```

Expected: Shows webhook route and settings routes.

- [ ] **Step 3: Verify command**

```bash
php artisan comet:sync-backup --help
```

Expected: Shows command with `--client` and `--dry-run` options.

- [ ] **Step 4: Update docs/INSTALL.md**

Add Comet Backup to the integrations section mentioning:
- Settings: server URL, admin credentials, webhook key
- Artisan command: `comet:sync-backup` (daily at 05:40)
- Webhook: `POST /api/webhooks/comet` (Bearer token auth)
- Composer dependency: `cometbackup/comet-php-sdk`

- [ ] **Step 5: Commit**

```bash
git add docs/INSTALL.md
git commit -m "Document Comet Backup integration in INSTALL.md"
```
