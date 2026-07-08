# Servosity Backup Deployment Pipeline — Design Spec

**Date:** 2026-04-02
**Status:** Approved

## Problem

Servosity backup needs to be deployed to client devices with a multi-step process: install Servosity One agent, provision DR backup accounts, install ScreenConnect remote access, and create device credentials. Currently this is entirely manual via the Servosity portal. We need PSA-driven automated deployment through Tactical RMM, following the same pattern as the Comet backup deployment pipeline.

## Solution

Toggle `servosity_backup_enabled` on an asset in PSA. PSA orchestrates the Servosity API calls (provision DR account, create credentials, fetch download URLs), then pushes config to Tactical RMM custom fields. A Tactical check script detects the fields + missing software and triggers a remediation task that installs everything.

## Deployment Flow

```
Tech toggles "Enable Servosity Backup" on asset
    │
    ▼
PSA: Get company detail from Servosity API
    → Extract agent_provision_token_id
    │
    ▼
PSA: Create DR backup account (POST /dr-backups/)
    → product_type = DR_DESKTOP or DR_SERVER (from asset_type)
    → device_name = asset hostname
    │
    ▼
PSA: Create credential (POST /credentials/)
    → username = svc-backup
    → password = random 24-char
    → domain = hostname
    │
    ▼
PSA: Get ScreenConnect download URL
    → GET /companies/{id}/connectwise-download-url/
    │
    ▼
PSA: Build Servosity One download URL
    → https://api.servosity.com/api/v1/companies/{id}/servosity-one/download/windows/latest/?token={agent_provision_token_id}
    │
    ▼
PSA: Push to Tactical RMM custom fields on the agent
    → ServosityOneUrl, ServosityScreenConnectUrl, ServosityCredUser, ServosityCredPass
    │
    ▼
PSA: Update asset record
    → servosity_backup_enabled = true
    → servosity_dr_backup_id = new backup ID
    │
    ▼
Tactical: Daily check detects fields + missing software → FAIL
    │
    ▼
Tactical: Remediation task installs Servosity One + ScreenConnect + credentials
```

## PSA-Side Components

### 1. ServosityClient — New API Methods

Add to `app/Services/Servosity/ServosityClient.php`:

- `getCompany(int $id): array` — `GET /companies/{id}/` — returns full company detail including `agent_provision_token_id`
- `createDrBackup(array $data): array` — `POST /dr-backups/` — provisions a DR backup account
- `createCredential(array $data): array` — `POST /credentials/` — creates a credential entry
- `getConnectWiseDownloadUrl(int $companyId): string` — `GET /companies/{id}/connectwise-download-url/` — extracts ScreenConnect download URL from response

### 2. ServosityDeploymentService — New File

`app/Services/Servosity/ServosityDeploymentService.php`

Orchestrates the full provisioning sequence when toggling backup on an asset:

**`enableBackup(Asset $asset): array`**
1. Validate: client has `servosity_company_id`, asset has a Tactical agent linked
2. Get company detail → extract `agent_provision_token_id`
3. Determine product type: `DR_SERVER` if asset_type contains "server", else `DR_DESKTOP`
4. Create DR backup account: `POST /dr-backups/` with `company`, `device_name` (hostname), `product_type`
5. Generate random 24-char password
6. Create credential: `POST /credentials/` with `company`, `name` (hostname), `username` = `svc-backup`, `password`, `domain` (hostname)
7. Build Servosity One URL: `{base_url}/api/v1/companies/{id}/servosity-one/download/windows/latest/?token={agent_provision_token_id}`
8. Get ScreenConnect URL via API
9. Push 4 custom fields to Tactical agent: `ServosityOneUrl`, `ServosityScreenConnectUrl`, `ServosityCredUser`, `ServosityCredPass`
10. Update asset: `servosity_backup_enabled = true`, `servosity_dr_backup_id`
11. Return summary of what was provisioned

**`disableBackup(Asset $asset): void`**
1. Clear Tactical custom fields (set all 4 to empty string)
2. Update asset: `servosity_backup_enabled = false`
3. Do NOT delete the Servosity DR backup account (data preservation)

### 3. ServosityConfig — New Constants

Add Tactical custom field ID constants (IDs assigned when fields are created in Tactical):

```php
const TACTICAL_SERVOSITY_ONE_URL_FIELD_ID = ?;      // Set after field creation
const TACTICAL_SERVOSITY_SC_URL_FIELD_ID = ?;        // Set after field creation
const TACTICAL_SERVOSITY_CRED_USER_FIELD_ID = ?;     // Set after field creation
const TACTICAL_SERVOSITY_CRED_PASS_FIELD_ID = ?;     // Set after field creation
```

### 4. AssetController — Toggle Endpoint

Add `toggleServosityBackup(Asset $asset)` to `app/Http/Controllers/Web/AssetController.php`:

- Mirrors the existing `toggleCometBackup()` pattern
- Calls `ServosityDeploymentService::enableBackup()` or `disableBackup()`
- Returns redirect with success/error flash message
- Guards: client must have `servosity_company_id`, asset must have Tactical agent

### 5. Database Migration

Add to `assets` table:
- `servosity_backup_enabled` (boolean, default false)
- `servosity_dr_backup_id` (unsigned integer, nullable) — FK-like reference to Servosity DR backup

### 6. Asset Show View

Add Servosity backup toggle button in the backup/deployment section of the asset detail page, following the Comet toggle pattern:
- Shows "Enable Servosity Backup" / "Disable Servosity Backup" based on current state
- Only visible when client has `servosity_company_id` mapped
- Disabled if no Tactical agent linked (with tooltip explaining why)

### 7. Route

```php
POST /assets/{asset}/servosity/toggle → AssetController@toggleServosityBackup
```

## Tactical RMM Components

### Custom Fields (created via Tactical API/UI)

| Field Name | Type | Scope | Purpose |
|-----------|------|-------|---------|
| `ServosityOneUrl` | string | agent | Servosity One installer URL with embedded token |
| `ServosityScreenConnectUrl` | string | agent | ScreenConnect installer download URL |
| `ServosityCredUser` | string | agent | Backup service account username |
| `ServosityCredPass` | string | agent | Backup service account password |

All fields must have `default_value_string = ''` to avoid unresolved template issues.

### Check Script

Daily check on policies 3 (workstation) and 4 (server):

```
IF ServosityOneUrl field has value:
    Check if Servosity One is installed (service or program check)
    Check if Servosity ScreenConnect is installed (service or program check)
    IF either missing → exit 1 (FAIL)
    ELSE → exit 0 (PASS)
ELSE:
    exit 0 (SKIP — not targeted for Servosity)
```

### Remediation Script

On check failure:
1. If Servosity One not installed: download from `ServosityOneUrl` field, run silent install
2. If ScreenConnect not installed: download from `ServosityScreenConnectUrl` field, run silent install
3. Configure local backup credentials: create local Windows user `svc-backup` with password from `ServosityCredPass`, add to Backup Operators group

## Credential Strategy

- Username: `svc-backup` (standard across all devices)
- Password: random 24-char alphanumeric, generated per device
- Stored in Servosity via `/credentials/` API (for the backup agent to use)
- Pushed to Tactical custom field (for the remediation script to create the local account)
- The credential is per-device (scoped to the company in Servosity, named after the hostname)

## Disable Behavior

When disabling Servosity backup on an asset:
- Clear all 4 Tactical custom fields → next check run passes (no URL = skip)
- Set `servosity_backup_enabled = false` on asset
- Do NOT delete the DR backup account in Servosity (preserves backup data)
- Do NOT uninstall Servosity One or ScreenConnect (manual cleanup if needed)

## Error Handling

- If Servosity API calls fail during enable: roll back any partial provisioning, flash error to tech
- If Tactical push fails: Servosity account is created but agent won't install until fields are set. Log warning, allow retry via re-toggle.
- DR backup creation is idempotent by device_name — if account already exists, the API returns the existing one (or errors, which we catch and surface).

## Tactical Script Creation (via Tactical API)

Scripts and custom fields are created via the Tactical RMM API as part of the implementation, following the same pattern used for the Comet deployment pipeline.

### Custom Fields — Created via `POST /core/customfields/`

4 agent-scoped custom fields with `default_value_string = ''`:
- `ServosityOneUrl`
- `ServosityScreenConnectUrl`
- `ServosityCredUser`
- `ServosityCredPass`

Field IDs are stored in `ServosityConfig` constants after creation.

### Check Script — Created via `POST /scripts/`

Category: `SITS:Checks`. PowerShell. Runs daily on policies 3 (workstation) + 4 (server).

Logic:
```
param([string]$ServosityOneUrl)

# If no URL set, this device isn't targeted — pass
if (-not $ServosityOneUrl) { exit 0 }

# Check for Servosity One (service or installed program)
$svcOne = Get-Service -Name "ServosityOne*" -ErrorAction SilentlyContinue
$progOne = Get-ItemProperty "HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall\*" |
    Where-Object { $_.DisplayName -like "*Servosity*One*" }

# Check for Servosity ScreenConnect
$svcSC = Get-Service -Name "ScreenConnect*Servosity*" -ErrorAction SilentlyContinue
$progSC = Get-ItemProperty "HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall\*" |
    Where-Object { $_.DisplayName -like "*ScreenConnect*" -and $_.Publisher -like "*Servosity*" }

$oneInstalled = ($svcOne -or $progOne)
$scInstalled = ($svcSC -or $progSC)

if (-not $oneInstalled) { Write-Output "Servosity One not installed"; exit 1 }
if (-not $scInstalled) { Write-Output "Servosity ScreenConnect not installed"; exit 1 }

Write-Output "Servosity One and ScreenConnect installed"
exit 0
```

Arguments: `['-ServosityOneUrl', '{{agent.ServosityOneUrl}}']`
`fails_b4_alert = 2`, return codes 65 and 98 as informational.

### Remediation Script — Created via `POST /scripts/`

Category: `SITS:Deploy`. PowerShell. Triggered as remediation task on check failure.

Logic:
```
param(
    [string]$ServosityOneUrl,
    [string]$ServosityScreenConnectUrl,
    [string]$ServosityCredUser,
    [string]$ServosityCredPass
)

$tempDir = "$env:ProgramData\TacticalRMM\servosity"
New-Item -ItemType Directory -Path $tempDir -Force | Out-Null

# Install Servosity One if missing
$svcOne = Get-Service -Name "ServosityOne*" -ErrorAction SilentlyContinue
if (-not $svcOne -and $ServosityOneUrl) {
    $installer = "$tempDir\ServosityOneSetup.exe"
    Invoke-WebRequest -Uri $ServosityOneUrl -OutFile $installer -UseBasicParsing
    Start-Process -FilePath $installer -ArgumentList "/S" -Wait -NoNewWindow
    Write-Output "Servosity One installed"
}

# Install ScreenConnect if missing
$svcSC = Get-Service -Name "ScreenConnect*Servosity*" -ErrorAction SilentlyContinue
if (-not $svcSC -and $ServosityScreenConnectUrl) {
    $scInstaller = "$tempDir\ServosityScreenConnect.exe"
    Invoke-WebRequest -Uri $ServosityScreenConnectUrl -OutFile $scInstaller -UseBasicParsing
    Start-Process -FilePath $scInstaller -ArgumentList "/S" -Wait -NoNewWindow
    Write-Output "Servosity ScreenConnect installed"
}

# Create local backup service account if it doesn't exist
if ($ServosityCredUser -and $ServosityCredPass) {
    $user = Get-LocalUser -Name $ServosityCredUser -ErrorAction SilentlyContinue
    if (-not $user) {
        $secPass = ConvertTo-SecureString $ServosityCredPass -AsPlainText -Force
        New-LocalUser -Name $ServosityCredUser -Password $secPass -PasswordNeverExpires -Description "Servosity backup service account"
        Add-LocalGroupMember -Group "Backup Operators" -Member $ServosityCredUser
        Write-Output "Created local user $ServosityCredUser"
    } else {
        Write-Output "Local user $ServosityCredUser already exists"
    }
}

# Cleanup
Remove-Item -Path $tempDir -Recurse -Force -ErrorAction SilentlyContinue
Write-Output "Servosity deployment complete"
exit 0
```

Arguments: `['-ServosityOneUrl', '{{agent.ServosityOneUrl}}', '-ServosityScreenConnectUrl', '{{agent.ServosityScreenConnectUrl}}', '-ServosityCredUser', '{{agent.ServosityCredUser}}', '-ServosityCredPass', '{{agent.ServosityCredPass}}']`

### Policy Check Assignment

After scripts are created, add the check to policies 3 and 4 via `POST /automation/policies/{id}/checks/` with the check script ID, daily interval (86400 seconds), and the remediation script as the associated task.

## What Doesn't Change

- Existing Servosity license sync (`ServosityLicenseSyncService`) — continues as-is
- Client-to-company mapping (`servosity_company_id`) — already in place
- Servosity alert/webhook handling — not part of this feature
- No new scheduled commands

## Files Summary

| File | Change |
|------|--------|
| `app/Services/Servosity/ServosityClient.php` | Add `getCompany()`, `createDrBackup()`, `createCredential()`, `getConnectWiseDownloadUrl()`, `post()` |
| `app/Services/Servosity/ServosityDeploymentService.php` | **New** — deployment orchestration |
| `app/Support/ServosityConfig.php` | Add Tactical field ID constants |
| `app/Http/Controllers/Web/AssetController.php` | Add `toggleServosityBackup()` |
| `database/migrations/...` | Add `servosity_backup_enabled`, `servosity_dr_backup_id` to assets |
| `resources/views/assets/show.blade.php` | Add toggle button |
| `routes/web.php` | Add toggle route |

Tactical RMM (created via API, not PSA files):
- 4 custom fields (agent-scoped)
- 1 check script (SITS:Checks category)
- 1 remediation script (SITS:Deploy category)
- 2 policy check assignments (policies 3 + 4)
