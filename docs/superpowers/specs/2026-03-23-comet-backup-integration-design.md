# Comet Backup Integration Design

**Date:** 2026-03-23
**Status:** Approved

## Problem

PSA currently uses NinjaRMM for both RMM and backup at ~$14,000/year. With Tactical RMM replacing the monitoring/management side, backup is the last dependency keeping Ninja around. Migrating to Comet Backup eliminates the Ninja subscription entirely, saving ~$11,000/year.

## Solution

1. **Use Comet-hosted Backup Server** (managed by Comet, Wasabi S3 storage included)
2. **Integrate Comet into the PSA** with the same patterns used for Ninja backup: device sync, license counts, backup job history, alert webhooks, and AI triage tools

## Infrastructure

### Comet Server

- Comet-hosted (no self-managed VPS — Comet manages the server infrastructure)
- Professional plan ($49/month, 1 admin license) or Growth equivalent
- Per-device pricing: $2/endpoint + $3/disk imaging = $5/device/month
- Storage: Comet Storage powered by Wasabi ($6.99/TB/month, no egress fees)
- Can migrate to self-hosted later if needed

### Estimated Cost

| Component | Monthly |
|-----------|---------|
| Comet plan | $49 |
| 28 devices (file + image) | $161 |
| Comet Storage 8TB | $56 |
| **Total** | **~$266** |

vs. NinjaRMM (RMM + backup): ~$1,167/month ($14,000/year)

**Net savings: ~$10,800/year** (Tactical is free, Comet replaces Ninja backup)

## Data Model

### Asset Columns (new)

| Column | Type | Purpose |
|--------|------|---------|
| `comet_username` | string, nullable | Comet user account |
| `comet_device_id` | string, nullable, unique | Comet device GUID |

Existing vendor-agnostic backup columns are reused:
- `backup_cloud_bytes` (unsignedBigInteger, nullable)
- `backup_local_bytes` (unsignedBigInteger, nullable)
- `backup_revisions_bytes` (unsignedBigInteger, nullable) — Comet does not track revisions separately; left null for Comet-synced assets
- `backup_synced_at` (timestamp, nullable) — updated by whichever backup vendor sync runs (Comet or Ninja)

No separate `comet_synced_at` column — `backup_synced_at` serves the same purpose and keeps the schema vendor-agnostic.

### Client Mapping

`comet_org_id` (string, nullable) on `clients` table. Maps a PSA client to a Comet Organization ID.

**Multi-org mode (recommended):** Each PSA client maps to a Comet Organization. Sync filters users by their organization membership.

**Single-org mode:** If all Comet users are in one organization, set every mapped client's `comet_org_id` to that org's ID. Sync matches users to clients by looking up which client's `comet_org_id` matches the user's organization. In single-org this means all users belong to the same org, so matching falls back to hostname-based asset resolution (the asset must already exist in the PSA and be assigned to the correct client).

### License Types (vendor: `comet`)

| vendor_sku_id | Name | Quantity Represents |
|---------------|------|---------------------|
| `backup_workstation` | Comet Backup — Workstation | Protected workstation count |
| `backup_server` | Comet Backup — Server | Protected server count |
| `cloud_usage_gb` | Comet Backup Usage (GB) | Cloud storage in GB |

License upserts use `vendor_ref` = `comet_org_id` (following Ninja pattern where `vendor_ref` = `ninja_org_id`).

### Ticket Source

New `TicketSource::CometBackup` enum value for backup alert tickets.

## Files to Create

| File | Purpose |
|------|---------|
| `app/Support/CometConfig.php` | Static config helper (server URL, credentials, settings) |
| `app/Services/Comet/CometClient.php` | PHP SDK wrapper with error handling and logging |
| `app/Services/Comet/CometClientException.php` | Exception class |
| `app/Services/Comet/CometBackupSyncService.php` | Device sync, backup storage, license counts |
| `app/Services/Comet/CometJobService.php` | Backup job history for asset detail page |
| `app/Services/Comet/CometAlertService.php` | Webhook handler: failed backup → ticket, success → resolve |
| `app/Http/Controllers/Api/CometWebhookController.php` | Webhook endpoint |
| `app/Http/Middleware/VerifyCometWebhookKey.php` | Bearer token validation |
| `app/Console/Commands/CometSyncBackup.php` | `comet:sync-backup` artisan command |
| `database/migrations/..._add_comet_fields_to_assets.php` | New asset columns |
| `database/migrations/..._add_comet_org_id_to_clients.php` | Client mapping column |

## Files to Modify

| File | Change |
|------|--------|
| `app/Enums/TicketSource.php` | Add `CometBackup` value |
| `app/Services/Triage/ContextBuilder.php` | Add backup status to asset context for Comet-linked assets |
| `app/Services/Triage/TriageToolDefinitions.php` | Add Comet backup tools (gated by CometConfig) |
| `app/Services/Triage/TriageToolExecutor.php` | Execute Comet triage tool calls |
| `resources/views/assets/show.blade.php` | Backup tab: show Comet data when `comet_device_id` present |
| `resources/views/settings/integrations.blade.php` | Comet Backup config section |
| `app/Http/Controllers/Web/IntegrationsController.php` | Comet settings save/test/sync actions |
| `routes/api.php` | Webhook route (with `throttle:120,1`) |
| `routes/console.php` | Scheduled sync command |

## CometConfig

`app/Support/CometConfig.php` — static helper following TacticalConfig pattern.

**Settings:**
- `comet_server_url` — Comet server base URL (e.g., `https://backup.soundit.co`)
- `comet_admin_user` — admin username (encrypted)
- `comet_admin_password` — admin password (encrypted)
- `comet_webhook_key` — webhook Bearer token (encrypted)
- `comet_alert_enabled` — enable/disable backup failure alerts (default: true)

**Methods:**
- `isConfigured()` — returns true when `comet_server_url`, `comet_admin_user`, and `comet_admin_password` are all set
- `isEnabled()` — delegates to `isConfigured()` (same as TacticalConfig)
- `alertsEnabled()` — returns `comet_alert_enabled` setting (default true), only meaningful when `isConfigured()` is true
- `serverUrl()` — returns `comet_server_url`
- `get(key)` — generic setting getter with encrypted field handling

## CometClient

`app/Services/Comet/CometClient.php` — wraps the official `cometbackup/comet-php-sdk` package.

**Dependencies:** `composer require cometbackup/comet-php-sdk` (PHP 7.2+, namespace `Comet\`)

**Authentication:** Admin credentials passed as POST parameters per Comet API convention. The PHP SDK handles this internally via the `\Comet\Server` class constructor: `new \Comet\Server($url, $username, $password)`.

**Methods:**

| Method | SDK Call | Returns |
|--------|---------|---------|
| `listUsersFull()` | `AdminListUsersFull()` | All user profiles with device details, storage stats |
| `getUserProfile(username)` | `AdminGetUserProfile()` | Single user profile |
| `getJobsForUser(username, since)` | `AdminGetJobsForUser()` | Backup job history |
| `getJobsForDateRange(start, end)` | `AdminGetJobsForDateRange()` | Jobs in time range |
| `listActive()` | `AdminDispatcherListActive()` | Live connected devices |
| `getOrganizations()` | `AdminOrganizationList()` | Tenant orgs (for client mapping) |
| `isHealthy()` | `AdminMetaVersion()` | Connection test |

**Error handling:** Try/catch around SDK calls, log with `[Comet]` prefix, throw `CometClientException`.

## CometBackupSyncService

`app/Services/Comet/CometBackupSyncService.php` — replaces `NinjaBackupSyncService`.

Returns `SyncResult` (same pattern as `NinjaBackupSyncService`): tracks `$result->updated`, `$result->created`, `$result->deactivated`, `$result->recordError()`.

### syncBackupUsage()

1. Load mapped clients (`whereNotNull('comet_org_id')`)
2. Call `listUsersFull()` to get all user profiles
3. Filter users by organization (matching client `comet_org_id`)
4. For each user's devices:
   - Match to PSA asset by hostname (case-insensitive, scoped to client)
   - Update `comet_username`, `comet_device_id`
   - Update `backup_cloud_bytes`, `backup_local_bytes` from storage vault statistics
   - Set `backup_revisions_bytes` to null (Comet does not provide separate revision tracking)
   - Update `backup_synced_at` to now
5. Clear stale backup data for assets with `comet_device_id` no longer seen in Comet response
6. Call `syncLicenseCounts()` with collected data

### syncLicenseCounts()

- Upsert 3 license types (vendor `comet`): `backup_workstation`, `backup_server`, `cloud_usage_gb`
- Count devices by `asset_type` (server vs workstation), only where backup data exists (cloud or local > 0)
- Convert cloud bytes to GB (1024³, rounded)
- Use `vendor_ref` = `comet_org_id` in license upserts
- Zero out licenses for clients that no longer have backup data
- Call `License::deactivateOrphaned('comet', 'comet_org_id')` for unmapped clients

### Artisan Command

`comet:sync-backup` — supports `--client={id}` (sync single client) and `--dry-run` (log changes without writing) flags, following established patterns.

### Schedule

Daily at 05:40 (available slot between existing 05:35 and 05:45 commands). Runs independently of `ninja:sync-backup` (05:30) — both coexist during migration. Gated by `CometConfig::isConfigured()`.

## CometJobService

`app/Services/Comet/CometJobService.php` — on-demand job history for asset detail page.

### getRecentJobs(Asset $asset)

1. Resolve `comet_username` from asset
2. Call `getJobsForUser(username)` for last 7 days
3. Filter to jobs matching the asset's `comet_device_id`
4. Return structured array: status, classification (backup/restore), start time, end time, duration, total size, upload size, error message

**Job status mapping:**
- 5000 → Success (green badge)
- 6001 → Running (yellow badge)
- 7001 → Warning (orange badge)
- 7002 → Error (red badge)
- 7005 → Cancelled (grey badge)

## CometAlertService

`app/Services/Comet/CometAlertService.php` — webhook handler for backup alerts.

### Webhook Endpoint

`POST /api/webhooks/comet` — authenticated by `VerifyCometWebhookKey` middleware (Bearer token). Rate limited `throttle:120,1`.

### Webhook Payload

Comet sends webhook events via its "event streamer" system (configured in Comet Server settings). The event streamer is configured to POST JSON to the PSA endpoint.

Comet webhook events include a `Type` field indicating the event type. The relevant type for backup alerting is job completion. The payload includes:

```json
{
  "Type": "job.completed",
  "Data": {
    "Username": "client-hostname",
    "Status": 7002,
    "Classification": 4,
    "SourceGUID": "...",
    "DestinationGUID": "...",
    "DeviceID": "...",
    "StartTime": 1711180800,
    "EndTime": 1711184400,
    "TotalFiles": 12345,
    "TotalSize": 1073741824,
    "UploadSize": 536870912,
    "FileErrors": "error details..."
  }
}
```

**Status codes:** 5000 = success, 7001 = warning, 7002 = error, 7005 = cancelled.
**Classification codes:** 4 = backup, 5 = restore, 7 = retention pass.

### Controller Dispatch Logic

`CometWebhookController::handle(Request $request)`:

1. Parse JSON body
2. Read `Type` field
3. If `Type` is `job.completed`:
   - Read `Data.Status`
   - If status == 7002 (error): call `CometAlertService::handleJobFailure($data)`
   - If status == 5000 (success): call `CometAlertService::handleJobSuccess($data)`
   - All other statuses (warning, cancelled, running): log and ignore
4. All other event types: log at debug level and return 200

### handleJobFailure(array $data)

1. Extract: username, device ID, classification, error message, timestamps
2. Resolve asset via `comet_device_id` → asset → client
3. Skip if no client match (unmapped device)
4. Build subject: `[Backup Alert] Failed - {job_type} on {hostname}` (where job_type is derived from classification: "Backup", "Restore", etc.)
5. Dedup: check for open ticket with same subject (no time window). Note: different job types (e.g., file backup vs disk image) produce different subjects and thus separate tickets. This is intentional — they are independent failures.
6. Create ticket: priority P3, type Incident, source CometBackup
7. Description includes: hostname, job type, error message, start/end time, file count, total size
8. Link asset to ticket

### handleJobSuccess(array $data)

1. Resolve asset via `comet_device_id` → client
2. Find open ticket matching `[Backup Alert] Failed - {job_type} on {hostname}` for this device and job type
3. If found: resolve ticket, add note "Backup completed successfully"

### Noise Filtering

- Only alert on status 7002 (error), not 7005 (cancelled) or 7001 (warning)
- Only alert on classification 4 (backup). Ignore restore (5) and retention (7) failures.
- Gated by `CometConfig::alertsEnabled()` setting

Note: Comet does not use email notifications to the PSA — all alerting is via webhooks. No JunkDetector changes needed.

## AI Triage Integration

### Context Enrichment (ContextBuilder)

When a ticket's linked asset has `comet_device_id`, automatically include in `buildAssetSection()`:
- Last backup status (success/failed/never)
- Last successful backup timestamp
- Cloud storage usage (formatted with `Format::bytes()`)
- Days since last successful backup (highlighted if > 2 days)

This is new backup context in ContextBuilder — Ninja backup data is not currently included in triage context. This adds it for Comet-linked assets only.

Max 500 bytes per asset for backup context (within existing per-asset budget).

Data source: `backup_cloud_bytes` and `backup_synced_at` from local DB (no live API call needed), plus one `getJobsForUser()` call to check last job status.

### Triage Tools

Two tools added to `TriageToolDefinitions`, gated by `CometConfig::isConfigured()`:

#### `comet_get_backup_status`

Query backup health for a device.

**Input:** `hostname` (string) — matched against assets with `comet_device_id`
**Returns:** Last job status, last success time, storage usage (cloud/local bytes), protected item types, days since last success.

#### `comet_get_backup_jobs`

Get recent backup job history.

**Input:** `hostname` (string), `days` (integer, default 7)
**Returns:** Array of jobs with: status, classification, start time, duration, size, error message (truncated to 500 chars each).

### Client Scoping

All tool calls enforce client scoping: hostname → `comet_device_id` → asset → `client_id` must match ticket's `client_id`. Cross-client queries blocked (same pattern as Tactical/Ninja triage tools).

## Settings UI

New "Comet Backup" card in the Integrations page:

- Server URL input
- Admin username input
- Admin password input (encrypted)
- Webhook key: generate button + display (same pattern as Tactical)
- Test Connection button
- Sync Backup Usage button
- Enable backup failure alerts toggle
- Webhook setup instructions: URL (`POST https://your-psa-domain/api/webhooks/comet`), headers (`Authorization: Bearer {key}`, `Content-Type: application/json`), configure event streamer in Comet Server settings for "job.completed" events
- Client → Organization mapping (same layout as other vendor mappings)

## Asset Detail UI

Backup tab logic:
- If asset has `comet_device_id` → show Comet backup data
- Else if asset has `ninja_id` → show Ninja backup data (legacy, during migration)
- Else → no backup tab

Comet backup tab contents:
- **Storage card:** cloud bytes, local bytes, last sync time (reuses existing layout)
- **Recent jobs card:** status badge, job type, started, duration (same table layout as Ninja jobs)
- No separate integrity checks section (Comet handles verification within jobs)

## Migration Path

1. Configure Comet-hosted server (sign up, set up organizations per client)
2. Install Comet agents on client devices (can coexist with Ninja during transition)
3. Configure PSA integration: credentials, client org mapping
4. Run `comet:sync-backup` — assets get `comet_device_id` via hostname match
5. Backup tab automatically switches to Comet data source per device
6. Migrate devices gradually (no big-bang cutover)
7. Once all devices migrated, cancel Ninja subscription
8. Ninja backup fields become inert (future cleanup, same as Halo ID pattern)

## Scope Boundaries

- Comet integration **coexists** with Ninja during migration — both sync services can run simultaneously
- No changes to billing/contract architecture — Comet license types plug into existing `QuantityType` system
- Webhook events limited to job completion (success/failure) — no agent online/offline alerts
- AI triage tools are read-only (query backup status, not trigger backups)
- Comet server is hosted by Comet (no VPS provisioning needed). Can migrate to self-hosted later if needed
