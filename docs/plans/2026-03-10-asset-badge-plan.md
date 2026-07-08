# Enhanced Asset Badge with AJAX Popover Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Reusable asset badge with near-real-time RMM data (uptime, online/offline, reboot status) fetched on hover via AJAX, plus an assets column on the ticket index.

**Architecture:** Two new DB columns (`last_boot_at`, `needs_reboot`) on `assets`, synced from Ninja's OS queries endpoint (5-min cycle) and Level's existing device data (4-hour cycle). A new `quickLook()` AJAX endpoint fetches live RMM data on hover with 60s server cache and graceful fallback. The asset badge Blade component is upgraded from a static Bootstrap popover to a JS-driven AJAX popover that also live-updates the status dot color.

**Tech Stack:** Laravel 12, Blade, Bootstrap 5.3, vanilla JS (no build step), Guzzle HTTP

**Design doc:** `docs/plans/2026-03-10-asset-badge-design.md`

---

### Task 1: Migration — Add `last_boot_at` and `needs_reboot` to `assets`

**Files:**
- Create: `database/migrations/2026_03_10_XXXXXX_add_boot_fields_to_assets.php`

**Step 1: Create the migration**

```bash
php artisan make:migration add_boot_fields_to_assets --table=assets
```

**Step 2: Write the migration**

```php
public function up(): void
{
    Schema::table('assets', function (Blueprint $table) {
        $table->timestamp('last_boot_at')->nullable()->after('last_seen_at');
        $table->boolean('needs_reboot')->nullable()->after('last_boot_at');
    });
}

public function down(): void
{
    Schema::table('assets', function (Blueprint $table) {
        $table->dropColumn(['last_boot_at', 'needs_reboot']);
    });
}
```

**Step 3: Run the migration**

```bash
php artisan migrate
```

**Step 4: Commit**

```bash
git add database/migrations/*add_boot_fields_to_assets*
git commit -m "Add last_boot_at and needs_reboot columns to assets table"
```

---

### Task 2: Asset Model — Add fillable fields and casts

**Files:**
- Modify: `app/Models/Asset.php:16-90`

**Step 1: Add `last_boot_at` and `needs_reboot` to `$fillable`**

In `app/Models/Asset.php`, add to the `$fillable` array (after `last_seen_at` on line 41):

```php
'last_boot_at',
'needs_reboot',
```

**Step 2: Add casts**

In the `casts()` method (after `'last_seen_at' => 'datetime'` on line 76), add:

```php
'last_boot_at' => 'datetime',
'needs_reboot' => 'boolean',
```

**Step 3: Commit**

```bash
git add app/Models/Asset.php
git commit -m "Add last_boot_at and needs_reboot to Asset model fillable and casts"
```

---

### Task 3: NinjaClient — Add `getOperatingSystems()` method

**Files:**
- Modify: `app/Services/Ninja/NinjaClient.php:138` (after `getDeviceDetail()`)

**Step 1: Add the method**

Add after `getDeviceDetail()` (after line 138 in NinjaClient.php):

```php
/**
 * Fetch OS data for all devices (boot time, reboot status).
 *
 * Endpoint: GET /v2/queries/operating-systems
 * Response: {"results": [...], "cursor": {"name": "...", "count": N}}
 * Each result: deviceId, lastBootTime (unix double), needsReboot (bool),
 *              name, manufacturer, buildNumber, releaseId, architecture.
 */
public function getOperatingSystems(int $pageSize = 500): array
{
    $allRecords = [];
    $cursorName = null;

    do {
        $params = ['pageSize' => $pageSize];
        if ($cursorName !== null) {
            $params['cursor'] = $cursorName;
        }

        $response = $this->get('/v2/queries/operating-systems', $params);

        $results = $response['results'] ?? [];
        $allRecords = array_merge($allRecords, $results);

        if (count($results) < $pageSize) {
            break;
        }

        $cursorName = $response['cursor']['name'] ?? null;

        if ($cursorName === null) {
            break;
        }
    } while (true);

    return $allRecords;
}
```

This follows the exact same cursor-based pagination pattern as `getBackupUsage()` (line 147).

**Step 2: Commit**

```bash
git add app/Services/Ninja/NinjaClient.php
git commit -m "Add getOperatingSystems() to NinjaClient for boot time and reboot data"
```

---

### Task 4: NinjaSyncService — Sync OS data during 5-minute status sync

**Files:**
- Modify: `app/Services/Ninja/NinjaSyncService.php:221-261`

**Step 1: Add OS data sync method**

Add a new private method after `syncStatusForClient()` (after line 261):

```php
/**
 * Sync OS data (boot time, reboot status) for all Ninja-linked assets.
 * Called alongside the 5-minute status sync.
 */
public function syncOsData(): void
{
    try {
        $osRecords = $this->ninja->getOperatingSystems();
    } catch (NinjaClientException $e) {
        Log::warning('[NinjaSync] Failed to fetch OS data: ' . $e->getMessage());
        return;
    }

    $lookup = collect($osRecords)->keyBy('deviceId');

    Asset::whereNotNull('ninja_id')
        ->chunk(200, function ($assets) use ($lookup) {
            foreach ($assets as $asset) {
                $os = $lookup->get($asset->ninja_id);
                if (!$os) {
                    continue;
                }

                $updates = [];

                if (!empty($os['lastBootTime'])) {
                    $updates['last_boot_at'] = Carbon::createFromTimestamp((int) $os['lastBootTime']);
                }

                if (isset($os['needsReboot'])) {
                    $updates['needs_reboot'] = (bool) $os['needsReboot'];
                }

                if (!empty($updates)) {
                    $asset->update($updates);
                }
            }
        });
}
```

**Step 2: Find the artisan command that calls `syncStatusForClient()` and add the OS sync call**

Find where `syncStatusForClient` is called from the scheduler. Search for the `ninja:sync-status` command.

```bash
grep -r 'syncStatusForClient\|ninja:sync-status' app/Console/ --include='*.php' -l
```

Add `$ninjaSyncService->syncOsData()` call after the status sync loop completes. The OS endpoint is global (not per-org), so it only needs one call.

**Step 3: Commit**

```bash
git add app/Services/Ninja/NinjaSyncService.php app/Console/Commands/Ninja*
git commit -m "Sync Ninja OS data (boot time, needs_reboot) in 5-minute status cycle"
```

---

### Task 5: LevelSyncService — Persist `last_reboot_time` to `last_boot_at`

**Files:**
- Modify: `app/Services/Level/LevelSyncService.php:118-151`

**Step 1: Add `last_boot_at` to the `$data` array**

In `upsertDeviceFromData()`, add after `'level_synced_at' => now()` (line 133):

```php
'last_boot_at' => !empty($device['last_reboot_time'])
    ? Carbon::parse($device['last_reboot_time'])
    : ($asset?->last_boot_at),
```

This reuses the same `last_reboot_time` field that `resolveLastSeen()` already parses (line 287-289), but stores it in `last_boot_at` instead of only using it as a `last_seen_at` fallback.

Note: Level doesn't provide a `needs_reboot` flag, so that field stays null for Level devices.

**Step 2: Commit**

```bash
git add app/Services/Level/LevelSyncService.php
git commit -m "Persist Level last_reboot_time to last_boot_at during device sync"
```

---

### Task 6: Quick-Look AJAX Endpoint

**Files:**
- Modify: `app/Http/Controllers/Web/AssetController.php`
- Modify: `routes/web.php`

**Step 1: Add the route**

In `routes/web.php`, after the existing asset routes (after line 376), add:

```php
Route::get('/assets/{asset}/quick-look', [AssetController::class, 'quickLook'])->name('assets.quickLook');
```

**Step 2: Add the `quickLook()` method to AssetController**

Add to `AssetController.php`:

```php
public function quickLook(Asset $asset, NinjaSyncService $ninjaSync)
{
    $cacheKey = "asset_quick_look:{$asset->id}";
    $cached = Cache::get($cacheKey);
    if ($cached) {
        return response()->json($cached);
    }

    // Live RMM fetch with short timeout
    $liveData = $this->fetchLiveRmmData($asset, $ninjaSync);

    // Merge live data with DB fields
    $contract = $asset->contracts()->first();
    $statusLabel = $asset->statusBadge;
    $statusColor = match ($statusLabel) {
        'Online' => '#198754',
        'Offline' => '#dc3545',
        default => '#6c757d',
    };

    // Compute uptime from last_boot_at
    $uptime = null;
    if ($asset->last_boot_at) {
        $diff = $asset->last_boot_at->diff(now());
        $parts = [];
        if ($diff->days > 0) $parts[] = $diff->days . 'd';
        if ($diff->h > 0) $parts[] = $diff->h . 'h';
        if (empty($parts)) $parts[] = $diff->i . 'm';
        $uptime = implode(' ', $parts);
    }

    // Determine RMM source and URL
    $rmm = null;
    $rmmUrl = null;
    if ($asset->ninja_id) {
        $rmm = 'ninja';
        $rmmUrl = $asset->ninja_url;
    } elseif ($asset->level_id) {
        $rmm = 'level';
        $rmmUrl = $asset->level_url;
    }

    $result = [
        'name' => $asset->name,
        'hostname' => $asset->hostname,
        'asset_type' => $asset->asset_type,
        'os' => $asset->os,
        'serial' => $asset->serial_number,
        'status' => $statusLabel,
        'status_color' => $statusColor,
        'last_seen' => $asset->last_seen_at?->diffForHumans(),
        'uptime' => $uptime,
        'needs_reboot' => $asset->needs_reboot,
        'contract' => $contract
            ? ($contract->contract_number . ' - ' . $contract->name)
            : null,
        'rmm' => $rmm,
        'rmm_url' => $rmmUrl,
    ];

    Cache::put($cacheKey, $result, 60);

    return response()->json($result);
}

/**
 * Fetch live status from RMM with short timeout. Updates DB as side effect.
 */
private function fetchLiveRmmData(Asset $asset, NinjaSyncService $ninjaSync): void
{
    if ($asset->ninja_id) {
        try {
            $device = app(NinjaClient::class)->getDevice($asset->ninja_id, timeout: 5);
            $lastContact = isset($device['lastContact'])
                ? Carbon::createFromTimestamp($device['lastContact'])
                : null;

            $asset->update([
                'last_seen_at' => $lastContact ?? $asset->last_seen_at,
                'rmm_online' => $lastContact && $lastContact->diffInMinutes(now()) <= 10,
            ]);
        } catch (\Throwable $e) {
            Log::debug("[AssetController] Quick-look Ninja fetch failed: {$e->getMessage()}");
        }
    } elseif ($asset->level_id) {
        try {
            $levelClient = app(LevelClient::class);
            $device = $levelClient->getDevice($asset->level_id);

            $asset->update([
                'rmm_online' => !empty($device['online']),
                'last_seen_at' => !empty($device['online']) ? now() : $asset->last_seen_at,
                'last_boot_at' => !empty($device['last_reboot_time'])
                    ? Carbon::parse($device['last_reboot_time'])
                    : $asset->last_boot_at,
            ]);
        } catch (\Throwable $e) {
            Log::debug("[AssetController] Quick-look Level fetch failed: {$e->getMessage()}");
        }
    }
}
```

Don't forget to add the necessary `use` statements at the top of AssetController:

```php
use App\Services\Ninja\NinjaClient;
use App\Services\Level\LevelClient;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;
```

**Step 3: Commit**

```bash
git add app/Http/Controllers/Web/AssetController.php routes/web.php
git commit -m "Add asset quick-look AJAX endpoint with live RMM fetch and caching"
```

---

### Task 7: Asset Badge — AJAX Popover with Live Status Dot

**Files:**
- Modify: `resources/views/components/asset-badge.blade.php` (full rewrite)

**Step 1: Replace the static popover with AJAX-driven popover**

Replace the entire contents of `resources/views/components/asset-badge.blade.php`:

```blade
{{-- Expects: asset model instance --}}
@props(['asset' => null, 'link' => true, 'popover' => true, 'fallback' => '—'])

@if($asset)
    @php
        $statusLabel = $asset->statusBadge;
        $dotColor = match($statusLabel) {
            'Online' => '#198754',
            'Offline' => '#dc3545',
            default => '#6c757d',
        };
        $displayName = $asset->hostname ?: $asset->name;
    @endphp
    <div class="d-inline-flex align-items-center gap-1 asset-badge-wrapper"
         @if($popover) data-asset-id="{{ $asset->id }}" @endif
    >
        <span class="d-inline-block rounded-circle flex-shrink-0 asset-status-dot"
              style="width:8px;height:8px;background:{{ $dotColor }};"></span>
        @if($link)
            <a href="{{ route('assets.show', $asset) }}" class="text-decoration-none text-truncate"
               style="max-width: 200px" {{ $attributes }}>{{ $displayName }}</a>
        @else
            <span class="text-truncate" style="max-width: 200px" {{ $attributes }}>{{ $displayName }}</span>
        @endif
    </div>
@else
    <span class="text-muted">{{ $fallback }}</span>
@endif
```

**Step 2: Add the AJAX popover JS**

Add a `@once` block at the bottom of the component (or in the layout `@push('scripts')` stack — whichever the project uses). This JS should:

1. Listen for `mouseenter` on `.asset-badge-wrapper[data-asset-id]`
2. Fetch `/assets/{id}/quick-look` (client-side cache by asset ID)
3. Show a Bootstrap popover with the response data
4. Update the `.asset-status-dot` background color from `status_color`

```blade
@once
@push('scripts')
<script>
(function() {
    const cache = {};
    let activePopover = null;

    document.addEventListener('mouseenter', function(e) {
        const wrapper = e.target.closest('.asset-badge-wrapper[data-asset-id]');
        if (!wrapper) return;

        const assetId = wrapper.dataset.assetId;
        const dot = wrapper.querySelector('.asset-status-dot');

        // Dismiss any existing popover
        if (activePopover) {
            activePopover.dispose();
            activePopover = null;
        }

        if (cache[assetId]) {
            showPopover(wrapper, dot, cache[assetId]);
            return;
        }

        // Show loading popover
        const loadingPopover = new bootstrap.Popover(wrapper, {
            html: true,
            content: '<div class="text-center py-2"><div class="spinner-border spinner-border-sm" role="status"></div></div>',
            trigger: 'manual',
            placement: 'auto',
        });
        loadingPopover.show();
        activePopover = loadingPopover;

        fetch('/assets/' + assetId + '/quick-look', {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(r => r.ok ? r.json() : Promise.reject(r))
        .then(data => {
            cache[assetId] = data;
            loadingPopover.dispose();
            activePopover = null;

            // Only show if still hovering
            if (wrapper.matches(':hover')) {
                showPopover(wrapper, dot, data);
            }
            // Always update dot color
            if (dot && data.status_color) {
                dot.style.background = data.status_color;
            }
        })
        .catch(() => {
            loadingPopover.dispose();
            activePopover = null;
            if (wrapper.matches(':hover')) {
                showPopover(wrapper, dot, { _error: true, hostname: wrapper.textContent.trim() });
            }
        });
    }, true);

    document.addEventListener('mouseleave', function(e) {
        const wrapper = e.target.closest('.asset-badge-wrapper[data-asset-id]');
        if (!wrapper && activePopover) {
            activePopover.dispose();
            activePopover = null;
        }
        if (wrapper && activePopover) {
            activePopover.dispose();
            activePopover = null;
        }
    }, true);

    function showPopover(wrapper, dot, data) {
        if (data._error) {
            const content = '<strong>' + escHtml(data.hostname) + '</strong><br><span class="text-muted">Status unavailable</span>';
            const p = new bootstrap.Popover(wrapper, { html: true, content: content, trigger: 'manual', placement: 'auto' });
            p.show();
            activePopover = p;
            return;
        }

        // Update dot
        if (dot && data.status_color) {
            dot.style.background = data.status_color;
        }

        let html = '<strong>' + escHtml(data.hostname || data.name) + '</strong>';
        if (data.asset_type) html += ' <small class="text-muted">(' + escHtml(data.asset_type) + ')</small>';

        // Status line with uptime
        html += '<br><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:' + data.status_color + ';"></span> ';
        html += escHtml(data.status);
        if (data.uptime) html += ' &middot; Up ' + escHtml(data.uptime);
        if (data.needs_reboot) html += ' <i class="bi bi-arrow-repeat text-warning" title="Reboot needed"></i>';

        if (data.contract) html += '<br><small class="text-muted"><i class="bi bi-file-earmark-text me-1"></i>' + escHtml(data.contract) + '</small>';
        if (data.os) html += '<br><small class="text-muted"><i class="bi bi-pc-display me-1"></i>' + escHtml(data.os) + '</small>';
        if (data.serial) html += '<br><small class="text-muted">S/N: ' + escHtml(data.serial) + '</small>';
        if (data.last_seen) html += '<br><small class="text-muted">Last seen: ' + escHtml(data.last_seen) + '</small>';

        if (data.rmm_url) {
            const label = data.rmm === 'ninja' ? 'View in Ninja' : 'View in Level';
            html += '<br><a href="' + escHtml(data.rmm_url) + '" target="_blank" class="small" onclick="event.stopPropagation();">' + label + ' <i class="bi bi-box-arrow-up-right"></i></a>';
        }

        const p = new bootstrap.Popover(wrapper, {
            html: true,
            content: html,
            trigger: 'manual',
            placement: 'auto',
            sanitize: false,
        });
        p.show();
        activePopover = p;
    }

    function escHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
})();
</script>
@endpush
@endonce
```

**Step 3: Verify the layout has a `@stack('scripts')` section**

Check that the main layout (`resources/views/layouts/app.blade.php`) has `@stack('scripts')` before `</body>`. If not, add it.

**Step 4: Test manually**

Start the dev server, log in, navigate to a page with asset badges (e.g., a ticket detail page with linked assets), and hover over an asset badge. Verify:
- Spinner shows on first hover
- Popover appears with correct data
- Status dot color updates
- Repeated hovers use client cache (no spinner)
- If you unplug network, the fallback "Status unavailable" message shows

**Step 5: Commit**

```bash
git add resources/views/components/asset-badge.blade.php
git commit -m "Replace static asset badge popover with AJAX-driven live RMM popover"
```

---

### Task 8: Ticket Index — Add Assets Column with Eager Loading

**Files:**
- Modify: `app/Services/TicketService.php:447`
- Modify: `resources/views/tickets/index.blade.php:243,314`

**Step 1: Add `assets` to eager-load**

In `app/Services/TicketService.php`, line 447, change:

```php
->with(['client', 'assignee', 'latestTriageRun'])
```

to:

```php
->with(['client', 'assignee', 'latestTriageRun', 'assets'])
```

**Step 2: Add the "Assets" column header**

In `resources/views/tickets/index.blade.php`, after the Source `<th>` (line 243), add:

```blade
<th>Assets</th>
```

**Step 3: Add the assets column body**

In the `<tbody>` section, after the Source `<td>` (line 314), add:

```blade
<td class="small" onclick="event.stopPropagation()">
    @forelse($ticket->assets as $ticketAsset)
        <div class="mb-1"><x-asset-badge :asset="$ticketAsset" :link="false" /></div>
    @empty
        <span class="text-muted">-</span>
    @endforelse
</td>
```

Notes:
- `:link="false"` prevents navigation away from the ticket index when clicking the badge name. The whole row already links to the ticket via `onclick`.
- `onclick="event.stopPropagation()"` prevents the row click handler from firing when hovering/interacting with the popover.
- Each badge is wrapped in a `<div class="mb-1">` to stack vertically when multiple assets exist.

**Step 4: Test manually**

Start the dev server, navigate to `/tickets`, and verify:
- The "Assets" column appears
- Tickets with linked assets show stacked badges with status dots
- Hovering shows the AJAX popover
- Tickets without assets show a dash

**Step 5: Commit**

```bash
git add app/Services/TicketService.php resources/views/tickets/index.blade.php
git commit -m "Add Assets column to ticket index with eager-loaded asset badges"
```

---

## Summary

| Task | What | Files |
|------|------|-------|
| 1 | Migration: `last_boot_at`, `needs_reboot` | New migration |
| 2 | Asset model: fillable + casts | `app/Models/Asset.php` |
| 3 | NinjaClient: `getOperatingSystems()` | `app/Services/Ninja/NinjaClient.php` |
| 4 | NinjaSyncService: sync OS data in status cycle | `app/Services/Ninja/NinjaSyncService.php` |
| 5 | LevelSyncService: persist `last_reboot_time` → `last_boot_at` | `app/Services/Level/LevelSyncService.php` |
| 6 | Quick-look AJAX endpoint | `AssetController.php`, `routes/web.php` |
| 7 | Asset badge: AJAX popover + live status dot | `asset-badge.blade.php` |
| 8 | Ticket index: assets column | `TicketService.php`, `tickets/index.blade.php` |
