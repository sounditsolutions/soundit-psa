# RMM Agent Installer Downloads in Client Portal — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship a public, per-client URL that lets end users self-serve RMM agent installs without opening a ticket.

**Architecture:** Per-client `portal_install_token` on the clients table maps to a branded public landing page at `/setup/{token}`. The page queries the mapped primary RMM's API for installer info (URL, optional registration key, optional install script) and renders download buttons for each supported platform. Admin UI lets MSPs generate and rotate tokens per client. Portal dashboard shows a "Set up a new computer" card for authenticated users.

**Tech Stack:** Laravel 12 / Blade / MariaDB. No build step. Manual verification (no existing test framework). Spec: `docs/superpowers/specs/2026-04-18-rmm-portal-installer-downloads-design.md`.

---

## File Structure

### New files

| File | Responsibility |
|------|---------------|
| `database/migrations/2026_04_18_000001_add_portal_install_to_clients.php` | Adds `portal_install_token` + `portal_primary_rmm` columns |
| `app/Services/Portal/InstallerInfo.php` | Value object for a single platform's installer (URL, key, script, instructions) |
| `app/Services/Portal/InstallerPackage.php` | Value object holding all platforms for a client + MSP branding |
| `app/Services/Portal/PortalInstallService.php` | Resolves token → client → primary RMM → `InstallerPackage` |
| `app/Http/Controllers/Portal/PortalInstallController.php` | Public landing page (`show`) + download redirect (`download`) |
| `resources/views/portal/install/show.blade.php` | Standalone branded landing page |
| `resources/views/portal/install/invalid.blade.php` | Friendly error page (invalid token, no RMM, API failure) |

### Modified files

| File | Change |
|------|--------|
| `app/Models/Client.php` | Add fields to `$fillable`; add `availableRmms()` helper |
| `app/Support/PortalConfig.php` | Add `supportPhone()` helper |
| `app/Services/Level/LevelClient.php` | Add `getInstallerInfo(string $groupId, string $platform): ?InstallerInfo` |
| `app/Services/Ninja/NinjaClient.php` | Add `getInstallerInfo(int $orgId, string $platform): ?InstallerInfo` |
| `app/Services/Tactical/TacticalClient.php` | Add `getInstallerInfo(string $siteId, string $platform): ?InstallerInfo` |
| `app/Http/Controllers/Web/ClientController.php` | Add `generateInstallLink`, `rotateInstallLink`, `disableInstallLink`, `updatePortalPrimaryRmm` actions |
| `routes/web.php` | Add public `/setup/*` routes and admin install-link actions |
| `resources/views/clients/show.blade.php` | Add "Self-Service Install Link" card in the Overview tab |
| `app/Http/Controllers/Portal/PortalDashboardController.php` | Pass `installToken` to the dashboard view |
| `resources/views/portal/dashboard.blade.php` | Add "Set up a new computer" card when token exists |

---

### Task 1: Migration + Client model changes

**Files:**
- Create: `database/migrations/2026_04_18_000001_add_portal_install_to_clients.php`
- Modify: `app/Models/Client.php`

- [ ] **Step 1: Create migration**

Create `database/migrations/2026_04_18_000001_add_portal_install_to_clients.php`:

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
            $table->string('portal_install_token')->nullable()->unique()->after('tactical_site_id');
            $table->string('portal_primary_rmm')->nullable()->after('portal_install_token');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropUnique(['portal_install_token']);
            $table->dropColumn(['portal_install_token', 'portal_primary_rmm']);
        });
    }
};
```

- [ ] **Step 2: Add fields to Client $fillable**

In `app/Models/Client.php`, add to the `$fillable` array (after `'tactical_site_id'`, around line 35):

```php
        'portal_install_token',
        'portal_primary_rmm',
```

- [ ] **Step 3: Add `availableRmms()` helper on Client model**

At the end of the Client model (before the closing `}`), add:

```php
    /**
     * Return a list of RMM slugs this client has mapped.
     * Used to populate the primary-RMM dropdown and to pick a default.
     *
     * @return array<int, string>  e.g. ['ninja', 'level'] or ['tactical']
     */
    public function availableRmms(): array
    {
        $rmms = [];
        if (! empty($this->ninja_org_id)) {
            $rmms[] = 'ninja';
        }
        if (! empty($this->level_group_id)) {
            $rmms[] = 'level';
        }
        if (! empty($this->tactical_site_id)) {
            $rmms[] = 'tactical';
        }

        return $rmms;
    }

    /**
     * Returns the RMM slug to use for portal self-service install.
     * Uses `portal_primary_rmm` if set, otherwise the only mapped RMM
     * (returns null if multiple are mapped and none is chosen).
     */
    public function effectiveInstallRmm(): ?string
    {
        $available = $this->availableRmms();

        if (empty($available)) {
            return null;
        }

        if ($this->portal_primary_rmm && in_array($this->portal_primary_rmm, $available, true)) {
            return $this->portal_primary_rmm;
        }

        return count($available) === 1 ? $available[0] : null;
    }
```

- [ ] **Step 4: Run migration**

Run: `php artisan migrate`

Expected output:
```
INFO  Running migrations.
2026_04_18_000001_add_portal_install_to_clients .................... DONE
```

- [ ] **Step 5: Verify syntax**

Run: `php -l app/Models/Client.php && php -l database/migrations/2026_04_18_000001_add_portal_install_to_clients.php`

Expected: `No syntax errors detected` for both files.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_04_18_000001_add_portal_install_to_clients.php app/Models/Client.php
git commit -m "Add portal_install_token and portal_primary_rmm to clients"
```

---

### Task 2: Value objects (InstallerInfo + InstallerPackage)

**Files:**
- Create: `app/Services/Portal/InstallerInfo.php`
- Create: `app/Services/Portal/InstallerPackage.php`

- [ ] **Step 1: Create InstallerInfo**

Create `app/Services/Portal/InstallerInfo.php`:

```php
<?php

namespace App\Services\Portal;

/**
 * Describes how to install a specific RMM agent on one platform.
 *
 * Three shapes are supported:
 *   1. Direct download  — set $downloadUrl only. Button triggers a redirect to the URL.
 *   2. Download + key   — set $downloadUrl and $registrationKey. Landing page shows
 *                         both, user copies the key and pastes it during install.
 *   3. One-liner script — set $installScript. Landing page shows a copy-to-clipboard
 *                         block; the user runs the script in PowerShell/bash.
 *
 * $instructions is optional free-form text shown below the install controls.
 */
final class InstallerInfo
{
    public function __construct(
        public readonly ?string $downloadUrl = null,
        public readonly ?string $registrationKey = null,
        public readonly ?string $installScript = null,
        public readonly ?string $instructions = null,
    ) {}

    public function hasScript(): bool
    {
        return ! empty($this->installScript);
    }

    public function hasKey(): bool
    {
        return ! empty($this->registrationKey);
    }

    public function hasDownload(): bool
    {
        return ! empty($this->downloadUrl);
    }
}
```

- [ ] **Step 2: Create InstallerPackage**

Create `app/Services/Portal/InstallerPackage.php`:

```php
<?php

namespace App\Services\Portal;

/**
 * Everything the public landing page needs to render for a single client:
 * the set of per-platform installers and the MSP branding/contact info.
 */
final class InstallerPackage
{
    /**
     * @param  array<string, InstallerInfo>  $platforms  Keyed by platform slug
     *        ('windows', 'mac', 'linux'). Missing keys mean the RMM doesn't
     *        support that platform.
     */
    public function __construct(
        public readonly string $clientName,
        public readonly string $rmmLabel,
        public readonly array $platforms,
        public readonly string $mspName,
        public readonly ?string $mspLogoUrl,
        public readonly ?string $supportEmail,
        public readonly ?string $supportPhone,
    ) {}

    /** @return array<int, string> */
    public function availablePlatforms(): array
    {
        return array_keys($this->platforms);
    }

    public function for(string $platform): ?InstallerInfo
    {
        return $this->platforms[$platform] ?? null;
    }
}
```

- [ ] **Step 3: Verify syntax**

Run: `php -l app/Services/Portal/InstallerInfo.php && php -l app/Services/Portal/InstallerPackage.php`

Expected: `No syntax errors detected` for both files.

- [ ] **Step 4: Commit**

```bash
git add app/Services/Portal/InstallerInfo.php app/Services/Portal/InstallerPackage.php
git commit -m "Add InstallerInfo and InstallerPackage value objects"
```

---

### Task 3: PortalConfig support phone helper

**Files:**
- Modify: `app/Support/PortalConfig.php`

- [ ] **Step 1: Add supportPhone() helper**

In `app/Support/PortalConfig.php`, add after the existing `supportEmail()` method:

```php
    public static function supportPhone(): ?string
    {
        $phone = Setting::getValue('portal_support_phone');

        return $phone ?: null;
    }
```

- [ ] **Step 2: Verify syntax**

Run: `php -l app/Support/PortalConfig.php`

Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add app/Support/PortalConfig.php
git commit -m "Add supportPhone helper to PortalConfig"
```

---

### Task 4: PortalInstallService

**Files:**
- Create: `app/Services/Portal/PortalInstallService.php`

Resolves a token to a branded `InstallerPackage`. Per-RMM installer methods don't exist yet — this service calls them via `match` and collects whatever non-null results come back.

- [ ] **Step 1: Create the service**

Create `app/Services/Portal/PortalInstallService.php`:

```php
<?php

namespace App\Services\Portal;

use App\Models\Client;
use App\Services\Level\LevelClient;
use App\Services\Ninja\NinjaClient;
use App\Services\Tactical\TacticalClient;
use App\Support\PortalConfig;
use Illuminate\Support\Facades\Log;

class PortalInstallService
{
    /**
     * Platforms we check against each RMM. Each RMM returns ?InstallerInfo
     * per platform; nulls are dropped from the final package.
     */
    private const PLATFORMS = ['windows', 'mac', 'linux'];

    /**
     * Look up a client by install token, then build its InstallerPackage.
     * Returns null if the token doesn't resolve.
     */
    public function findByToken(string $token): ?Client
    {
        if (empty($token)) {
            return null;
        }

        return Client::where('portal_install_token', $token)->first();
    }

    /**
     * Build the package for a client. Returns null if the client has no
     * usable RMM mapping or the chosen RMM returned no installers.
     */
    public function buildPackage(Client $client): ?InstallerPackage
    {
        $rmm = $client->effectiveInstallRmm();
        if (! $rmm) {
            return null;
        }

        $platforms = [];
        foreach (self::PLATFORMS as $platform) {
            $info = $this->resolveInstaller($client, $rmm, $platform);
            if ($info !== null) {
                $platforms[$platform] = $info;
            }
        }

        if (empty($platforms)) {
            return null;
        }

        return new InstallerPackage(
            clientName: $client->name,
            rmmLabel: $this->rmmLabel($rmm),
            platforms: $platforms,
            mspName: PortalConfig::companyName(),
            mspLogoUrl: PortalConfig::logoUrl(),
            supportEmail: PortalConfig::supportEmail(),
            supportPhone: PortalConfig::supportPhone(),
        );
    }

    private function resolveInstaller(Client $client, string $rmm, string $platform): ?InstallerInfo
    {
        try {
            return match ($rmm) {
                'level' => app(LevelClient::class)->getInstallerInfo((string) $client->level_group_id, $platform),
                'ninja' => app(NinjaClient::class)->getInstallerInfo((int) $client->ninja_org_id, $platform),
                'tactical' => app(TacticalClient::class)->getInstallerInfo((string) $client->tactical_site_id, $platform),
                default => null,
            };
        } catch (\Throwable $e) {
            Log::warning('[PortalInstall] RMM installer lookup failed', [
                'client_id' => $client->id,
                'rmm' => $rmm,
                'platform' => $platform,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function rmmLabel(string $rmm): string
    {
        return match ($rmm) {
            'ninja' => 'NinjaRMM Agent',
            'level' => 'Level Agent',
            'tactical' => 'Tactical RMM Agent',
            default => 'Management Agent',
        };
    }
}
```

- [ ] **Step 2: Verify syntax**

Run: `php -l app/Services/Portal/PortalInstallService.php`

Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add app/Services/Portal/PortalInstallService.php
git commit -m "Add PortalInstallService for building InstallerPackage from token"
```

---

### Task 5: LevelClient::getInstallerInfo

**Files:**
- Modify: `app/Services/Level/LevelClient.php`

Level's model (confirmed 2026-04-18): generic installer binary + per-group registration key pullable via API. The exact API endpoint isn't documented in this repo — you must research it before implementing.

- [ ] **Step 1: Research Level's install-key API**

Check the following in order until you find how Level exposes the per-group registration key via API:

1. Try `php artisan tinker` against the existing `LevelClient::get()`:
   ```php
   $client = app(\App\Services\Level\LevelClient::class);
   // Try candidate endpoints — these are educated guesses:
   $client->get('/v2/groups/{GROUP_ID}/install-key');
   $client->get('/v2/groups/{GROUP_ID}/installers');
   $client->get('/v2/groups/{GROUP_ID}/deployment');
   // Replace {GROUP_ID} with a real level_group_id from the clients table
   ```
2. If those 404, ask the user to provide the specific Level API path for install keys (they emailed Level support about this topic — they may already know or can ask Level for it).
3. Record the working endpoint and the response shape for the next step.

**Outcome of research:** you should know (a) the API endpoint to call, (b) the JSON path to the key, and (c) the URL to the generic agent installer for each platform (Windows/Mac/Linux).

- [ ] **Step 2: Add getInstallerInfo to LevelClient**

Open `app/Services/Level/LevelClient.php` and add at the end of the class, before the closing `}`:

```php
    /**
     * Get installer info for a specific Level group (client).
     *
     * Level uses a generic installer per platform + a per-group registration
     * key. Install flow: user runs the installer, enters the key at the prompt.
     * If the Level dashboard offers a one-liner script, we return that as
     * installScript so the user doesn't have to type the key manually.
     *
     * @param  string  $groupId  The Level group ID from clients.level_group_id
     * @param  string  $platform  One of: 'windows', 'mac', 'linux'
     */
    public function getInstallerInfo(string $groupId, string $platform): ?\App\Services\Portal\InstallerInfo
    {
        if (empty($groupId)) {
            return null;
        }

        // Fetch the group's install key via Level's API.
        // REPLACE THE ENDPOINT BELOW based on your research in Task 5 Step 1.
        try {
            $response = $this->get("/v2/groups/{$groupId}/install-key");
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[LevelClient] install-key fetch failed', [
                'group_id' => $groupId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        // REPLACE THE JSON PATH BELOW based on Level's actual response shape.
        $key = $response['install_key'] ?? $response['key'] ?? null;
        if (! $key) {
            return null;
        }

        // The generic Level installer URLs per platform. Level's installer is
        // "agnostic" — same binary for all customers. If Level's API returns
        // a download URL, prefer that. Otherwise hardcode the known URLs.
        $downloadUrl = match ($platform) {
            'windows' => 'https://downloads.level.io/installers/windows/latest/LevelInstaller.msi',
            'mac' => 'https://downloads.level.io/installers/mac/latest/LevelInstaller.pkg',
            'linux' => 'https://downloads.level.io/installers/linux/latest/level-installer.sh',
            default => null,
        };

        if (! $downloadUrl) {
            return null;
        }

        // If Level supports a one-liner install script (e.g., PowerShell on
        // Windows), build it here so the user doesn't need to enter the key.
        $script = match ($platform) {
            'windows' => "(New-Object Net.WebClient).DownloadFile('{$downloadUrl}', \"\$env:TEMP\\LevelInstaller.msi\"); Start-Process msiexec.exe -ArgumentList '/i', \"\$env:TEMP\\LevelInstaller.msi\", '/qn', 'INSTALLKEY={$key}' -Wait",
            default => null,
        };

        return new \App\Services\Portal\InstallerInfo(
            downloadUrl: $downloadUrl,
            registrationKey: $key,
            installScript: $script,
            instructions: $script
                ? 'Right-click Start, choose "Windows PowerShell (Admin)", paste the script above and press Enter.'
                : 'Download the installer, then when prompted enter the registration key shown above.',
        );
    }
```

**IMPORTANT:** The endpoint path (`/v2/groups/{$groupId}/install-key`), the JSON path for the key, and the generic installer URLs are all **best-guess placeholders**. Replace each with the real values from Step 1 research before considering this task complete. If Level doesn't provide an MSI silent-install path that accepts a key via command line, remove the Windows `$script` block and fall back to the key+download flow.

- [ ] **Step 3: Verify syntax**

Run: `php -l app/Services/Level/LevelClient.php`

Expected: `No syntax errors detected`.

- [ ] **Step 4: Test the method in tinker against a real Level client**

Run:
```bash
php artisan tinker --execute='
$c = App\Models\Client::whereNotNull("level_group_id")->first();
echo "Client: " . $c->name . " group=" . $c->level_group_id . PHP_EOL;
$info = app(App\Services\Level\LevelClient::class)->getInstallerInfo((string) $c->level_group_id, "windows");
if ($info) {
    echo "download_url: " . ($info->downloadUrl ?? "-") . PHP_EOL;
    echo "registration_key: " . ($info->registrationKey ?? "-") . PHP_EOL;
    echo "install_script: " . ($info->installScript ? "(set, " . strlen($info->installScript) . " chars)" : "-") . PHP_EOL;
} else {
    echo "InstallerInfo returned null — check endpoint path or response shape" . PHP_EOL;
}
'
```

Expected: a non-null `InstallerInfo` with a real key that matches what Level's dashboard shows for that group.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Level/LevelClient.php
git commit -m "Add getInstallerInfo to LevelClient"
```

---

### Task 6: NinjaClient::getInstallerInfo (research-gated)

**Files:**
- Modify: `app/Services/Ninja/NinjaClient.php`

NinjaRMM typically exposes per-organization installer URLs. The endpoint isn't documented in this repo — research first.

- [ ] **Step 1: Research Ninja's installer endpoint**

Try the following in tinker:

```php
$c = app(App\Services\Ninja\NinjaClient::class);
// Candidate endpoints — adjust based on Ninja's REST API reference at
// https://app.ninjarmm.com/apidocs-beta/
$c->get('/v2/organization/{ORG_ID}/installers');
$c->get('/v2/organizations/{ORG_ID}/installer');
$c->get('/v2/installer-download-link', ['orgId' => ORG_ID, 'nodeClass' => 'WINDOWS_WORKSTATION']);
```

If none of these work, search Ninja's public API reference for keywords "installer", "download", "generator" and find the right endpoint and params.

If after a reasonable effort you can't find a working endpoint, skip this task — return `null` from `getInstallerInfo` and mark NinjaRMM as unsupported for this feature. The plan continues with Level-only.

- [ ] **Step 2: Add getInstallerInfo to NinjaClient**

If research succeeds, add at the end of the `NinjaClient` class:

```php
    /**
     * Get installer info for a specific Ninja organization.
     *
     * @param  int  $orgId  The Ninja org ID from clients.ninja_org_id
     * @param  string  $platform  One of: 'windows', 'mac', 'linux'
     */
    public function getInstallerInfo(int $orgId, string $platform): ?\App\Services\Portal\InstallerInfo
    {
        if (! $orgId) {
            return null;
        }

        // REPLACE THIS BLOCK based on your Task 6 Step 1 research.
        // Typical Ninja shape: call an installer endpoint, get back { url: "..." }
        $ninjaPlatform = match ($platform) {
            'windows' => 'WINDOWS',
            'mac' => 'MAC',
            'linux' => 'LINUX',
            default => null,
        };

        if (! $ninjaPlatform) {
            return null;
        }

        try {
            $response = $this->get('/v2/installer-download-link', [
                'orgId' => $orgId,
                'nodeClass' => $ninjaPlatform,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[NinjaClient] installer fetch failed', [
                'org_id' => $orgId,
                'platform' => $platform,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        $url = $response['url'] ?? $response['downloadUrl'] ?? null;
        if (! $url) {
            return null;
        }

        return new \App\Services\Portal\InstallerInfo(
            downloadUrl: $url,
            instructions: 'Download the installer and run it. Your device will automatically register with our management system.',
        );
    }
```

If research failed (no endpoint found), add this minimal stub instead so `PortalInstallService` receives `null` cleanly:

```php
    public function getInstallerInfo(int $orgId, string $platform): ?\App\Services\Portal\InstallerInfo
    {
        // NinjaRMM API does not currently expose installer URLs via API.
        // Feature unavailable for Ninja-mapped clients until Ninja adds this.
        return null;
    }
```

- [ ] **Step 3: Verify syntax**

Run: `php -l app/Services/Ninja/NinjaClient.php`

Expected: `No syntax errors detected`.

- [ ] **Step 4: Test (only if research succeeded)**

Run:
```bash
php artisan tinker --execute='
$c = App\Models\Client::whereNotNull("ninja_org_id")->first();
$info = app(App\Services\Ninja\NinjaClient::class)->getInstallerInfo((int) $c->ninja_org_id, "windows");
echo $info ? "url: " . $info->downloadUrl : "null" . PHP_EOL;
'
```

- [ ] **Step 5: Commit**

```bash
git add app/Services/Ninja/NinjaClient.php
git commit -m "Add getInstallerInfo to NinjaClient"
```

---

### Task 7: TacticalClient::getInstallerInfo (research-gated)

**Files:**
- Modify: `app/Services/Tactical/TacticalClient.php`

TRMM's agent installer comes from deployment tokens generated per client/site. The endpoint isn't documented in this repo — research first.

- [ ] **Step 1: Research Tactical's installer/deployment endpoint**

Try:
```php
$c = app(App\Services\Tactical\TacticalClient::class);
$c->get('agents/installer/');  // list deployment tokens
$c->post('agents/installer/', ['client' => CLIENT_ID, 'site' => SITE_ID, 'agent_type' => 'workstation', 'expires' => 24, 'install_flags' => []]);
```

TRMM docs: https://docs.tacticalrmm.com/ — look for "deployment" in the API or Admin UI sections.

If the endpoint creates a deployment token that returns a download URL, use that. If no such endpoint exists, stub out `getInstallerInfo` to return `null`.

- [ ] **Step 2: Add getInstallerInfo to TacticalClient**

If research succeeds, add at the end of the `TacticalClient` class (TRMM clients.tactical_site_id format is `"ClientName|SiteName"` — parse it):

```php
    /**
     * Get installer info for a Tactical site. TRMM deployment tokens have an
     * expiry; we request 7 days so the URL stays valid for a reasonable window.
     *
     * @param  string  $siteId  "ClientName|SiteName" from clients.tactical_site_id
     * @param  string  $platform  One of: 'windows', 'mac', 'linux'
     */
    public function getInstallerInfo(string $siteId, string $platform): ?\App\Services\Portal\InstallerInfo
    {
        if (empty($siteId) || ! str_contains($siteId, '|')) {
            return null;
        }

        [$clientName, $siteName] = explode('|', $siteId, 2);

        // REPLACE based on Task 7 Step 1 research.
        // TRMM requires numeric client/site IDs — look them up first.
        try {
            $clients = $this->getClients();
            $tacticalClient = collect($clients)->firstWhere('name', $clientName);
            if (! $tacticalClient) {
                return null;
            }
            $site = collect($tacticalClient['sites'] ?? [])->firstWhere('name', $siteName);
            if (! $site) {
                return null;
            }

            $tacticalPlatform = match ($platform) {
                'windows' => 'windows',
                'mac' => 'darwin',
                'linux' => 'linux',
                default => null,
            };

            if (! $tacticalPlatform) {
                return null;
            }

            // Create a short-lived deployment token
            $deployment = $this->post('agents/installer/', [
                'client' => $tacticalClient['id'],
                'site' => $site['id'],
                'expires' => 168,                // hours (7 days)
                'agenttype' => 'workstation',
                'power' => 0,
                'ping' => 0,
                'rdp' => 0,
                'install_flags' => [],
                'arch' => $platform === 'windows' ? '64' : null,
                'api' => config('services.tactical.api_url'),
                'plat' => $tacticalPlatform,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[TacticalClient] installer fetch failed', [
                'site_id' => $siteId,
                'platform' => $platform,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        $url = $deployment['url'] ?? $deployment['download_url'] ?? null;
        if (! $url) {
            return null;
        }

        return new \App\Services\Portal\InstallerInfo(
            downloadUrl: $url,
            instructions: 'Download the installer and run it. Your device will automatically register with our management system.',
        );
    }
```

If research failed, use this stub:

```php
    public function getInstallerInfo(string $siteId, string $platform): ?\App\Services\Portal\InstallerInfo
    {
        // Not yet implemented — see docs/superpowers/plans/
        return null;
    }
```

- [ ] **Step 3: Verify syntax**

Run: `php -l app/Services/Tactical/TacticalClient.php`

Expected: `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
git add app/Services/Tactical/TacticalClient.php
git commit -m "Add getInstallerInfo to TacticalClient"
```

---

### Task 8: PortalInstallController + public routes

**Files:**
- Create: `app/Http/Controllers/Portal/PortalInstallController.php`
- Modify: `routes/web.php`

- [ ] **Step 1: Create the controller**

Create `app/Http/Controllers/Portal/PortalInstallController.php`:

```php
<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Services\Portal\PortalInstallService;
use App\Support\PortalConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class PortalInstallController extends Controller
{
    public function __construct(private readonly PortalInstallService $service) {}

    /**
     * Public landing page for client self-service RMM installs.
     * Invalid tokens, missing RMM, or API failures all render the invalid page.
     */
    public function show(Request $request, string $token): View|RedirectResponse
    {
        $client = $this->service->findByToken($token);
        if (! $client) {
            return $this->invalidPage('This setup link is not valid. Contact your IT support team.');
        }

        $package = $this->service->buildPackage($client);
        if (! $package) {
            return $this->invalidPage(sprintf(
                'Device enrollment is not configured for your organization. Contact %s for assistance.',
                PortalConfig::companyName(),
            ));
        }

        // ?download=1 — auto-detect platform from UA and redirect to installer
        if ($request->boolean('download')) {
            $platform = $this->detectPlatform($request->userAgent() ?? '');
            $info = $platform ? $package->for($platform) : null;
            if ($info && $info->hasDownload()) {
                return redirect()->away($info->downloadUrl);
            }
            // fall through to the landing page
        }

        Log::info('[PortalInstall] Landing page viewed', [
            'client_id' => $client->id,
            'token_prefix' => substr($token, 0, 8),
            'ip' => $request->ip(),
        ]);

        return view('portal.install.show', compact('package'));
    }

    /**
     * Direct download redirect for the given platform.
     * Only used when InstallerInfo has a download_url and no script.
     * (Script and key-based installers are handled inline on the landing page.)
     */
    public function download(Request $request, string $token): RedirectResponse
    {
        $client = $this->service->findByToken($token);
        if (! $client) {
            return redirect()->route('portal.install.show', ['token' => $token]);
        }

        $package = $this->service->buildPackage($client);
        if (! $package) {
            return redirect()->route('portal.install.show', ['token' => $token]);
        }

        $platform = $request->query('platform');
        if (! is_string($platform)) {
            return redirect()->route('portal.install.show', ['token' => $token]);
        }

        $info = $package->for($platform);
        if (! $info || ! $info->hasDownload()) {
            return redirect()->route('portal.install.show', ['token' => $token]);
        }

        Log::info('[PortalInstall] Download redirect', [
            'client_id' => $client->id,
            'platform' => $platform,
        ]);

        return redirect()->away($info->downloadUrl);
    }

    private function detectPlatform(string $userAgent): ?string
    {
        if (stripos($userAgent, 'Windows') !== false) {
            return 'windows';
        }
        if (stripos($userAgent, 'Mac OS') !== false || stripos($userAgent, 'Macintosh') !== false) {
            return 'mac';
        }
        if (stripos($userAgent, 'Linux') !== false) {
            return 'linux';
        }

        return null;
    }

    private function invalidPage(string $message): View
    {
        return view('portal.install.invalid', [
            'message' => $message,
            'mspName' => PortalConfig::companyName(),
            'mspLogoUrl' => PortalConfig::logoUrl(),
            'supportEmail' => PortalConfig::supportEmail(),
            'supportPhone' => PortalConfig::supportPhone(),
        ]);
    }
}
```

- [ ] **Step 2: Register public routes in routes/web.php**

Open `routes/web.php` and find the other public routes (around lines 45-76 based on existing `legal.eula` and similar). Add after them:

```php
// Public self-service RMM installer landing — no auth, no session required.
Route::get('/setup/{token}', [\App\Http\Controllers\Portal\PortalInstallController::class, 'show'])
    ->middleware('throttle:60,1')
    ->name('portal.install.show')
    ->where('token', '[A-Za-z0-9]{16,64}');

Route::get('/setup/{token}/download', [\App\Http\Controllers\Portal\PortalInstallController::class, 'download'])
    ->middleware('throttle:60,1')
    ->name('portal.install.download')
    ->where('token', '[A-Za-z0-9]{16,64}');
```

- [ ] **Step 3: Verify syntax**

Run: `php -l app/Http/Controllers/Portal/PortalInstallController.php && php -l routes/web.php`

Expected: `No syntax errors detected` for both.

- [ ] **Step 4: Clear route cache and list**

Run:
```bash
php artisan route:clear
php artisan route:list | grep "setup"
```

Expected output includes:
```
GET|HEAD  setup/{token} ........................................ portal.install.show
GET|HEAD  setup/{token}/download .............................. portal.install.download
```

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Portal/PortalInstallController.php routes/web.php
git commit -m "Add PortalInstallController and public setup routes"
```

---

### Task 9: Landing page + invalid error page

**Files:**
- Create: `resources/views/portal/install/show.blade.php`
- Create: `resources/views/portal/install/invalid.blade.php`

Both pages are fully standalone (no layout inheritance) — they render outside the portal auth context.

- [ ] **Step 1: Create the landing page view**

Create `resources/views/portal/install/show.blade.php`:

```blade
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title>Set up your computer — {{ $package->clientName }}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Montserrat:wght@600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #1a365d;
            --primary-light: #234179;
            --accent: #fed136;
            --accent-hover: #fdc50c;
        }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #f8f9fa;
            color: #374151;
        }
        .install-header {
            background: var(--primary);
            color: #fff;
            padding: 2rem 0;
        }
        .install-header img { max-height: 48px; }
        .install-header h1 {
            font-family: 'Montserrat', sans-serif;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin: 0;
            font-size: 1.5rem;
        }
        .install-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 2rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.04);
        }
        .btn-accent {
            background: var(--accent);
            color: var(--primary);
            border: 0;
            font-weight: 600;
            padding: 0.75rem 1.5rem;
        }
        .btn-accent:hover { background: var(--accent-hover); }
        .download-btn {
            min-width: 220px;
        }
        .key-block {
            background: #f3f4f6;
            border-radius: 8px;
            padding: 1rem;
            font-family: ui-monospace, 'SF Mono', Consolas, monospace;
            font-size: 0.95rem;
            word-break: break-all;
        }
        .script-block {
            background: #1f2937;
            color: #f3f4f6;
            border-radius: 8px;
            padding: 1rem;
            font-family: ui-monospace, 'SF Mono', Consolas, monospace;
            font-size: 0.85rem;
            overflow-x: auto;
            white-space: pre-wrap;
            word-break: break-all;
        }
        .platform-toggle {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        .platform-toggle .btn {
            border: 1px solid #d1d5db;
            background: #fff;
            color: #6b7280;
        }
        .platform-toggle .btn.active {
            background: var(--primary);
            border-color: var(--primary);
            color: #fff;
        }
        .footer-contact {
            color: #6b7280;
            padding: 2rem 0;
            font-size: 0.9rem;
        }
    </style>
</head>
<body>

<header class="install-header">
    <div class="container d-flex align-items-center gap-3">
        @if($package->mspLogoUrl)
            <img src="{{ $package->mspLogoUrl }}" alt="{{ $package->mspName }}">
        @endif
        <h1>{{ $package->mspName }}</h1>
    </div>
</header>

<main class="container py-5">
    <div class="install-card mx-auto" style="max-width: 720px;">
        <h2 class="h3 mb-2">Set up your new computer</h2>
        <p class="text-muted mb-4">
            Welcome, {{ $package->clientName }}. This will install the
            <strong>{{ $package->rmmLabel }}</strong> on this computer so our support
            team can help you when you need it.
        </p>

        <div class="mb-3">
            <label class="form-label fw-semibold">Choose your operating system</label>
            <div class="platform-toggle" id="platformToggle">
                @foreach($package->availablePlatforms() as $platform)
                    <button type="button"
                            class="btn"
                            data-platform="{{ $platform }}">
                        @if($platform === 'windows')<i class="bi bi-windows me-1"></i>Windows@endif
                        @if($platform === 'mac')<i class="bi bi-apple me-1"></i>macOS@endif
                        @if($platform === 'linux')<i class="bi bi-ubuntu me-1"></i>Linux@endif
                    </button>
                @endforeach
            </div>
        </div>

        @foreach($package->platforms as $platform => $info)
            <div class="platform-panel" data-platform-panel="{{ $platform }}" style="display: none;">

                @if($info->hasScript())
                    <div class="mb-3">
                        <strong>One-click install</strong>
                        <p class="text-muted small mb-2">
                            Copy the command below, then paste it into an administrator PowerShell window and press Enter.
                        </p>
                        <div class="script-block" id="script-{{ $platform }}">{{ $info->installScript }}</div>
                        <button type="button" class="btn btn-sm btn-outline-secondary mt-2" onclick="copyScript('{{ $platform }}')">
                            <i class="bi bi-clipboard me-1"></i>Copy to clipboard
                        </button>
                    </div>
                @endif

                @if($info->hasDownload())
                    <div class="mb-3">
                        @if($info->hasScript())
                            <hr class="my-4">
                            <p class="text-muted small mb-2">Or download and run the installer manually:</p>
                        @endif
                        <a href="{{ route('portal.install.download', ['token' => request()->route('token'), 'platform' => $platform]) }}"
                           class="btn btn-accent download-btn">
                            <i class="bi bi-download me-1"></i>Download installer
                        </a>
                    </div>
                @endif

                @if($info->hasKey() && ! $info->hasScript())
                    <div class="mb-3">
                        <strong>Registration key</strong>
                        <p class="text-muted small mb-2">
                            When the installer asks for a key, copy and paste this:
                        </p>
                        <div class="key-block" id="key-{{ $platform }}">{{ $info->registrationKey }}</div>
                        <button type="button" class="btn btn-sm btn-outline-secondary mt-2" onclick="copyKey('{{ $platform }}')">
                            <i class="bi bi-clipboard me-1"></i>Copy key
                        </button>
                    </div>
                @endif

                @if($info->instructions)
                    <div class="small text-muted mt-3">
                        <i class="bi bi-info-circle me-1"></i>{{ $info->instructions }}
                    </div>
                @endif
            </div>
        @endforeach
    </div>
</main>

<footer class="footer-contact">
    <div class="container text-center">
        <p class="mb-1">Need help? Contact {{ $package->mspName }}</p>
        @if($package->supportPhone)
            <p class="mb-1"><i class="bi bi-telephone me-1"></i>{{ $package->supportPhone }}</p>
        @endif
        @if($package->supportEmail)
            <p class="mb-0"><i class="bi bi-envelope me-1"></i>
                <a href="mailto:{{ $package->supportEmail }}">{{ $package->supportEmail }}</a>
            </p>
        @endif
    </div>
</footer>

<script>
    // Detect OS and pre-select the matching platform panel
    (function () {
        var available = @json($package->availablePlatforms());
        var detected = null;
        var platformString = (navigator.userAgentData?.platform || navigator.platform || '').toLowerCase();
        if (platformString.includes('win')) detected = 'windows';
        else if (platformString.includes('mac')) detected = 'mac';
        else if (platformString.includes('linux')) detected = 'linux';

        var initial = available.includes(detected) ? detected : available[0];
        if (initial) showPlatform(initial);

        document.querySelectorAll('[data-platform]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                showPlatform(btn.dataset.platform);
            });
        });
    })();

    function showPlatform(platform) {
        document.querySelectorAll('[data-platform]').forEach(function (btn) {
            btn.classList.toggle('active', btn.dataset.platform === platform);
        });
        document.querySelectorAll('[data-platform-panel]').forEach(function (panel) {
            panel.style.display = panel.dataset.platformPanel === platform ? 'block' : 'none';
        });
    }

    function copyScript(platform) {
        var el = document.getElementById('script-' + platform);
        if (el) navigator.clipboard.writeText(el.textContent.trim());
    }

    function copyKey(platform) {
        var el = document.getElementById('key-' + platform);
        if (el) navigator.clipboard.writeText(el.textContent.trim());
    }
</script>

</body>
</html>
```

- [ ] **Step 2: Create the invalid page view**

Create `resources/views/portal/install/invalid.blade.php`:

```blade
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title>Setup — {{ $mspName }}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Montserrat:wght@600;700;800&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #f8f9fa;
            color: #374151;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        .install-header {
            background: #1a365d;
            color: #fff;
            padding: 2rem 0;
        }
        .install-header img { max-height: 48px; }
        .install-header h1 {
            font-family: 'Montserrat', sans-serif;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin: 0;
            font-size: 1.5rem;
        }
        .install-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 2rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.04);
            max-width: 560px;
        }
    </style>
</head>
<body>

<header class="install-header">
    <div class="container d-flex align-items-center gap-3">
        @if($mspLogoUrl)
            <img src="{{ $mspLogoUrl }}" alt="{{ $mspName }}">
        @endif
        <h1>{{ $mspName }}</h1>
    </div>
</header>

<main class="container py-5 flex-grow-1 d-flex align-items-center justify-content-center">
    <div class="install-card text-center">
        <div class="mb-3">
            <i class="bi bi-exclamation-triangle text-warning" style="font-size: 3rem;"></i>
        </div>
        <h2 class="h4 mb-3">Setup not available</h2>
        <p class="text-muted mb-4">{{ $message }}</p>

        @if($supportPhone || $supportEmail)
            <hr>
            <p class="mb-1 small text-muted">Need help? Contact {{ $mspName }}</p>
            @if($supportPhone)
                <p class="mb-1"><i class="bi bi-telephone me-1"></i>{{ $supportPhone }}</p>
            @endif
            @if($supportEmail)
                <p class="mb-0"><i class="bi bi-envelope me-1"></i>
                    <a href="mailto:{{ $supportEmail }}">{{ $supportEmail }}</a>
                </p>
            @endif
        @endif
    </div>
</main>

</body>
</html>
```

- [ ] **Step 3: Verify syntax**

Run: `php -l resources/views/portal/install/show.blade.php && php -l resources/views/portal/install/invalid.blade.php`

Expected: `No syntax errors detected` for both.

- [ ] **Step 4: Commit**

```bash
git add resources/views/portal/install/
git commit -m "Add portal install landing page and error page"
```

---

### Task 10: Client admin UI — generate/rotate/disable install link

**Files:**
- Modify: `app/Http/Controllers/Web/ClientController.php`
- Modify: `routes/web.php`

- [ ] **Step 1: Add actions to ClientController**

Add the following imports at the top of `ClientController.php` if not already present:

```php
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
```

Add these four actions to `ClientController.php` (place them near other client-modifying actions like `update` or `siteNotesUpdate`):

```php
    public function generateInstallLink(Client $client): RedirectResponse
    {
        if ($client->portal_install_token) {
            return redirect()->route('clients.show', $client)
                ->with('error', 'This client already has an install link. Use Rotate to replace it.');
        }

        if (empty($client->availableRmms())) {
            return redirect()->route('clients.show', $client)
                ->with('error', 'Map this client to an RMM (Ninja, Level, or Tactical) before generating an install link.');
        }

        $available = $client->availableRmms();
        $client->update([
            'portal_install_token' => Str::random(32),
            'portal_primary_rmm' => count($available) === 1 ? $available[0] : $client->portal_primary_rmm,
        ]);

        return redirect()->route('clients.show', $client)
            ->with('success', 'Install link generated.');
    }

    public function rotateInstallLink(Client $client): RedirectResponse
    {
        if (! $client->portal_install_token) {
            return redirect()->route('clients.show', $client)
                ->with('error', 'No install link to rotate.');
        }

        $client->update(['portal_install_token' => Str::random(32)]);

        return redirect()->route('clients.show', $client)
            ->with('success', 'Install link rotated. The previous URL is no longer valid.');
    }

    public function disableInstallLink(Client $client): RedirectResponse
    {
        $client->update([
            'portal_install_token' => null,
            'portal_primary_rmm' => null,
        ]);

        return redirect()->route('clients.show', $client)
            ->with('success', 'Install link disabled.');
    }

    public function updatePortalPrimaryRmm(Request $request, Client $client): RedirectResponse
    {
        $validated = $request->validate([
            'portal_primary_rmm' => ['required', 'in:ninja,level,tactical'],
        ]);

        if (! in_array($validated['portal_primary_rmm'], $client->availableRmms(), true)) {
            return redirect()->route('clients.show', $client)
                ->with('error', 'That RMM is not mapped to this client.');
        }

        $client->update(['portal_primary_rmm' => $validated['portal_primary_rmm']]);

        return redirect()->route('clients.show', $client)
            ->with('success', 'Primary RMM updated.');
    }
```

- [ ] **Step 2: Register admin routes**

In `routes/web.php`, find the other client-related POST/PATCH routes (e.g., where `clients.update`, `clients.site-notes.update`, etc. are registered — likely inside the auth-required group). Add:

```php
Route::post('/clients/{client}/install-link/generate', [\App\Http\Controllers\Web\ClientController::class, 'generateInstallLink'])
    ->name('clients.install-link.generate');
Route::post('/clients/{client}/install-link/rotate', [\App\Http\Controllers\Web\ClientController::class, 'rotateInstallLink'])
    ->name('clients.install-link.rotate');
Route::post('/clients/{client}/install-link/disable', [\App\Http\Controllers\Web\ClientController::class, 'disableInstallLink'])
    ->name('clients.install-link.disable');
Route::patch('/clients/{client}/portal-primary-rmm', [\App\Http\Controllers\Web\ClientController::class, 'updatePortalPrimaryRmm'])
    ->name('clients.portal-primary-rmm.update');
```

- [ ] **Step 3: Verify syntax**

Run: `php -l app/Http/Controllers/Web/ClientController.php && php -l routes/web.php`

Expected: `No syntax errors detected` for both.

- [ ] **Step 4: Verify routes are registered**

Run:
```bash
php artisan route:clear
php artisan route:list | grep install-link
```

Expected output:
```
POST  clients/{client}/install-link/generate ................ clients.install-link.generate
POST  clients/{client}/install-link/rotate .................. clients.install-link.rotate
POST  clients/{client}/install-link/disable ................. clients.install-link.disable
```

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Web/ClientController.php routes/web.php
git commit -m "Add install-link generate/rotate/disable controller actions and routes"
```

---

### Task 11: Client detail page — "Self-Service Install Link" card

**Files:**
- Modify: `resources/views/clients/show.blade.php`

- [ ] **Step 1: Add the card**

Open `resources/views/clients/show.blade.php` and find the Overview tab's Contracts card (around line 307 per the spec exploration). Add this new card immediately after the Contracts card, before the Danger Zone section:

```blade
                    {{-- Self-Service Install Link --}}
                    @php $availableRmms = $client->availableRmms(); @endphp
                    @if(! empty($availableRmms))
                        <div class="card mb-3">
                            <div class="card-header d-flex align-items-center gap-2">
                                <i class="bi bi-download"></i>
                                <span>Self-Service Install Link</span>
                            </div>
                            <div class="card-body">
                                @if($client->portal_install_token)
                                    @php $installUrl = url('/setup/' . $client->portal_install_token); @endphp
                                    <label class="form-label small text-muted mb-1">Install URL</label>
                                    <div class="input-group input-group-sm mb-3">
                                        <input type="text" class="form-control font-monospace" readonly value="{{ $installUrl }}" id="installUrlInput">
                                        <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('installUrlInput').value)">
                                            <i class="bi bi-clipboard"></i>
                                        </button>
                                        <a href="{{ $installUrl }}" target="_blank" class="btn btn-outline-secondary">
                                            <i class="bi bi-box-arrow-up-right"></i>
                                        </a>
                                    </div>

                                    @if(count($availableRmms) > 1)
                                        <form method="POST" action="{{ route('clients.portal-primary-rmm.update', $client) }}" class="mb-3">
                                            @csrf @method('PATCH')
                                            <label class="form-label small text-muted mb-1">Primary RMM</label>
                                            <div class="input-group input-group-sm">
                                                <select name="portal_primary_rmm" class="form-select">
                                                    @foreach($availableRmms as $rmm)
                                                        <option value="{{ $rmm }}" {{ $client->portal_primary_rmm === $rmm ? 'selected' : '' }}>
                                                            {{ ucfirst($rmm) }}
                                                        </option>
                                                    @endforeach
                                                </select>
                                                <button type="submit" class="btn btn-outline-primary">Update</button>
                                            </div>
                                        </form>
                                    @endif

                                    <div class="d-flex gap-2">
                                        <form method="POST" action="{{ route('clients.install-link.rotate', $client) }}" onsubmit="return confirm('Rotate this link? The old URL will stop working immediately.')">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-outline-warning">
                                                <i class="bi bi-arrow-repeat me-1"></i>Rotate link
                                            </button>
                                        </form>
                                        <form method="POST" action="{{ route('clients.install-link.disable', $client) }}" onsubmit="return confirm('Disable this link entirely?')">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                                <i class="bi bi-x-lg me-1"></i>Disable
                                            </button>
                                        </form>
                                    </div>
                                @else
                                    <p class="text-muted small mb-3">
                                        Generate a shareable link that lets end users install the RMM agent on new devices without contacting support.
                                    </p>
                                    <form method="POST" action="{{ route('clients.install-link.generate', $client) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-primary">
                                            <i class="bi bi-plus-lg me-1"></i>Generate install link
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    @endif
```

- [ ] **Step 2: Verify syntax**

Run: `php -l resources/views/clients/show.blade.php`

Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add resources/views/clients/show.blade.php
git commit -m "Add Self-Service Install Link card to client detail page"
```

---

### Task 12: Portal dashboard — "Set up a new computer" card

**Files:**
- Modify: `app/Http/Controllers/Portal/PortalDashboardController.php`
- Modify: `resources/views/portal/dashboard.blade.php`

- [ ] **Step 1: Load the install token in the dashboard controller**

In `app/Http/Controllers/Portal/PortalDashboardController.php`, find the `index()` method's `compact()` call (around line 63 of the existing file per the research). Add a line before the `return view(...)` to load the token, and include it in `compact()`:

After the line `$hasPurchasableContracts = ...` and before `return view('portal.dashboard', compact(...))`, add:

```php
        $installToken = \App\Models\Client::where('id', $clientId)
            ->value('portal_install_token');
```

Then update the `compact(...)` call to include `installToken`:

```php
        return view('portal.dashboard', compact(
            'person',
            'openTickets',
            'unpaidTotal',
            'recentTickets',
            'unpaidInvoices',
            'prepayContracts',
            'totalPrepayHours',
            'hasPurchasableContracts',
            'clientId',
            'installToken',
        ));
```

- [ ] **Step 2: Add the dashboard card**

In `resources/views/portal/dashboard.blade.php`, find where the Prepaid Time Balance card ends (around line 67) and before the `row` that starts the two-column grid (around line 69). Insert:

```blade
@if(! empty($installToken))
    <div class="card mb-4">
        <div class="card-body d-flex align-items-center justify-content-between flex-wrap gap-3">
            <div>
                <h5 class="mb-1"><i class="bi bi-laptop me-2"></i>Set up a new computer</h5>
                <p class="text-muted small mb-0">Install our management agent on a new Windows, Mac, or Linux device.</p>
            </div>
            <a href="{{ url('/setup/' . $installToken) }}" target="_blank" class="btn btn-primary">
                <i class="bi bi-download me-1"></i>Get the installer
            </a>
        </div>
    </div>
@endif
```

- [ ] **Step 3: Verify syntax**

Run: `php -l app/Http/Controllers/Portal/PortalDashboardController.php && php -l resources/views/portal/dashboard.blade.php`

Expected: `No syntax errors detected` for both.

- [ ] **Step 4: Commit**

```bash
git add app/Http/Controllers/Portal/PortalDashboardController.php resources/views/portal/dashboard.blade.php
git commit -m "Add 'Set up a new computer' card to portal dashboard"
```

---

### Task 13: Deploy and end-to-end verification

**Files:** None — deployment and manual testing only.

- [ ] **Step 1: Push and deploy**

```bash
git push
ssh your-vps "cd /var/www/psa && git pull && php artisan migrate --force && php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan queue:restart || true"
ssh your-vps "echo '<?php opcache_reset(); echo \"ok\";' > /var/www/psa/public/opcache-clear.php && curl -s https://your-psa-domain/opcache-clear.php && rm /var/www/psa/public/opcache-clear.php"
```

- [ ] **Step 2: Health check**

Run: `curl -sS https://your-psa-domain/api/health`

Expected: `{"status":"ok",...}`

- [ ] **Step 3: Generate a link for a test client**

In a browser:
1. Navigate to a test client's detail page (one that has Level mapped): `https://your-psa-domain/clients/{ID}`
2. Scroll to the Overview tab → find the "Self-Service Install Link" card
3. Click "Generate install link"
4. Copy the URL that appears

- [ ] **Step 4: Visit the landing page as an anonymous user**

Open the copied URL in an Incognito/Private window.

Verify:
- Branded header shows MSP name and logo (if configured)
- "Set up your new computer" heading with client name
- Platform toggle buttons appear for each supported platform
- Windows panel shows (after auto-detection or manual click)
- If Level is the RMM: the registration key is visible AND/OR a PowerShell one-liner is shown with a Copy button
- If Ninja or Tactical is the RMM and implementation was skipped: the invalid page renders instead

- [ ] **Step 5: Test the download/script**

- Click "Download installer" — a file download begins (or the browser redirects to the RMM's installer URL)
- Click "Copy to clipboard" on the script or key — verify the clipboard content is correct

- [ ] **Step 6: Test as an authenticated portal user**

Log into the portal as a test contact for the same client. Verify the "Set up a new computer" card appears on the dashboard and links to the setup URL.

- [ ] **Step 7: Test rotate**

Back in the admin UI, click "Rotate link" on the client detail card.

- Reload the old setup URL → should show the invalid page ("This setup link is not valid")
- The new URL should work

- [ ] **Step 8: Test disable**

Click "Disable" on the card. The card should return to its "Generate install link" state. Visiting the old URL should show the invalid page.

- [ ] **Step 9: Test invalid-token handling**

Visit `https://your-psa-domain/setup/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa` — should render the invalid page gracefully (no 404).

- [ ] **Step 10: Optional — test with no RMM mapped**

Pick a client with no RMM mapped. Verify the "Self-Service Install Link" card does NOT appear on their detail page (the `@if(! empty($availableRmms))` hides it).
