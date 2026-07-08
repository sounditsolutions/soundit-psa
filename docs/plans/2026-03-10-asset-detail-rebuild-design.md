# Asset Detail Page Rebuild — Design

**Goal:** Complete rebuild of the asset detail page with tabbed interface showing rich technical data from Ninja, Level, and CIPP APIs via on-demand AJAX fetching.

## Layout

Replace the current card-grid layout with a **tabbed interface**. The header stays (hostname, status badge, contract badges, edit/refresh buttons). Below the header: 8 tabs.

## Tab 1: Overview (default)

Consolidates current Device Info + Hardware + Sync Info + Client cards into a single dense view. Two-column layout:

**Left column — Device Identity:**
- Hostname, Name (if different), Type, Serial, OS
- IP Address, Last User -> **Person badge** if matched (match `last_user` against `people.email`, `people.cipp_upn`, or fuzzy name match scoped to client), raw username fallback
- Warranty: start date, end date, status (Active/Expired with color), system age
- Client badge, Contract badges

**Right column — Hardware & Status:**
- Online/Offline status with colored dot + last seen
- Uptime (from `last_boot_at`) + needs reboot warning
- CPU, RAM, Disk summary
- RMM source (Ninja/Level) with console link + last synced
- Refresh button

## Tabs 2-5: On-demand AJAX from Ninja API

Each tab fetches data from the Ninja API on first click. Shows a spinner while loading. Caches in a JS object per tab for the page session (no re-fetch on repeated clicks). For Level-linked assets, tabs that require Ninja-only endpoints show "Not available for Level devices" with a muted message.

### Tab 2 — Network
- `GET /v2/device/{ninjaId}/network-interfaces`
- Table: Interface Name, Status (connected/disconnected badge), MAC Address, IPv4, IPv6, Subnet, Gateway, DNS Servers, DHCP (yes/no), Speed
- For Level assets: show the IPs we have stored (limited data from Level API)

### Tab 3 — Storage
- Physical disks: `GET /v2/device/{ninjaId}/disks` -> Model, Capacity, Interface (NVMe/SATA), Media Type (SSD/HDD), SMART Status (badge), Temperature
- Logical volumes: `GET /v2/device/{ninjaId}/volumes` -> Drive letter, Capacity, Free Space, % Used (progress bar), File System
- For Level assets: show disk partitions from stored `disk_summary` (less detail)

### Tab 4 — Software
- `GET /v2/device/{ninjaId}/software`
- Searchable table: Name, Version, Vendor, Install Date
- Client-side search box filters the table as you type
- Sort by name (default), vendor, or version

### Tab 5 — Patches
- `GET /v2/device/{ninjaId}/os-patches`
- Table: Title, KB Article, Severity (badge: Critical/Important/Moderate/Low), Security Update (yes/no), Status (pending/installed/failed), Release Date
- Filter to show pending only by default, toggle to show all

## Tab 6: Security (hybrid — DB data + AJAX)

Consolidates existing Control D, Zorus, and M365/Intune sections into one tab. All from stored DB data (no AJAX needed):

- **Intune Compliance** — compliance state badge, enrollment type, OS version, ownership, last sync
- **Defender** — status, version, last scan
- **DNS Security (Control D)** — profile, device status, agent status/version, last seen. Activity log button (existing AJAX pattern)
- **DNS Security (Zorus)** — group, filtering/CyberSight status, agent version/state
- **MFA Status** — from person's `mfa_enabled` field (if person mapped)
- Link/unlink dropdowns for Control D and Zorus (existing functionality, moved here)

## Tab 7: Alerts & Tickets

Consolidates existing NinjaRMM Alerts and Recent Tickets sections:

- **Active Alerts** table — severity, condition, message, fired time, linked ticket
- **Resolved Alerts** (collapsible) — same + resolved time
- **Linked Tickets** — expanded from 5 to 15 most recent, add Priority column

## Tab 8: Backup

Consolidates existing Backup Storage and Backup Jobs sections:

- **Storage Stats** — cloud, local, revisions
- **Recent Backup Jobs** table
- **Integrity Checks** table

## AJAX Endpoint

Single new endpoint: `GET /assets/{asset}/device-data/{section}`

Where `section` is one of: `network`, `storage`, `software`, `patches`

The controller determines the RMM source (Ninja or Level), calls the appropriate API with a 10s timeout, and returns JSON. No server-side caching (data changes frequently, the page-session JS cache is sufficient). On failure, returns `{"error": "..."}` and the JS shows a friendly "Could not load data" message.

For Ninja: proxies to the appropriate `/v2/device/{ninjaId}/{subResource}` endpoints.
For Level: returns whatever stored DB data we have (Level's API doesn't expose these sub-resources).

## Person Matching

New helper method on `Asset` model: `resolveLastUserPerson(): ?Person`

Logic (scoped to `$this->client_id`):
1. If `last_user` contains `\` (domain\username), extract username portion
2. Match against `people.cipp_upn` (startsWith username@)
3. Match against `people.email` (startsWith username@)
4. Match against `people.first_name . ' ' . people.last_name` (case-insensitive)
5. Return first match or null

Called once on page load for the Overview tab. Result displayed as `<x-person-badge>` with fallback to raw username.

## Files Changed

| File | Change |
|------|--------|
| `resources/views/assets/show.blade.php` | Complete rewrite — tabbed layout |
| `app/Http/Controllers/Web/AssetController.php` | Add `deviceData()` endpoint |
| `routes/web.php` | Add device-data route |
| `app/Models/Asset.php` | Add `resolveLastUserPerson()` |

No new migrations, no new tables, no new sync jobs.
