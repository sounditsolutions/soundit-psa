# Tactical RMM Integration with AI Triage and Assistant

**Date:** 2026-03-23
**Bead:** psa-wf8
**Status:** Approved

## Problem

The AI triage pipeline has rich tool access for NinjaRMM, CIPP, Mesh, Control D, and Zorus, but no access to Tactical RMM data. With 100+ agents now on Tactical, the AI can't see device status, check results, hardware details, network config, or run diagnostics when triaging tickets.

## Solution

Three layers of Tactical integration into the AI triage pipeline:

1. **Context enrichment** — automatically include Tactical device health in the ticket context
2. **Query tools** — let the AI query device details, checks, network, software, services on demand
3. **Diagnostic tools** — let the AI run safe, read-only diagnostic scripts on the device

## Layer 1: Context Enrichment (ContextBuilder)

When a ticket has a linked asset with a `tactical_asset`, the `buildAssetSection()` in `ContextBuilder` automatically includes:

- Online/offline/overdue status
- Check summary: "13 passing, 2 failing"
- Failing check names and most recent output (truncated)
- Hardware summary: CPU, RAM, disk space, make/model
- OS version
- Public IP
- Logged-in user
- Needs reboot flag
- Uptime

This requires no tool call — it's always present in the context when the asset has Tactical data.

**Data source:** `TacticalAsset` model (local DB, synced daily) + live API call for check results.

**Truncation:** Max 1KB per asset for Tactical context to stay within token budget.

## Layer 2: Query Tools (TechnicalTriager)

Six new tools added to `TriageToolDefinitions`, gated by `TacticalConfig::isConfigured()`:

### `tactical_get_device`

Query comprehensive device info from Tactical API.

**Input:** `hostname` (string) — matched against `tactical_assets.hostname`
**Returns:** Online status, OS, CPU, RAM, disks (volumes with free space), make/model, graphics, public IP, local IPs, logged-in user, uptime, needs reboot, agent version, last seen, check summary.

### `tactical_get_device_checks`

Get all check results for a device with their output.

**Input:** `hostname` (string)
**Returns:** Array of checks with: name, status (passing/failing/info), return code, stdout (truncated to 500 chars each), last run time.

### `tactical_get_device_network`

Get network configuration from WMI detail.

**Input:** `hostname` (string)
**Returns:** For each enabled adapter: IP address, subnet, gateway, DNS servers, DHCP enabled/server, MAC address, adapter name.

### `tactical_get_device_software`

Get installed software list.

**Input:** `hostname` (string)
**Returns:** Array of: name, version, publisher. Sorted alphabetically.

### `tactical_get_device_services`

Get Windows services and their status.

**Input:** `hostname` (string), `filter` (optional string — "running", "stopped", or search term)
**Returns:** Array of: name, display name, status, start type. Filtered/limited to 50 results.

### `tactical_get_device_disks`

Get physical disk and volume details.

**Input:** `hostname` (string)
**Returns:** Physical disks (model, size, media type) + volumes (drive letter, filesystem, total, free, percent used).

## Layer 3: Diagnostic Tools (Read-Only Scripts)

### `tactical_run_diagnostic`

Run a pre-approved diagnostic script on the device and return the output.

**Input:** `hostname` (string), `diagnostic` (string — must be from allow-list)
**Returns:** Script stdout, stderr, return code, execution time.

**Allow-list of diagnostics:**

| Diagnostic Key | Description | Script |
|---|---|---|
| `event_log_errors` | Get critical/error events from last 24h | PowerShell: Get-WinEvent filtered |
| `top_processes` | Top 15 processes by CPU and memory | PowerShell: Get-Process sorted |
| `network_test` | Ping test to 8.8.8.8 + DNS resolution test | PowerShell: Test-Connection + Resolve-DnsName |
| `disk_health` | SMART status and disk health | Uses existing Monitor SMART Disk Status script |
| `windows_update_history` | Last 10 Windows updates installed | PowerShell: Get-HotFix |
| `printer_status` | List printers and their status | PowerShell: Get-Printer |
| `startup_programs` | List startup programs | PowerShell: Get-CimInstance Win32_StartupCommand |
| `uptime_detail` | Uptime, last boot, pending reboot reasons | PowerShell: checks registry keys |
| `dns_config` | Current DNS configuration and cache stats | PowerShell: Get-DnsClientServerAddress |
| `firewall_status` | Firewall profiles and their status | PowerShell: Get-NetFirewallProfile |

Each diagnostic script is stored in Tactical's script library with a `SITS:Diagnostics` category. The PSA maintains a mapping of diagnostic keys to Tactical script IDs.

**Timeout:** 30 seconds per diagnostic. If the device is offline, returns an error immediately.

**Safety:** All diagnostic scripts are read-only. No changes to the system. The allow-list is hardcoded in `TriageToolDefinitions` — the AI cannot request arbitrary scripts.

## Implementation

### Files to Create

| File | Purpose |
|------|---------|
| `app/Services/Triage/TacticalTriageTools.php` | Tool definitions for Tactical integration |
| `app/Services/Triage/TacticalTriageExecutor.php` | Tool execution — API calls and script dispatch |

### Files to Modify

| File | Change |
|------|--------|
| `app/Services/Triage/TriageToolDefinitions.php` | Add Tactical tools (gated by TacticalConfig) |
| `app/Services/Triage/TriageToolExecutor.php` | Add Tactical tool execution cases |
| `app/Services/Triage/ContextBuilder.php` | Enrich asset section with Tactical data |
| `app/Services/Tactical/TacticalClient.php` | Add methods for agent detail, checks, WMI data |

### Tactical Script Library

Create 10 diagnostic scripts in Tactical under `SITS:Diagnostics` category. Store a mapping of diagnostic key → Tactical script ID in PSA settings or as a constant in `TacticalTriageExecutor`.

### Tool Resolution Flow

1. AI receives ticket context (which now includes Tactical device health from ContextBuilder)
2. AI decides to investigate further → calls `tactical_get_device_checks` to see failing checks
3. AI sees a failing check → calls `tactical_run_diagnostic` to get more detail
4. AI synthesizes findings into the triage note

### Client Scoping

All Tactical tool calls enforce client scoping:
- `hostname` is resolved via `tactical_assets` table → `asset` → `client_id`
- Must match the ticket's `client_id`
- Cross-client queries are blocked (same pattern as existing Ninja tools)

## Scope Boundaries

- Tactical tools **coexist** with Ninja tools — both are available if configured
- Diagnostic scripts are **read-only** — no remediation from triage (that's what the check-failure tasks handle)
- Script execution has a **30-second timeout** — long-running diagnostics are not suitable for triage
- The AI assistant (interactive chat) gets the same tools but could be expanded to include write operations in a future phase
- No changes to the triage prompt templates — the AI discovers Tactical tools via the existing tool_use mechanism
