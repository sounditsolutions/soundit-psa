# Servosity Backup Deployment Pipeline — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Enable techs to toggle Servosity DR backup on any asset, which provisions a backup account + credentials in Servosity's API, then pushes config to Tactical RMM for automated agent installation.

**Architecture:** Asset toggle in PSA triggers `ServosityDeploymentService` which calls the Servosity API to create a DR backup account, create device credentials, and fetch download URLs. All config is pushed to Tactical RMM custom fields. A Tactical check script detects the fields + missing software and triggers a remediation script that installs Servosity One + ScreenConnect + creates a local backup user.

**Tech Stack:** Laravel 12 / PHP 8.3, Servosity REST API (Token auth, Django REST pagination), Tactical RMM API (X-API-KEY auth), PowerShell scripts on Windows agents.

**Spec:** `docs/superpowers/specs/2026-04-02-servosity-deployment-pipeline-design.md`

---

## File Structure

| File | Responsibility |
|------|---------------|
| `app/Services/Servosity/ServosityClient.php` | **Modify** — Add `post()`, `getCompany()`, `createDrBackup()`, `createCredential()`, `getConnectWiseDownloadUrl()` |
| `app/Services/Servosity/ServosityDeploymentService.php` | **Create** — Orchestrates enable/disable: API provisioning + Tactical push |
| `app/Support/ServosityConfig.php` | **Modify** — Add Tactical custom field ID constants |
| `app/Http/Controllers/Web/AssetController.php` | **Modify** — Add `toggleServosityBackup()` method |
| `database/migrations/2026_04_02_000001_add_servosity_backup_fields_to_assets.php` | **Create** — Add `servosity_backup_enabled`, `servosity_dr_backup_id` |
| `app/Models/Asset.php` | **Modify** — Add new columns to `$fillable` and `$casts` |
| `resources/views/assets/show.blade.php` | **Modify** — Add Servosity toggle in Backup tab |
| `routes/web.php` | **Modify** — Add toggle route |

---

### Task 1: Database Migration

Add Servosity backup fields to the `assets` table.

**Files:**
- Create: `database/migrations/2026_04_02_000001_add_servosity_backup_fields_to_assets.php`
- Modify: `app/Models/Asset.php`

- [ ] **Step 1: Create migration**

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
            $table->boolean('servosity_backup_enabled')->default(false)->after('comet_backup_enabled');
            $table->unsignedBigInteger('servosity_dr_backup_id')->nullable()->after('servosity_backup_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn(['servosity_backup_enabled', 'servosity_dr_backup_id']);
        });
    }
};
```

- [ ] **Step 2: Add columns to Asset model**

In `app/Models/Asset.php`, add to the `$fillable` array (after `comet_backup_enabled` on line 66):

```php
'servosity_backup_enabled',
'servosity_dr_backup_id',
```

In the `$casts` array (after the `comet_backup_enabled` cast):

```php
'servosity_backup_enabled' => 'boolean',
```

- [ ] **Step 3: Run migration**

Run: `php artisan migrate`
Expected: Migration runs successfully, two columns added to assets table.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_04_02_000001_add_servosity_backup_fields_to_assets.php app/Models/Asset.php
git commit -m "Add servosity_backup_enabled and servosity_dr_backup_id to assets"
```

---

### Task 2: ServosityClient API Methods

Add `post()`, `getCompany()`, `createDrBackup()`, `createCredential()`, and `getConnectWiseDownloadUrl()` to the existing client.

**Files:**
- Modify: `app/Services/Servosity/ServosityClient.php`

- [ ] **Step 1: Add `post()` method**

Add after the existing `get()` method (line 34):

```php
/**
 * Make an authenticated POST request to the Servosity API.
 */
public function post(string $endpoint, array $data = []): array
{
    return $this->request('POST', $endpoint, ['json' => $data]);
}
```

- [ ] **Step 2: Add `getCompany()` method**

Add after `getCompanies()` (line 80):

```php
/**
 * Get full company detail including agent_provision_token_id.
 */
public function getCompany(int $companyId): array
{
    return $this->get("companies/{$companyId}/");
}
```

- [ ] **Step 3: Add `createDrBackup()` method**

```php
/**
 * Create a DR backup account.
 *
 * @param array{company: int, device_name: string, product_type: string} $data
 *   product_type: DR_DESKTOP, DR_SERVER, or DR_LINUX
 */
public function createDrBackup(array $data): array
{
    return $this->post('dr-backups/', $data);
}
```

- [ ] **Step 4: Add `createCredential()` method**

```php
/**
 * Create a credential entry for a company.
 *
 * @param array{company: int, name: string, username: string, password: string, domain: string} $data
 */
public function createCredential(array $data): array
{
    return $this->post('credentials/', $data);
}
```

- [ ] **Step 5: Add `getConnectWiseDownloadUrl()` method**

```php
/**
 * Get the Servosity ScreenConnect download URL for a company.
 */
public function getConnectWiseDownloadUrl(int $companyId): ?string
{
    $response = $this->get("companies/{$companyId}/connectwise-download-url/");

    return $response['connectwise_download_url'] ?? null;
}
```

- [ ] **Step 6: Verify syntax**

Run: `php -l app/Services/Servosity/ServosityClient.php`
Expected: `No syntax errors detected`

- [ ] **Step 7: Commit**

```bash
git add app/Services/Servosity/ServosityClient.php
git commit -m "Add post, getCompany, createDrBackup, createCredential, getConnectWiseDownloadUrl to ServosityClient"
```

---

### Task 3: Create Tactical Custom Fields

Create the 4 agent-scoped custom fields in Tactical RMM via the API, then store the IDs in ServosityConfig.

**Files:**
- Modify: `app/Support/ServosityConfig.php`

- [ ] **Step 1: Create custom fields via Tactical API**

Run in tinker (this is a one-time setup step — creates real Tactical fields):

```php
$client = new \App\Services\Tactical\TacticalClient();

$fields = ['ServosityOneUrl', 'ServosityScreenConnectUrl', 'ServosityCredUser', 'ServosityCredPass'];

foreach ($fields as $name) {
    $result = $client->post('core/customfields/', [
        'model' => 'agent',
        'name' => $name,
        'type' => 'text',
        'default_value_string' => '',
    ]);
    echo $name . ' → ID: ' . $result['id'] . PHP_EOL;
}
```

Record the 4 IDs that are returned. They will be used in the next step.

- [ ] **Step 2: Add field ID constants to ServosityConfig**

Add constants to `app/Support/ServosityConfig.php` (use the actual IDs from step 1):

```php
class ServosityConfig
{
    // Tactical RMM custom field IDs for Servosity deployment
    public const TACTICAL_SERVOSITY_ONE_URL_FIELD_ID = ??;     // Replace with actual ID
    public const TACTICAL_SERVOSITY_SC_URL_FIELD_ID = ??;      // Replace with actual ID
    public const TACTICAL_SERVOSITY_CRED_USER_FIELD_ID = ??;   // Replace with actual ID
    public const TACTICAL_SERVOSITY_CRED_PASS_FIELD_ID = ??;   // Replace with actual ID
```

Place these above the existing `get()` method.

- [ ] **Step 3: Commit**

```bash
git add app/Support/ServosityConfig.php
git commit -m "Add Tactical custom field IDs for Servosity deployment"
```

---

### Task 4: ServosityDeploymentService

Orchestrates the full enable/disable flow: Servosity API provisioning + Tactical custom field push.

**Files:**
- Create: `app/Services/Servosity/ServosityDeploymentService.php`

- [ ] **Step 1: Create the service**

```php
<?php

namespace App\Services\Servosity;

use App\Models\Asset;
use App\Services\Tactical\TacticalClient;
use App\Support\ServosityConfig;
use App\Support\TacticalConfig;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ServosityDeploymentService
{
    private ServosityClient $servosity;

    public function __construct()
    {
        $this->servosity = new ServosityClient([
            'api_token' => ServosityConfig::get('api_token'),
            'base_url' => ServosityConfig::get('base_url'),
        ]);
    }

    /**
     * Enable Servosity backup for an asset.
     *
     * Creates a DR backup account, generates credentials, fetches download URLs,
     * and pushes everything to Tactical RMM custom fields.
     *
     * @return array Summary of what was provisioned
     * @throws \RuntimeException If provisioning fails
     */
    public function enableBackup(Asset $asset): array
    {
        $client = $asset->client;
        $companyId = $client->servosity_company_id;

        if (! $companyId) {
            throw new \RuntimeException('Client does not have a Servosity company mapping.');
        }

        // 1. Get company detail for agent_provision_token_id
        $company = $this->servosity->getCompany($companyId);
        $provisionToken = $company['agent_provision_token_id'] ?? null;

        if (! $provisionToken) {
            throw new \RuntimeException('Servosity company has no agent provision token.');
        }

        // 2. Determine product type from asset_type
        $productType = $this->resolveProductType($asset);

        // 3. Create DR backup account
        $drBackup = $this->servosity->createDrBackup([
            'company' => $companyId,
            'device_name' => $asset->hostname,
            'product_type' => $productType,
        ]);

        $drBackupId = $drBackup['id'] ?? null;

        Log::info('[Servosity] Created DR backup account', [
            'asset_id' => $asset->id,
            'hostname' => $asset->hostname,
            'product_type' => $productType,
            'dr_backup_id' => $drBackupId,
        ]);

        // 4. Generate credential
        $password = Str::random(24);
        $credential = $this->servosity->createCredential([
            'company' => $companyId,
            'name' => $asset->hostname,
            'username' => 'svc-backup',
            'password' => $password,
            'domain' => $asset->hostname,
        ]);

        Log::info('[Servosity] Created credential', [
            'asset_id' => $asset->id,
            'credential_id' => $credential['id'] ?? null,
        ]);

        // 5. Build download URLs
        $baseUrl = rtrim(ServosityConfig::get('base_url') ?? 'https://api.servosity.com', '/');
        $servosityOneUrl = "{$baseUrl}/api/v1/companies/{$companyId}/servosity-one/download/windows/latest/?token={$provisionToken}";

        $screenConnectUrl = $this->servosity->getConnectWiseDownloadUrl($companyId);

        // 6. Push to Tactical custom fields
        $this->pushToTactical($asset, $servosityOneUrl, $screenConnectUrl, 'svc-backup', $password);

        // 7. Update asset
        $asset->update([
            'servosity_backup_enabled' => true,
            'servosity_dr_backup_id' => $drBackupId,
        ]);

        return [
            'dr_backup_id' => $drBackupId,
            'product_type' => $productType,
            'credential_id' => $credential['id'] ?? null,
            'servosity_one_url' => $servosityOneUrl,
            'screen_connect_url' => $screenConnectUrl ? 'set' : 'unavailable',
        ];
    }

    /**
     * Disable Servosity backup for an asset.
     *
     * Clears Tactical custom fields and updates the asset flag.
     * Does NOT delete the Servosity DR backup account (data preservation).
     */
    public function disableBackup(Asset $asset): void
    {
        $this->pushToTactical($asset, '', '', '', '');

        $asset->update([
            'servosity_backup_enabled' => false,
        ]);

        Log::info('[Servosity] Disabled backup', [
            'asset_id' => $asset->id,
            'hostname' => $asset->hostname,
        ]);
    }

    /**
     * Determine DR product type from asset_type.
     *
     * Server types → DR_SERVER, everything else → DR_DESKTOP.
     */
    private function resolveProductType(Asset $asset): string
    {
        $type = strtolower($asset->asset_type ?? '');

        if (str_contains($type, 'server') || $type === 'windows_server') {
            return 'DR_SERVER';
        }

        return 'DR_DESKTOP';
    }

    /**
     * Push Servosity deployment config to Tactical RMM custom fields.
     */
    private function pushToTactical(
        Asset $asset,
        string $servosityOneUrl,
        ?string $screenConnectUrl,
        string $credUser,
        string $credPass,
    ): void {
        $tacticalAsset = $asset->tacticalAsset;

        if (! $tacticalAsset) {
            Log::warning('[Servosity] No Tactical agent linked, skipping field push', [
                'asset_id' => $asset->id,
                'hostname' => $asset->hostname,
            ]);
            return;
        }

        if (! TacticalConfig::isConfigured()) {
            Log::warning('[Servosity] Tactical not configured, skipping field push');
            return;
        }

        $tactical = new TacticalClient();
        $agentId = $tacticalAsset->agent_id;

        $fields = [
            ServosityConfig::TACTICAL_SERVOSITY_ONE_URL_FIELD_ID => $servosityOneUrl,
            ServosityConfig::TACTICAL_SERVOSITY_SC_URL_FIELD_ID => $screenConnectUrl ?? '',
            ServosityConfig::TACTICAL_SERVOSITY_CRED_USER_FIELD_ID => $credUser,
            ServosityConfig::TACTICAL_SERVOSITY_CRED_PASS_FIELD_ID => $credPass,
        ];

        foreach ($fields as $fieldId => $value) {
            $tactical->setAgentCustomField($agentId, $fieldId, $value);
        }

        Log::info('[Servosity] Pushed config to Tactical agent', [
            'asset_id' => $asset->id,
            'agent_id' => $agentId,
        ]);
    }
}
```

- [ ] **Step 2: Verify syntax**

Run: `php -l app/Services/Servosity/ServosityDeploymentService.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add app/Services/Servosity/ServosityDeploymentService.php
git commit -m "Add ServosityDeploymentService for backup provisioning and Tactical push"
```

---

### Task 5: Controller Toggle + Route

Add `toggleServosityBackup()` to AssetController and the corresponding route.

**Files:**
- Modify: `app/Http/Controllers/Web/AssetController.php`
- Modify: `routes/web.php`

- [ ] **Step 1: Add controller method**

Add `toggleServosityBackup()` to `app/Http/Controllers/Web/AssetController.php` after the existing `toggleCometBackup()` method. Add the import for `ServosityDeploymentService` at the top of the file.

Import to add at top:

```php
use App\Services\Servosity\ServosityDeploymentService;
```

Method to add:

```php
public function toggleServosityBackup(Request $request, Asset $asset)
{
    $redirectTo = url()->previous(route('clients.show', $asset->client_id));

    // Disabling — simple path
    if ($asset->servosity_backup_enabled) {
        try {
            $service = new ServosityDeploymentService();
            $service->disableBackup($asset);
        } catch (\Exception $e) {
            Log::warning("[Servosity] Failed to disable backup for {$asset->hostname}: " . $e->getMessage());
            $asset->update(['servosity_backup_enabled' => false]);
        }

        return redirect($redirectTo)->with('success', "Servosity backup disabled for {$asset->hostname}.");
    }

    // Enabling — requires client mapping and Tactical agent
    if (! $asset->client?->servosity_company_id) {
        return redirect($redirectTo)->with('error', 'Client does not have a Servosity company mapping.');
    }

    // Auto-link Tactical agent by hostname if needed
    $tacticalAsset = $asset->tacticalAsset;
    if (! $tacticalAsset && \App\Support\TacticalConfig::isConfigured() && $asset->hostname) {
        try {
            $tacticalClient = new \App\Services\Tactical\TacticalClient();
            $agents = $tacticalClient->getAgents();
            foreach ($agents as $agent) {
                if (strcasecmp($agent['hostname'] ?? '', $asset->hostname) === 0) {
                    $ta = \App\Models\TacticalAsset::updateOrCreate(
                        ['agent_id' => $agent['agent_id']],
                        ['hostname' => $agent['hostname'], 'asset_id' => $asset->id]
                    );
                    $asset->update(['tactical_asset_id' => $ta->id]);
                    $asset->refresh();
                    Log::info("[Servosity] Auto-linked Tactical agent for {$asset->hostname}");
                    break;
                }
            }
        } catch (\Exception $e) {
            Log::warning("[Servosity] Failed to auto-link Tactical agent: " . $e->getMessage());
        }
    }

    if (! $asset->tacticalAsset) {
        return redirect($redirectTo)->with('error', "No Tactical RMM agent found for {$asset->hostname}. Link the device first.");
    }

    try {
        $service = new ServosityDeploymentService();
        $result = $service->enableBackup($asset);

        $type = $result['product_type'] === 'DR_SERVER' ? 'DR Server' : 'DR Desktop';
        return redirect($redirectTo)->with('success', "Servosity {$type} backup enabled for {$asset->hostname}.");
    } catch (\Exception $e) {
        Log::error("[Servosity] Failed to enable backup for {$asset->hostname}: " . $e->getMessage());
        return redirect($redirectTo)->with('error', "Failed to enable Servosity backup: " . $e->getMessage());
    }
}
```

- [ ] **Step 2: Add route**

In `routes/web.php`, add after the existing `assets.comet.toggle-backup` route (around line 420):

```php
Route::post('/assets/{asset}/servosity/toggle-backup', [AssetController::class, 'toggleServosityBackup'])->name('assets.servosity.toggle-backup');
```

- [ ] **Step 3: Verify syntax**

Run: `php -l app/Http/Controllers/Web/AssetController.php && php -l routes/web.php`
Expected: `No syntax errors detected` for both files.

- [ ] **Step 4: Commit**

```bash
git add app/Http/Controllers/Web/AssetController.php routes/web.php
git commit -m "Add toggleServosityBackup controller action and route"
```

---

### Task 6: Blade Toggle UI

Add the Servosity backup toggle to the Backup tab on the asset detail page, alongside the existing Comet toggle.

**Files:**
- Modify: `resources/views/assets/show.blade.php`

- [ ] **Step 1: Add Servosity toggle**

In `resources/views/assets/show.blade.php`, immediately after the Comet toggle block's `@endif` (line 1324), add:

```blade
@if($asset->client && $asset->client->servosity_company_id)
    <div class="d-flex align-items-center gap-2 mb-3">
        <form action="{{ route('assets.servosity.toggle-backup', $asset) }}" method="POST" class="d-inline">
            @csrf
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox"
                       {{ $asset->servosity_backup_enabled ? 'checked' : '' }}
                       {{ !$asset->tacticalAsset && !$asset->servosity_backup_enabled ? 'disabled' : '' }}
                       onchange="this.form.submit()">
                <label class="form-check-label">
                    Servosity: {{ $asset->servosity_backup_enabled ? 'Backup enabled' : 'Backup not enabled' }}
                    @if(!$asset->tacticalAsset && !$asset->servosity_backup_enabled)
                        <small class="text-muted">(no Tactical agent linked)</small>
                    @endif
                </label>
            </div>
        </form>
    </div>
@endif
```

- [ ] **Step 2: Verify syntax**

Run: `php -l resources/views/assets/show.blade.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add resources/views/assets/show.blade.php
git commit -m "Add Servosity backup toggle to asset Backup tab"
```

---

### Task 7: Tactical Check Script

Create the Servosity deployment check script in Tactical RMM via the API.

**Files:** None (Tactical API call, no PSA file changes)

- [ ] **Step 1: Create check script in Tactical**

Run in tinker:

```php
$client = new \App\Services\Tactical\TacticalClient();

$checkScript = $client->post('scripts/', [
    'name' => 'Servosity Deployment Check',
    'description' => 'Verifies Servosity One and ScreenConnect are installed when deployment is enabled.',
    'shell' => 'powershell',
    'category' => 'SITS:Checks',
    'default_timeout' => 60,
    'script_body' => base64_encode('param([string]$ServosityOneUrl)

# If no URL set, this device is not targeted
if (-not $ServosityOneUrl) { exit 0 }

# Servosity ScreenConnect package ID (from existing Check: ScreenConnect Health script 192)
$ServositySCPackageId = "4656fb5a3d1e851d"

# Check for Servosity One
$progOne = Get-ItemProperty "HKLM:\\SOFTWARE\\Microsoft\\Windows\\CurrentVersion\\Uninstall\\*",
    "HKLM:\\SOFTWARE\\WOW6432Node\\Microsoft\\Windows\\CurrentVersion\\Uninstall\\*" -ErrorAction SilentlyContinue |
    Where-Object { $_.DisplayName -like "*Servosity*One*" }
$svcOne = Get-Service -Name "ServosityOne*" -ErrorAction SilentlyContinue

# Check for Servosity ScreenConnect by package ID
$progSC = Get-ItemProperty "HKLM:\\SOFTWARE\\Microsoft\\Windows\\CurrentVersion\\Uninstall\\*",
    "HKLM:\\SOFTWARE\\WOW6432Node\\Microsoft\\Windows\\CurrentVersion\\Uninstall\\*" -ErrorAction SilentlyContinue |
    Where-Object { $_.DisplayName -ilike "ScreenConnect Client ($ServositySCPackageId)" }
$svcSC = Get-Service -ErrorAction SilentlyContinue |
    Where-Object { $_.DisplayName -ilike "ScreenConnect Client ($ServositySCPackageId)" }

$oneInstalled = [bool]($progOne -or $svcOne)
$scInstalled = [bool]($progSC -or $svcSC)

if (-not $oneInstalled -and -not $scInstalled) {
    Write-Output "Servosity One and ScreenConnect not installed"
    exit 1
}
if (-not $oneInstalled) {
    Write-Output "Servosity One not installed"
    exit 1
}
if (-not $scInstalled) {
    Write-Output "Servosity ScreenConnect not installed"
    exit 1
}

Write-Output "Servosity One and ScreenConnect installed"
exit 0'),
    'args' => ['-ServosityOneUrl', '{{agent.ServosityOneUrl}}'],
]);

echo 'Check script ID: ' . $checkScript['id'] . PHP_EOL;
```

Record the returned script ID.

- [ ] **Step 2: Create remediation script in Tactical**

```php
$client = new \App\Services\Tactical\TacticalClient();

$remediationScript = $client->post('scripts/', [
    'name' => 'Servosity Deployment Install',
    'description' => 'Installs Servosity One, ScreenConnect, and creates local backup service account.',
    'shell' => 'powershell',
    'category' => 'SITS:Deploy',
    'default_timeout' => 600,
    'script_body' => base64_encode('param(
    [string]$ServosityOneUrl,
    [string]$ServosityScreenConnectUrl,
    [string]$ServosityCredUser,
    [string]$ServosityCredPass
)

$ErrorActionPreference = "Stop"
$tempDir = "$env:ProgramData\TacticalRMM\servosity"
New-Item -ItemType Directory -Path $tempDir -Force | Out-Null

# Servosity ScreenConnect package ID (from existing Check: ScreenConnect Health script 192)
$ServositySCPackageId = "4656fb5a3d1e851d"

# Check for Servosity One
$progOne = Get-ItemProperty "HKLM:\\SOFTWARE\\Microsoft\\Windows\\CurrentVersion\\Uninstall\\*",
    "HKLM:\\SOFTWARE\\WOW6432Node\\Microsoft\\Windows\\CurrentVersion\\Uninstall\\*" -ErrorAction SilentlyContinue |
    Where-Object { $_.DisplayName -like "*Servosity*One*" }
$svcOne = Get-Service -Name "ServosityOne*" -ErrorAction SilentlyContinue
$oneInstalled = [bool]($progOne -or $svcOne)

# Check for Servosity ScreenConnect by package ID
$progSC = Get-ItemProperty "HKLM:\\SOFTWARE\\Microsoft\\Windows\\CurrentVersion\\Uninstall\\*",
    "HKLM:\\SOFTWARE\\WOW6432Node\\Microsoft\\Windows\\CurrentVersion\\Uninstall\\*" -ErrorAction SilentlyContinue |
    Where-Object { $_.DisplayName -ilike "ScreenConnect Client ($ServositySCPackageId)" }
$svcSC = Get-Service -ErrorAction SilentlyContinue |
    Where-Object { $_.DisplayName -ilike "ScreenConnect Client ($ServositySCPackageId)" }
$scInstalled = [bool]($progSC -or $svcSC)

# Install Servosity One if missing
if (-not $oneInstalled -and $ServosityOneUrl) {
    Write-Output "Downloading Servosity One..."
    $installer = "$tempDir\ServosityOneSetup.exe"
    [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
    Invoke-WebRequest -Uri $ServosityOneUrl -OutFile $installer -UseBasicParsing
    Write-Output "Installing Servosity One..."
    $proc = Start-Process -FilePath $installer -ArgumentList "/SP- /VERYSILENT" -Wait -NoNewWindow -PassThru
    if ($proc.ExitCode -ne 0) {
        Write-Output "Servosity One installer exited with code $($proc.ExitCode)"
        exit 1
    }
    Write-Output "Servosity One installed successfully"
} elseif ($oneInstalled) {
    Write-Output "Servosity One already installed"
}

# Install ScreenConnect if missing
if (-not $scInstalled -and $ServosityScreenConnectUrl) {
    Write-Output "Downloading Servosity ScreenConnect..."
    $scFile = "$tempDir\ServosityScreenConnect.msi"
    [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
    Invoke-WebRequest -Uri $ServosityScreenConnectUrl -OutFile $scFile -UseBasicParsing
    Write-Output "Installing Servosity ScreenConnect..."
    $proc = Start-Process -FilePath "msiexec.exe" -ArgumentList "/i `"$scFile`" /qn /norestart" -Wait -NoNewWindow -PassThru
    if ($proc.ExitCode -ne 0 -and $proc.ExitCode -ne 3010) {
        Write-Output "ScreenConnect installer exited with code $($proc.ExitCode)"
        exit 1
    }
    Write-Output "Servosity ScreenConnect installed successfully"
} elseif ($scInstalled) {
    Write-Output "Servosity ScreenConnect already installed"
}

# Create local backup service account if it does not exist
if ($ServosityCredUser -and $ServosityCredPass) {
    $user = Get-LocalUser -Name $ServosityCredUser -ErrorAction SilentlyContinue
    if (-not $user) {
        Write-Output "Creating local user $ServosityCredUser..."
        $secPass = ConvertTo-SecureString $ServosityCredPass -AsPlainText -Force
        New-LocalUser -Name $ServosityCredUser -Password $secPass -PasswordNeverExpires -Description "Servosity backup service account"
        Add-LocalGroupMember -Group "Backup Operators" -Member $ServosityCredUser
        Write-Output "Created local user $ServosityCredUser in Backup Operators group"
    } else {
        Write-Output "Local user $ServosityCredUser already exists"
    }
}

# Cleanup
Remove-Item -Path $tempDir -Recurse -Force -ErrorAction SilentlyContinue
Write-Output "Servosity deployment complete"
exit 0'),
    'args' => [
        '-ServosityOneUrl', '{{agent.ServosityOneUrl}}',
        '-ServosityScreenConnectUrl', '{{agent.ServosityScreenConnectUrl}}',
        '-ServosityCredUser', '{{agent.ServosityCredUser}}',
        '-ServosityCredPass', '{{agent.ServosityCredPass}}',
    ],
]);

echo 'Remediation script ID: ' . $remediationScript['id'] . PHP_EOL;
```

Record the returned script ID.

- [ ] **Step 3: Add check to workstation policy (policy 3)**

```php
$client = new \App\Services\Tactical\TacticalClient();
$checkScriptId = ??; // The check script ID from step 1
$remediationScriptId = ??; // The remediation script ID from step 2

$client->post('automation/policies/3/checks/', [
    'check_type' => 'script',
    'script' => $checkScriptId,
    'timeout' => 60,
    'fails_b4_alert' => 2,
    'run_interval' => 86400,
    'info_return_codes' => [65, 98],
    'script_args' => ['-ServosityOneUrl', '{{agent.ServosityOneUrl}}'],
    'assigned_task' => [
        'script' => $remediationScriptId,
        'script_args' => [
            '-ServosityOneUrl', '{{agent.ServosityOneUrl}}',
            '-ServosityScreenConnectUrl', '{{agent.ServosityScreenConnectUrl}}',
            '-ServosityCredUser', '{{agent.ServosityCredUser}}',
            '-ServosityCredPass', '{{agent.ServosityCredPass}}',
        ],
        'timeout' => 600,
        'enabled' => true,
    ],
]);

echo 'Workstation policy check created' . PHP_EOL;
```

- [ ] **Step 4: Add check to server policy (policy 4)**

Same as step 3 but with policy ID 4:

```php
$client->post('automation/policies/4/checks/', [
    'check_type' => 'script',
    'script' => $checkScriptId,
    'timeout' => 60,
    'fails_b4_alert' => 2,
    'run_interval' => 86400,
    'info_return_codes' => [65, 98],
    'script_args' => ['-ServosityOneUrl', '{{agent.ServosityOneUrl}}'],
    'assigned_task' => [
        'script' => $remediationScriptId,
        'script_args' => [
            '-ServosityOneUrl', '{{agent.ServosityOneUrl}}',
            '-ServosityScreenConnectUrl', '{{agent.ServosityScreenConnectUrl}}',
            '-ServosityCredUser', '{{agent.ServosityCredUser}}',
            '-ServosityCredPass', '{{agent.ServosityCredPass}}',
        ],
        'timeout' => 600,
        'enabled' => true,
    ],
]);

echo 'Server policy check created' . PHP_EOL;
```

- [ ] **Step 5: Commit config (no file changes, but document the script IDs)**

No git commit needed for this task — the scripts live in Tactical, not in the PSA codebase. The field IDs were committed in Task 3.

---

### Task 8: Deploy and End-to-End Test

Deploy the changes and test the full pipeline on a real device.

**Files:** None (deployment and testing only)

- [ ] **Step 1: Push to GitHub and deploy**

```bash
git push
```

Then deploy to VPS:
```bash
ssh your-vps "cd /var/www/psa && git pull && composer install --no-dev --optimize-autoloader && php artisan migrate --force && php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan queue:restart"
```

Clear OPcache:
```bash
ssh your-vps "echo '<?php opcache_reset(); echo \"ok\";' > /var/www/psa/public/opcache-clear.php && curl -s https://your-psa-domain/opcache-clear.php && rm /var/www/psa/public/opcache-clear.php"
```

- [ ] **Step 2: Verify UI**

Navigate to an asset that belongs to a client with `servosity_company_id` set. Open the Backup tab. Confirm:
- Servosity toggle switch is visible
- If no Tactical agent is linked, the toggle is disabled with "(no Tactical agent linked)" label
- If Tactical agent is linked, the toggle is enabled

- [ ] **Step 3: Test enable on a real device**

Toggle Servosity backup ON for a test device. Verify:
1. DR backup account was created in the Servosity portal
2. Credential was created in the Servosity portal
3. Tactical custom fields are populated on the agent (check in Tactical UI)
4. Asset shows `servosity_backup_enabled = true` in PSA
5. Flash message shows success with correct product type (DR Desktop or DR Server)

- [ ] **Step 4: Test disable**

Toggle Servosity backup OFF for the same device. Verify:
1. Tactical custom fields are cleared (empty strings)
2. Asset shows `servosity_backup_enabled = false`
3. DR backup account still exists in Servosity (not deleted)

- [ ] **Step 5: Verify Tactical check fires**

Wait for the next check interval (or trigger manually in Tactical). Confirm:
- Check script runs and returns FAIL (Servosity One not installed yet)
- Remediation task triggers and attempts installation
- After installation, next check run passes
