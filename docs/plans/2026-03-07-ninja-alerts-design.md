# NinjaRMM Alert Integration

## Overview

Ingest NinjaRMM monitoring alerts into the PSA. All alerts are stored against assets as informational records. Critical and Major severity alerts also create tickets that auto-resolve when the alert clears in Ninja.

## Data Model

### `ninja_alerts` table

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint PK | |
| `asset_id` | FK to assets | nullable (device may not be mapped yet) |
| `ninja_device_id` | int | Ninja device ID from webhook |
| `ninja_alert_uid` | string | Unique alert identifier from Ninja |
| `severity` | string | critical, major, moderate, minor |
| `condition_name` | string | e.g. "Disk Space", "CPU Usage" |
| `message` | text | Alert description/details |
| `status` | string | active, resolved |
| `ticket_id` | FK to tickets | nullable — only set for Critical/Major |
| `fired_at` | datetime | When the alert originally fired |
| `resolved_at` | datetime | nullable — when the alert cleared |
| `timestamps` | | created_at, updated_at |

Unique constraint on `ninja_alert_uid` for dedup.

## Webhook Flow

### Alert Fires

1. Ninja sends webhook to existing `NinjaWebhookController`
2. `ProcessNinjaWebhook` handles alert activity types
3. Look up asset by `ninja_device_id` (`Asset::where('ninja_id', $deviceId)`)
4. Create `ninja_alert` record (or update if same `ninja_alert_uid` already exists and still active)
5. If severity is Critical or Major:
   - Check for existing open ticket with same `ninja_alert_uid` via `ninja_alerts.ticket_id` — if found, add a note ("Alert re-fired")
   - Otherwise create ticket:
     - `TicketSource::NinjaAlert`
     - `TicketType::Incident`
     - Link the asset to the ticket
     - Priority: Critical → P1, Major → P2
     - Subject: `[Ninja Alert] {condition_name} on {hostname}`
     - Description: alert message + device details
   - Store `ticket_id` on the `ninja_alert` record

### Alert Resolves

1. Update `ninja_alert` record: `status = resolved`, set `resolved_at`
2. If a linked ticket exists:
   - Check if human-touched: any non-system notes (not AiTriage, not System, not StatusChange), or manual status changes
   - If untouched: auto-resolve ticket with note "Alert cleared in NinjaRMM monitoring"
   - If human-touched: add note "Alert cleared in NinjaRMM monitoring" but leave status alone

## Severity → Priority Mapping

| Ninja Severity | Creates Ticket? | Ticket Priority |
|---------------|----------------|-----------------|
| Critical | Yes | P1 |
| Major | Yes | P2 |
| Moderate | No (informational only) | — |
| Minor | No (informational only) | — |

## New Enum Values

- `TicketSource::NinjaAlert` — new source for alert-generated tickets

## Asset Detail Page

- New "Alerts" card showing active alerts with severity badges (red for critical, yellow for major, blue for moderate/minor)
- Badge count on card header
- Expandable/toggle to view resolved alert history
- Links to associated tickets when present

## Triage Integration

- `ContextBuilder::buildAssetSection()` includes active ninja alert count and details for linked assets
- `JunkDetector` skips `TicketSource::NinjaAlert` tickets (not junk)
- Triage can reference active alerts when analyzing related tickets

## Deduplication

- Keyed on `ninja_alert_uid` (unique from Ninja)
- If an active alert already exists with the same UID, update it rather than creating a duplicate
- If a linked ticket is still open, add a note instead of creating a new ticket

## Files Changed

| File | Change |
|------|--------|
| New migration | `ninja_alerts` table |
| `app/Models/NinjaAlert.php` | New model |
| `app/Models/Asset.php` | `ninjaAlerts()` relationship |
| `app/Enums/TicketSource.php` | Add `NinjaAlert` |
| `app/Services/Ninja/NinjaAlertService.php` | Alert processing logic (create alert, create ticket, resolve) |
| `app/Jobs/ProcessNinjaWebhook.php` | Handle alert activity types, delegate to NinjaAlertService |
| `app/Services/Triage/JunkDetector.php` | Skip NinjaAlert source |
| `app/Services/Triage/ContextBuilder.php` | Include active alerts in asset section |
| `resources/views/assets/show.blade.php` | Alerts card |
| `resources/views/tickets/show.blade.php` | Alert source badge (if needed) |
