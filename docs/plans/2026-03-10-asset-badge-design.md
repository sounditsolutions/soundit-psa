# Enhanced Asset Badge with AJAX Popover + Ticket Index Column

**Goal:** Reusable asset badge component with near-real-time RMM data (uptime, online/offline, reboot status) fetched on hover via AJAX, plus an assets column on the ticket index.

## Data Model

Two new columns on `assets`:

| Column | Type | Source |
|--------|------|--------|
| `last_boot_at` | timestamp, nullable | Ninja `lastBootTime` / Level `last_reboot_time` |
| `needs_reboot` | boolean, nullable | Ninja `needsReboot` |

## Background Sync

**Ninja**: New `getOperatingSystems()` method on `NinjaClient` calling `GET /v2/queries/operating-systems`. Called during the existing 5-minute `ninja:sync-status` command. Keyed by `deviceId` to `ninja_id`. Stores `last_boot_at` and `needs_reboot`.

**Level**: Persist `last_reboot_time` (already in device response, currently only used as `last_seen_at` fallback) into `last_boot_at` during the existing 4-hour `level:sync-devices`.

## AJAX Quick-Look Endpoint

`GET /assets/{asset}/quick-look` — authenticated staff route, returns JSON.

Response shape:
```json
{
  "name": "DESKTOP-ABC",
  "hostname": "DESKTOP-ABC",
  "asset_type": "Workstation",
  "os": "Windows 11 Pro",
  "serial": "ABC123",
  "status": "Online",
  "status_color": "#198754",
  "last_seen": "2 minutes ago",
  "uptime": "3d 5h",
  "needs_reboot": false,
  "contract": "TLC0002 - Managed Services",
  "rmm": "ninja",
  "rmm_url": "https://app.ninjarmm.com/#/deviceDashboard/123/overview"
}
```

### Flow

1. Check cache (`asset_quick_look:{id}`, 60s TTL). Return cached if fresh.
2. If stale, determine RMM source (`ninja_id` or `level_id`).
3. Call Ninja `GET /v2/device/{id}` or Level `GET /v2/devices/{id}` with short timeout (~5s).
4. Parse live data: boot time, online status, `needsReboot` (Ninja).
5. Update `last_boot_at`, `needs_reboot`, `rmm_online`, `last_seen_at` in DB as side effect.
6. Merge live RMM data with static DB fields (contract, serial, OS, type).
7. Cache merged result for 60s.
8. If RMM API fails or times out, return stored DB data immediately (stale but available).

Contract: `$asset->contracts()->first()` from DB. No API call needed.

## Asset Badge Component

Replace static `data-bs-content` popover with JS-driven AJAX popover.

### Behavior

- **Server render**: Status dot (from stored `rmm_online`) + hostname link. No static popover.
- **`mouseenter`**: Fetch `GET /assets/{id}/quick-look`.
- **Loading**: Show small spinner in popover while fetching (first hover only).
- **Render**: Build popover HTML from JSON response.
- **Status dot update**: JS updates the dot's `background` CSS from `status_color` in the response. Reflects live RMM state, not last sync.
- **Client cache**: Store response in a JS object keyed by asset ID. Don't re-fetch on repeated hovers during the same page session.
- **Popover layout**:
  - Header: hostname (bold) + asset type
  - Status: Online/Offline dot + uptime (e.g., "3d 5h") + reboot warning icon if `needs_reboot`
  - Contract name (if assigned)
  - OS, serial
  - "View in Ninja" / "View in Level" link (if `rmm_url` present)

### Fallback

If fetch fails (network error, 500), show a minimal popover with the hostname and "Status unavailable" in muted text. Don't break the page.

## Ticket Index Assets Column

- Add "Assets" column to the ticket index table.
- Eager-load `assets` relation in `TicketService::getTicketList()`.
- Show all linked assets as stacked `<x-asset-badge>` components.
- Each badge has the status dot + hostname with the AJAX popover.

## Files Changed

| File | Change |
|------|--------|
| New migration | Add `last_boot_at`, `needs_reboot` to `assets` |
| `app/Models/Asset.php` | Add fillable fields, casts |
| `app/Services/Ninja/NinjaClient.php` | Add `getOperatingSystems()` |
| `app/Services/Ninja/NinjaSyncService.php` | Sync OS data in status sync |
| `app/Services/Level/LevelSyncService.php` | Persist `last_reboot_time` to `last_boot_at` |
| `app/Http/Controllers/Web/AssetController.php` | Add `quickLook()` endpoint |
| `routes/web.php` | Add quick-look route |
| `resources/views/components/asset-badge.blade.php` | AJAX popover + dot update |
| `resources/views/tickets/index.blade.php` | Add assets column |
| `app/Services/TicketService.php` | Eager-load assets in ticket list query |
