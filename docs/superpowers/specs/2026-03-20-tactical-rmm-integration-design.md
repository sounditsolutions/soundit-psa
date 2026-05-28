# Tactical RMM Integration

**Date:** 2026-03-20
**Status:** Approved

## Problem

NinjaRMM costs ~$14K/year and creates integration friction — device sync, user resolution, and alert handling all require bridging two separate systems. The PSA can't run scripts on devices, deploy software based on contracts, or act on alerts in real-time.

## Solution

Self-host Tactical RMM (open-source) on a dedicated VPS and integrate deeply with the PSA via Tactical's REST API. Three phases: device sync, alert-to-ticket webhooks, and script execution from within the PSA.

Tactical RMM is deployed alongside NinjaRMM (not replacing it) so clients can be migrated gradually. Tactical device data lives in a separate `tactical_assets` table linked to the main `assets` table via FK, keeping vendor data isolated.

## Architecture Overview

```
Tactical RMM Server (dedicated VPS)
  ├── Tactical Agents on Windows devices
  ├── REST API (X-API-KEY auth)
  └── Webhook → PSA on alert events

Sound PSA (existing VPS)
  ├── TacticalClient (Guzzle, API key auth)
  ├── TacticalDeviceSyncService (daily pull)
  ├── TacticalWebhookController (alert → ticket)
  └── Script execution (PSA → Tactical API → agent)
```

## Infrastructure

**Tactical RMM Server:**
- Dedicated Vultr VPS (4GB RAM, SSD)
- Fresh Debian 12 or Ubuntu 22.04
- Three subdomains: `your-tactical-rmm-domain`, `api-your-tactical-rmm-domain`, `mesh.soundit.co`
- Tactical install script handles Nginx, Certbot, PostgreSQL, Redis, etc.
- Sponsorship Tier 1 ($600/year) for code-signed agents + Mac support

**Total cost:** ~$450/year VPS + $600/year sponsorship = ~$1,050/year (vs $14K for NinjaRMM)

---

## Phase 1 — Foundation: Config, Sync, Asset Display

### Data Model

**New table: `tactical_assets`**

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint PK | |
| `asset_id` | FK → assets, nullable | Linked when matched to a PSA asset |
| `agent_id` | string, unique | Tactical's agent UUID |
| `hostname` | string | |
| `os` | string, nullable | Full OS name |
| `os_version` | string, nullable | |
| `public_ip` | string, nullable | |
| `local_ips` | json, nullable | Array of local IPs |
| `last_user` | string, nullable | Last logged-on user |
| `cpu` | string, nullable | |
| `ram_gb` | decimal, nullable | |
| `disk_summary` | text, nullable | |
| `status` | string(20) | online, offline, overdue |
| `agent_version` | string, nullable | |
| `last_seen_at` | datetime, nullable | |
| `site_name` | string, nullable | Tactical's site name |
| `needs_reboot` | boolean, default false | |
| `patches_pending` | integer, default 0 | Count of pending Windows updates |
| `synced_at` | datetime, nullable | |
| timestamps | | |

**Modified table: `assets`**
- Add `tactical_asset_id` FK → `tactical_assets` (nullable)

**Modified table: `clients`**
- Add `tactical_site_id` string, nullable — maps client to Tactical site

### Config

**`TacticalConfig`** (`app/Support/TacticalConfig.php`) — static helper following existing pattern. Settings: `tactical_api_url`, `tactical_api_key` (encrypted), `tactical_webhook_key` (encrypted). Methods: `isConfigured()`, `isEnabled()`, `get(key)`, `apiUrl()`.

### Client

**`TacticalClient`** (`app/Services/Tactical/TacticalClient.php`) — Guzzle client, `X-API-KEY` header auth. Methods:

- `getAgents(): array` — list all agents
- `getAgent(string $agentId): array` — single agent detail
- `runScript(string $agentId, int $scriptId, ?array $args, int $timeout): array` — execute script on agent
- `getScripts(): array` — list script library
- `getSoftware(string $agentId): array` — installed software
- `getPatches(string $agentId): array` — Windows update status
- `getSites(): array` — list sites for client mapping
- `isHealthy(): bool`

### Sync Service

**`TacticalDeviceSyncService`** (`app/Services/Tactical/TacticalDeviceSyncService.php`)

Syncs agents from Tactical into `tactical_assets` table and links to `assets`:
1. Fetch all agents via API
2. Upsert into `tactical_assets` by `agent_id`
3. Match to `assets` table: by `tactical_asset_id` first (already linked), then by hostname (case-insensitive, scoped to client via `tactical_site_id` mapping)
4. When matched: set `assets.tactical_asset_id`, update `assets.last_user` from tactical data
5. Stale cleanup: unlinked tactical_assets whose `agent_id` no longer appears in API response get `status` set to `offline`

**Schedule:** `tactical:sync-devices` daily at 05:30, gated by `TacticalConfig::isConfigured()`.

### Artisan Command

`TacticalSyncDevices` — `tactical:sync-devices` with `--client` and `--dry-run` flags.

### Settings UI

**Settings > Integrations > Tactical RMM section:**
- API URL input
- API Key input (encrypted)
- Webhook Key input (encrypted)
- Health check button
- "Sync Now" button

**Settings > Tactical Site Mapping page:**
- Same pattern as Ninja org mapping
- Fetches sites from Tactical API
- Maps each site to a PSA client via dropdown
- Saves to `clients.tactical_site_id`

### Asset Detail Page

**Tactical RMM card** on asset detail page (when `tactical_asset_id` is set):
- Status badge (Online/Offline/Overdue)
- Agent version
- Last seen (diffForHumans)
- Pending patches count (with warning if > 0)
- Needs reboot indicator
- Public IP / Local IPs
- Link to Tactical web UI for this agent

### Files to Create (Phase 1)

| File | Purpose |
|------|---------|
| `database/migrations/XXXX_create_tactical_assets_table.php` | New table |
| `database/migrations/XXXX_add_tactical_asset_id_to_assets.php` | FK on assets |
| `database/migrations/XXXX_add_tactical_site_id_to_clients.php` | Site mapping |
| `app/Models/TacticalAsset.php` | Model |
| `app/Support/TacticalConfig.php` | Config helper |
| `app/Services/Tactical/TacticalClient.php` | API client |
| `app/Services/Tactical/TacticalDeviceSyncService.php` | Sync service |
| `app/Console/Commands/TacticalSyncDevices.php` | Artisan command |

### Files to Modify (Phase 1)

| File | Change |
|------|--------|
| `app/Models/Asset.php` | Add `tacticalAsset()` HasOne relationship |
| `resources/views/assets/show.blade.php` | Add Tactical RMM data card |
| Settings views | Add Tactical config section + site mapping page |
| `routes/web.php` | Add settings/mapping routes |
| `routes/console.php` | Schedule sync command |

---

## Phase 2 — Alert Webhooks

### Webhook Endpoint

**Route:** `POST /api/webhooks/tactical`

**Middleware:** `VerifyTacticalWebhookKey` — validates `Authorization: Bearer {key}` header against `tactical_webhook_key` setting (encrypted). Returns 401 on mismatch.

### Controller

**`TacticalWebhookController`** (`app/Http/Controllers/Api/TacticalWebhookController.php`)

Receives alert webhook payload from Tactical:
1. Parse agent ID and alert details from payload
2. Look up `tactical_assets` by `agent_id` → get linked `asset` → get `client_id`
3. If no client match: log and return 200 (don't reject — Tactical doesn't retry)
4. On alert failure: create ticket
5. On alert resolved: resolve matching ticket

### Ticket Creation

- `TicketSource::TacticalRmm` — new enum value
- Priority mapping: Error→P2, Warning→P3, Informational→P4
- Subject: `[Tactical Alert] {severity} - {check_name} on {hostname}`
- Description: enriched with device info, check type, alert message
- Client/contact: from tactical_asset → asset → client. Contact resolved via `asset.primaryUser()` if available.
- Dedup: `client_id` + subject hash within 15-minute window (same pattern as Huntress)

### Ticket Resolution

When Tactical sends an alert-resolved webhook:
- Find open ticket by `client_id` + matching subject + `source = TacticalRmm`
- Set status to Resolved
- Add a system note: "Alert resolved automatically"

### Files to Create (Phase 2)

| File | Purpose |
|------|---------|
| `app/Http/Controllers/Api/TacticalWebhookController.php` | Webhook handler |
| `app/Http/Middleware/VerifyTacticalWebhookKey.php` | Auth middleware |
| `app/Services/Tactical/TacticalAlertService.php` | Alert → ticket logic |

### Files to Modify (Phase 2)

| File | Change |
|------|--------|
| `app/Enums/TicketSource.php` | Add `TacticalRmm` case |
| `routes/api.php` | Add webhook route |
| Triage `JunkDetector` | Add Tactical to `MONITORING_ALLOWLIST` if alerts come via email too |

---

## Phase 3 — Script Execution

### Script Library Sync

**New table: `tactical_scripts`**

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint PK | |
| `tactical_script_id` | integer, unique | Tactical's script ID |
| `name` | string | |
| `description` | text, nullable | |
| `shell` | string(20) | powershell, cmd, python |
| `category` | string, nullable | Tactical's script category |
| `synced_at` | datetime | |
| timestamps | | |

**`TacticalScriptSyncService`** — pulls script list from Tactical API, upserts into `tactical_scripts`. Scheduled daily after device sync.

### Script Runner — Asset Detail Page

**New card on asset detail page** (when Tactical agent is online):
- Dropdown: select script (from `tactical_scripts`, grouped by category)
- Optional: arguments text input
- Optional: timeout select (30s, 60s, 120s, 300s, 600s)
- "Run" button (with loading spinner)
- Results panel: shows stdout, stderr, return code, execution time
- AJAX-based (no page reload)

**Route:** `POST /assets/{asset}/tactical/run-script`
**Controller method:** `AssetController::runTacticalScript()`
- Validates asset has a Tactical agent and it's online
- Calls `TacticalClient::runScript()`
- Returns JSON response with output
- Logs execution for audit

### Script Runner — Ticket Detail Page

**Quick action in ticket sidebar** (when ticket has linked assets with Tactical agents):
- "Run Script" button opens a modal
- Select asset (from ticket's linked assets that have Tactical agents)
- Select script + optional args
- On execution: output is posted as a private ticket note with `NoteType::System`
- Note includes: script name, target device, output, return code

**Route:** `POST /tickets/{ticket}/tactical/run-script`
**Controller method:** `TicketController::runTacticalScript()` or `TacticalController::runScriptForTicket()`

### Files to Create (Phase 3)

| File | Purpose |
|------|---------|
| `database/migrations/XXXX_create_tactical_scripts_table.php` | Script library cache |
| `app/Models/TacticalScript.php` | Model |
| `app/Services/Tactical/TacticalScriptSyncService.php` | Script list sync |

### Files to Modify (Phase 3)

| File | Change |
|------|--------|
| `app/Http/Controllers/Web/AssetController.php` | Add `runTacticalScript()` method |
| `app/Http/Controllers/Web/TicketController.php` | Add `runTacticalScript()` method |
| `resources/views/assets/show.blade.php` | Add Script Runner card |
| `resources/views/tickets/show.blade.php` | Add Run Script quick action in sidebar |
| `routes/web.php` | Add script execution routes |
| `routes/console.php` | Schedule script sync |

---

## Scope Boundaries

- NinjaRMM integration is unchanged — both coexist
- Mac devices stay on a separate RMM (Level or similar)
- Patch management UI (approve/schedule from PSA) is Phase 4
- Software inventory display is Phase 4
- Contract-aware deployment recommendations are Phase 4
- No Tactical user/role management from PSA — use Tactical's own UI for that
- Tactical server setup/maintenance is manual (not automated by PSA)
