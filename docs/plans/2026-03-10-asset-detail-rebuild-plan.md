# Asset Detail Page Rebuild Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Rebuild the asset detail page with a tabbed interface showing rich technical data (software, network, storage, patches, security) fetched on-demand from Ninja/Level APIs.

**Architecture:** The page header stays the same. Below it, 8 Bootstrap nav-tabs replace the current card grid. Tabs 1 (Overview), 6 (Security), 7 (Alerts & Tickets), and 8 (Backup) render from stored DB data. Tabs 2-5 (Network, Storage, Software, Patches) fetch live from a new `GET /assets/{asset}/device-data/{section}` AJAX endpoint that proxies to Ninja's per-device sub-resource API. A new `Asset::resolveLastUserPerson()` method matches the `last_user` field to a Person in the DB.

**Tech Stack:** Laravel 12, Blade, Bootstrap 5.3 nav-tabs, vanilla JS fetch, NinjaRMM API v2

**Design doc:** `docs/plans/2026-03-10-asset-detail-rebuild-design.md`

---

### Task 1: Person Matching — `Asset::resolveLastUserPerson()`

**Files:**
- Modify: `app/Models/Asset.php`

**Step 1: Add the method**

Add this method to `app/Models/Asset.php` after the existing accessors section (~line 165):

```php
/**
 * Try to match last_user to a Person record in the same client.
 *
 * Matching order:
 * 1. CIPP UPN starts with extracted username
 * 2. Email starts with extracted username
 * 3. Full name match (case-insensitive)
 */
public function resolveLastUserPerson(): ?Person
{
    if (!$this->last_user || !$this->client_id) {
        return null;
    }

    $raw = $this->last_user;

    // Extract username from DOMAIN\username or username@domain
    $username = $raw;
    if (str_contains($raw, '\\')) {
        $username = substr($raw, strrpos($raw, '\\') + 1);
    } elseif (str_contains($raw, '@')) {
        $username = substr($raw, 0, strpos($raw, '@'));
    }

    $scope = Person::where('client_id', $this->client_id);

    // Match by CIPP UPN (e.g., username@domain.com)
    $match = (clone $scope)->where('cipp_upn', 'like', $username . '@%')->first();
    if ($match) return $match;

    // Match by email
    $match = (clone $scope)->where('email', 'like', $username . '@%')->first();
    if ($match) return $match;

    // Match by full name (case-insensitive)
    $match = (clone $scope)->whereRaw(
        "LOWER(CONCAT(first_name, ' ', last_name)) = ?",
        [strtolower($raw)]
    )->first();
    if ($match) return $match;

    return null;
}
```

Add the `use` statement at the top of the file if not present:
```php
use App\Models\Person;
```

**Step 2: Verify import isn't duplicated**

Check existing imports in Asset.php — `Person` is not currently imported.

**Step 3: Commit**

```bash
git add app/Models/Asset.php
git commit -m "Add resolveLastUserPerson() to Asset model for user-to-person matching"
```

---

### Task 2: Device Data AJAX Endpoint

**Files:**
- Modify: `app/Http/Controllers/Web/AssetController.php`
- Modify: `routes/web.php`

**Step 1: Add the route**

In `routes/web.php`, after the quick-look route (line 377), add:

```php
Route::get('/assets/{asset}/device-data/{section}', [AssetController::class, 'deviceData'])->name('assets.deviceData');
```

**Step 2: Add the controller method**

Add to `app/Http/Controllers/Web/AssetController.php`:

```php
/**
 * AJAX endpoint: fetch live device sub-resource data from RMM.
 * Sections: network, storage, software, patches
 */
public function deviceData(Asset $asset, string $section)
{
    $allowedSections = ['network', 'storage', 'software', 'patches'];
    if (!in_array($section, $allowedSections)) {
        return response()->json(['error' => 'Invalid section'], 422);
    }

    // Ninja-linked assets: fetch live from API
    if ($asset->ninja_id) {
        return response()->json($this->fetchNinjaDeviceData($asset, $section));
    }

    // Level-linked assets: return stored DB data (Level doesn't expose sub-resources)
    if ($asset->level_id) {
        return response()->json($this->getLevelFallbackData($asset, $section));
    }

    return response()->json(['error' => 'Asset not linked to any RMM'], 422);
}

private function fetchNinjaDeviceData(Asset $asset, string $section): array
{
    $ninjaId = $asset->ninja_id;
    $ninja = app(NinjaClient::class);

    try {
        return match ($section) {
            'network' => ['interfaces' => $ninja->get("/v2/device/{$ninjaId}/network-interfaces")],
            'storage' => [
                'disks' => $ninja->get("/v2/device/{$ninjaId}/disks"),
                'volumes' => $ninja->get("/v2/device/{$ninjaId}/volumes"),
            ],
            'software' => ['software' => $ninja->get("/v2/device/{$ninjaId}/software")],
            'patches' => ['patches' => $ninja->get("/v2/device/{$ninjaId}/os-patches")],
        };
    } catch (\Throwable $e) {
        Log::debug("[AssetController] Device data fetch failed: {$e->getMessage()}");
        return ['error' => 'Could not fetch data from RMM. Try again in a moment.'];
    }
}

private function getLevelFallbackData(Asset $asset, string $section): array
{
    return match ($section) {
        'network' => ['level_fallback' => true, 'ip_address' => $asset->ip_address],
        'storage' => ['level_fallback' => true, 'disk_summary' => $asset->disk_summary],
        'software' => ['level_fallback' => true, 'message' => 'Software inventory not available for Level devices.'],
        'patches' => ['level_fallback' => true, 'message' => 'Patch data not available for Level devices.'],
    };
}
```

Ensure `NinjaClient` is already imported (it was added in Task 6 of the badge plan).

**Step 3: Commit**

```bash
git add app/Http/Controllers/Web/AssetController.php routes/web.php
git commit -m "Add device-data AJAX endpoint for live RMM sub-resource queries"
```

---

### Task 3: Blade Rewrite — Page Header + Tab Navigation

**Files:**
- Modify: `resources/views/assets/show.blade.php` (complete rewrite)

This is the largest task. The blade file is ~600 lines. We'll rewrite it in sections. This task covers the header and tab nav structure.

**Step 1: Rewrite the page**

Replace the entire contents of `resources/views/assets/show.blade.php` with the new tabbed layout. The full file is below. It's long but straightforward — each tab pane is a self-contained section.

**Key patterns:**
- Bootstrap 5 `nav-tabs` + `tab-content` for tab switching
- Tabs 2-5 have a `data-ajax-section` attribute and load on first click via JS
- The JS block at the bottom handles AJAX fetch, spinner, caching, and error display
- Overview tab renders server-side (no AJAX)
- Security, Alerts, Backup tabs render server-side from DB data

**Important implementation notes for the Blade file:**

1. **Header section** (lines 1-67 of current file): Keep the back link, trashed alert, hostname title, status badges, contract badges, and edit button exactly as they are now.

2. **Tab nav**: After the header, add Bootstrap nav-tabs:
```blade
<ul class="nav nav-tabs mb-0" role="tablist">
    <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tab-overview">Overview</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-network" data-ajax-section="network">Network</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-storage" data-ajax-section="storage">Storage</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-software" data-ajax-section="software">Software</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-patches" data-ajax-section="patches">Patches</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-security">Security</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-alerts">Alerts & Tickets</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-backup">Backup</a></li>
</ul>
```

3. **Tab panes**: Each `<div class="tab-pane">` inside `<div class="tab-content">`.

4. **Overview tab**: Two-column row. Left = Device Identity table (hostname, name, type, serial, OS, IP, last user with person badge, warranty info, client, contracts). Right = Status & Hardware table (online status with dot, uptime, needs reboot, CPU, RAM, disk summary, RMM source with link, last synced, refresh button).

5. **AJAX tabs (network/storage/software/patches)**: Each starts with just `<div class="d-flex justify-content-center py-5"><div class="spinner-border"></div></div>`. JS replaces this with rendered HTML after fetch.

6. **Security tab**: Move all existing Control D, Zorus, and M365/Intune card content here. Add MFA status from resolved person.

7. **Alerts & Tickets tab**: Move existing NinjaRMM alerts section and Recent Tickets section here. Increase ticket limit from 5 to 15.

8. **Backup tab**: Move existing Backup Storage and Backup Jobs/Integrity sections here.

9. **JS block at bottom**: AJAX fetch handler + per-section render functions.

Given the file size, this task should be implemented by writing the complete new file. The implementer should:
- Read the current `show.blade.php` thoroughly first
- Preserve ALL existing functionality (Control D activity log AJAX, link/unlink forms, etc.)
- Use the exact same CSS classes and patterns for consistency
- The `$asset`, `$backupJobs`, `$controldDevices`, `$zorusEndpoints` variables are still passed from the controller

**Step 2: Update the controller's `show()` method**

In `AssetController::show()`, add the person resolution:

```php
$lastUserPerson = $asset->resolveLastUserPerson();
```

And pass it to the view:

```php
return view('assets.show', [
    'asset' => $asset,
    'backupJobs' => $backupJobs,
    'controldDevices' => $controldDevices,
    'zorusEndpoints' => $zorusEndpoints,
    'lastUserPerson' => $lastUserPerson,
]);
```

**Step 3: Test manually**

Start dev server, navigate to an asset detail page. Verify:
- All 8 tabs render without errors
- Overview tab shows all device info correctly
- Click Network tab → spinner → data loads
- Click Software tab → spinner → searchable table loads
- Click Patches tab → spinner → patches load with severity badges
- Click Storage tab → spinner → disks and volumes load with progress bars
- Security tab shows all existing integrations
- Alerts tab shows active/resolved alerts and tickets
- Backup tab shows storage stats and job history
- Repeated tab clicks use cached data (no re-fetch)
- Level-linked assets show fallback messages on AJAX tabs

**Step 4: Commit**

```bash
git add resources/views/assets/show.blade.php app/Http/Controllers/Web/AssetController.php
git commit -m "Rebuild asset detail page with tabbed layout and live RMM data"
```

---

### Task 4: AJAX Tab Rendering — Network

This task defines the exact HTML that the JS `renderNetwork()` function should produce from the AJAX response.

**The JS render function for the Network tab:**

```javascript
function renderNetwork(data) {
    if (data.level_fallback) {
        return '<div class="p-4"><p class="text-muted mb-1">Limited network data for Level devices.</p>'
            + '<table class="table table-sm"><tr><th style="width:140px" class="text-muted">IP Address</th><td>'
            + esc(data.ip_address || '-') + '</td></tr></table></div>';
    }
    if (!data.interfaces || !data.interfaces.length) {
        return '<p class="text-muted p-4">No network interface data available.</p>';
    }
    var html = '<div class="table-responsive"><table class="table table-sm table-hover mb-0">'
        + '<thead><tr><th>Interface</th><th>Status</th><th>MAC Address</th><th>IPv4</th><th>Subnet</th><th>Gateway</th><th>DNS</th><th>DHCP</th><th>Speed</th></tr></thead><tbody>';
    data.interfaces.forEach(function(iface) {
        var statusBadge = iface.status === 'Up' || iface.status === 'Connected'
            ? '<span class="badge bg-success">Connected</span>'
            : '<span class="badge bg-secondary">' + esc(iface.status || 'Unknown') + '</span>';
        var ips = (iface.ipAddresses || []).filter(function(ip) { return ip && ip.indexOf(':') === -1; }).join(', ');
        var ipv4 = iface.ipv4Address || ips || '-';
        var dns = (iface.dnsServers || []).join(', ') || '-';
        var speed = iface.speed ? (iface.speed >= 1000 ? (iface.speed/1000) + ' Gbps' : iface.speed + ' Mbps') : '-';
        html += '<tr><td>' + esc(iface.name || iface.description || '-') + '</td>'
            + '<td>' + statusBadge + '</td>'
            + '<td><code>' + esc(iface.macAddress || '-') + '</code></td>'
            + '<td>' + esc(ipv4) + '</td>'
            + '<td>' + esc(iface.subnetMask || '-') + '</td>'
            + '<td>' + esc(iface.gateway || iface.defaultGateway || '-') + '</td>'
            + '<td class="small">' + esc(dns) + '</td>'
            + '<td>' + (iface.dhcpEnabled ? 'Yes' : 'No') + '</td>'
            + '<td>' + speed + '</td></tr>';
    });
    html += '</tbody></table></div>';
    return html;
}
```

---

### Task 5: AJAX Tab Rendering — Storage

**The JS render function for the Storage tab:**

```javascript
function renderStorage(data) {
    if (data.level_fallback) {
        return '<div class="p-4"><p class="text-muted mb-1">Limited storage data for Level devices.</p>'
            + '<pre class="mb-0">' + esc(data.disk_summary || 'No disk data') + '</pre></div>';
    }
    var html = '';

    // Physical Disks
    if (data.disks && data.disks.length) {
        html += '<h6 class="px-3 pt-3 mb-2"><i class="bi bi-device-hdd me-1"></i>Physical Disks</h6>';
        html += '<div class="table-responsive"><table class="table table-sm table-hover mb-0">'
            + '<thead><tr><th>Model</th><th>Capacity</th><th>Type</th><th>Interface</th><th>SMART</th><th>Temp</th></tr></thead><tbody>';
        data.disks.forEach(function(d) {
            var cap = d.size ? formatBytes(d.size) : '-';
            var smartBadge = d.smartStatus === 'OK' || d.smartStatus === 'Healthy'
                ? '<span class="badge bg-success">Healthy</span>'
                : '<span class="badge bg-danger">' + esc(d.smartStatus || 'Unknown') + '</span>';
            var temp = d.temperature ? d.temperature + '°C' : '-';
            html += '<tr><td>' + esc(d.model || '-') + '</td><td>' + cap + '</td>'
                + '<td>' + esc(d.mediaType || '-') + '</td>'
                + '<td>' + esc(d.interfaceType || '-') + '</td>'
                + '<td>' + smartBadge + '</td><td>' + temp + '</td></tr>';
        });
        html += '</tbody></table></div>';
    }

    // Logical Volumes
    if (data.volumes && data.volumes.length) {
        html += '<h6 class="px-3 pt-3 mb-2"><i class="bi bi-hdd me-1"></i>Volumes</h6>';
        html += '<div class="table-responsive"><table class="table table-sm table-hover mb-0">'
            + '<thead><tr><th>Drive</th><th>File System</th><th>Capacity</th><th>Free</th><th>Usage</th></tr></thead><tbody>';
        data.volumes.forEach(function(v) {
            var cap = v.capacity ? formatBytes(v.capacity) : '-';
            var free = v.freeSpace != null ? formatBytes(v.freeSpace) : '-';
            var pct = (v.capacity && v.freeSpace != null) ? Math.round(((v.capacity - v.freeSpace) / v.capacity) * 100) : null;
            var bar = pct !== null
                ? '<div class="progress" style="height:18px;min-width:100px"><div class="progress-bar '
                    + (pct > 90 ? 'bg-danger' : pct > 75 ? 'bg-warning' : 'bg-success')
                    + '" style="width:' + pct + '%">' + pct + '%</div></div>'
                : '-';
            html += '<tr><td><strong>' + esc(v.name || '-') + '</strong></td><td>' + esc(v.fileSystem || '-') + '</td>'
                + '<td>' + cap + '</td><td>' + free + '</td><td>' + bar + '</td></tr>';
        });
        html += '</tbody></table></div>';
    }

    return html || '<p class="text-muted p-4">No storage data available.</p>';
}

function formatBytes(bytes) {
    if (!bytes) return '0 B';
    var k = 1024, sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
    var i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
}
```

---

### Task 6: AJAX Tab Rendering — Software

**The JS render function for the Software tab:**

```javascript
function renderSoftware(data) {
    if (data.level_fallback || data.message) {
        return '<p class="text-muted p-4">' + esc(data.message || 'Software inventory not available.') + '</p>';
    }
    if (!data.software || !data.software.length) {
        return '<p class="text-muted p-4">No software inventory available.</p>';
    }

    // Sort by name
    data.software.sort(function(a, b) { return (a.name || '').localeCompare(b.name || ''); });

    var html = '<div class="p-3 pb-0">'
        + '<input type="text" class="form-control form-control-sm mb-3" id="softwareSearch" placeholder="Search software..." style="max-width:300px">'
        + '</div>';
    html += '<div class="table-responsive"><table class="table table-sm table-hover mb-0" id="softwareTable">'
        + '<thead><tr><th>Name</th><th>Version</th><th>Vendor</th><th>Installed</th></tr></thead><tbody>';
    data.software.forEach(function(s) {
        var installed = s.installDate ? new Date(s.installDate * 1000).toLocaleDateString() : '-';
        html += '<tr><td>' + esc(s.name || '-') + '</td>'
            + '<td><code>' + esc(s.version || '-') + '</code></td>'
            + '<td class="text-muted">' + esc(s.vendor || '-') + '</td>'
            + '<td class="text-muted">' + installed + '</td></tr>';
    });
    html += '</tbody></table></div>';
    html += '<div class="p-3 text-muted small">' + data.software.length + ' application(s)</div>';

    // After rendering, wire up search filter
    setTimeout(function() {
        var input = document.getElementById('softwareSearch');
        if (input) {
            input.addEventListener('input', function() {
                var filter = this.value.toLowerCase();
                var rows = document.querySelectorAll('#softwareTable tbody tr');
                rows.forEach(function(row) {
                    row.style.display = row.textContent.toLowerCase().indexOf(filter) > -1 ? '' : 'none';
                });
            });
        }
    }, 0);

    return html;
}
```

---

### Task 7: AJAX Tab Rendering — Patches

**The JS render function for the Patches tab:**

```javascript
function renderPatches(data) {
    if (data.level_fallback || data.message) {
        return '<p class="text-muted p-4">' + esc(data.message || 'Patch data not available.') + '</p>';
    }
    if (!data.patches || !data.patches.length) {
        return '<p class="text-muted p-4">No patch data available.</p>';
    }

    // Separate pending vs installed
    var pending = data.patches.filter(function(p) { return p.status !== 'INSTALLED' && p.installDate == null; });
    var installed = data.patches.filter(function(p) { return p.status === 'INSTALLED' || p.installDate != null; });

    var html = '<div class="p-3 pb-0">'
        + '<span class="badge bg-warning text-dark me-2">' + pending.length + ' pending</span>'
        + '<span class="badge bg-success">' + installed.length + ' installed</span>'
        + '<button class="btn btn-sm btn-outline-secondary ms-3" id="toggleInstalled" type="button">Show installed</button>'
        + '</div>';

    function patchTable(patches, tableId) {
        var t = '<div class="table-responsive"><table class="table table-sm table-hover mb-0" id="' + tableId + '">'
            + '<thead><tr><th>Title</th><th>KB</th><th>Severity</th><th>Security</th><th>Status</th><th>Released</th></tr></thead><tbody>';
        patches.forEach(function(p) {
            var sevClass = {'CRITICAL':'bg-danger','IMPORTANT':'bg-warning text-dark','MODERATE':'bg-info text-dark','LOW':'bg-secondary'};
            var sev = (p.severity || 'UNKNOWN').toUpperCase();
            var sevBadge = '<span class="badge ' + (sevClass[sev] || 'bg-secondary') + '">' + esc(sev) + '</span>';
            var secBadge = p.isSecurityUpdate ? '<span class="badge bg-danger">Yes</span>' : '<span class="text-muted">No</span>';
            var status = p.installDate ? '<span class="badge bg-success">Installed</span>' : '<span class="badge bg-warning text-dark">Pending</span>';
            var released = p.releaseDate ? new Date(p.releaseDate * 1000).toLocaleDateString() : '-';
            t += '<tr><td>' + esc(p.title || '-') + '</td>'
                + '<td><code>' + esc(p.id || '-') + '</code></td>'
                + '<td>' + sevBadge + '</td>'
                + '<td>' + secBadge + '</td>'
                + '<td>' + status + '</td>'
                + '<td class="text-muted">' + released + '</td></tr>';
        });
        t += '</tbody></table></div>';
        return t;
    }

    html += '<h6 class="px-3 pt-3 mb-2">Pending Updates</h6>';
    html += pending.length ? patchTable(pending, 'pendingPatches') : '<p class="text-muted px-3">No pending updates.</p>';
    html += '<div id="installedSection" style="display:none">';
    html += '<h6 class="px-3 pt-3 mb-2">Installed Updates</h6>';
    html += installed.length ? patchTable(installed, 'installedPatches') : '<p class="text-muted px-3">No installed updates recorded.</p>';
    html += '</div>';

    setTimeout(function() {
        var btn = document.getElementById('toggleInstalled');
        if (btn) {
            btn.addEventListener('click', function() {
                var sec = document.getElementById('installedSection');
                var showing = sec.style.display !== 'none';
                sec.style.display = showing ? 'none' : '';
                btn.textContent = showing ? 'Show installed' : 'Hide installed';
            });
        }
    }, 0);

    return html;
}
```

---

### Task 8: AJAX Fetch Handler + Tab Wiring

**The core JS at the bottom of the Blade file that wires tabs to AJAX:**

```javascript
(function() {
    var cache = {};
    var assetId = {{ $asset->id }};
    var hasNinja = {{ $asset->ninja_id ? 'true' : 'false' }};
    var hasLevel = {{ $asset->level_id ? 'true' : 'false' }};

    document.querySelectorAll('[data-ajax-section]').forEach(function(tab) {
        tab.addEventListener('shown.bs.tab', function() {
            var section = this.dataset.ajaxSection;
            var pane = document.querySelector(this.getAttribute('href'));
            if (!pane || cache[section]) return;

            if (!hasNinja && !hasLevel) {
                pane.innerHTML = '<p class="text-muted p-4">Asset not linked to an RMM — no live data available.</p>';
                cache[section] = true;
                return;
            }

            // Show spinner (already in the pane from server render)
            fetch('/assets/' + assetId + '/device-data/' + section, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function(r) { return r.ok ? r.json() : Promise.reject(r); })
            .then(function(data) {
                if (data.error) {
                    pane.innerHTML = '<div class="alert alert-warning m-3"><i class="bi bi-exclamation-triangle me-2"></i>' + esc(data.error) + '</div>';
                } else {
                    pane.innerHTML = window['render' + section.charAt(0).toUpperCase() + section.slice(1)](data);
                }
                cache[section] = true;
            })
            .catch(function() {
                pane.innerHTML = '<div class="alert alert-danger m-3"><i class="bi bi-x-circle me-2"></i>Could not load data. Try refreshing the page.</div>';
                // Don't cache errors — allow retry
            });
        });
    });

    function esc(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
    window.esc = esc; // Make available to render functions
})();
```

The render functions from Tasks 4-7 should be defined before this block in the `@push('scripts')` section, or all together in one `<script>` block at the bottom.

---

### Summary of Tasks

| Task | What | Key Files |
|------|------|-----------|
| 1 | Person matching method | `Asset.php` |
| 2 | Device data AJAX endpoint | `AssetController.php`, `routes/web.php` |
| 3 | Blade rewrite — header + tabs + all 8 tab panes | `show.blade.php`, `AssetController.php` |
| 4 | JS render: Network tab | `show.blade.php` (JS) |
| 5 | JS render: Storage tab | `show.blade.php` (JS) |
| 6 | JS render: Software tab | `show.blade.php` (JS) |
| 7 | JS render: Patches tab | `show.blade.php` (JS) |
| 8 | JS AJAX fetch handler + tab wiring | `show.blade.php` (JS) |

**Note:** Tasks 3-8 are all in the same file (`show.blade.php`). They're separated in the plan for clarity but should be implemented as one commit — the complete blade rewrite.
