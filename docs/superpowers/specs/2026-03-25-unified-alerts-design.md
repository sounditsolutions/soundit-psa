# Unified RMM Alerts System

**Date:** 2026-03-25
**Status:** Approved

## Problem

RMM monitoring alerts are handled inconsistently: NinjaRMM has a dedicated `ninja_alerts` table with lifecycle tracking, Tactical RMM creates tickets directly, Comet and Huntress also create tickets directly. Technicians have no unified view of monitoring events across vendors. Alert noise that doesn't warrant a ticket still creates tickets, cluttering the ticket queue.

## Solution

A unified `alerts` table that stores all monitoring events from all vendors (Tactical, Ninja, Comet, Huntress). Alerts are first-class entities separate from tickets. Technicians view alerts in a dedicated dashboard, acknowledge them, and manually convert to tickets when warranted. No auto-ticket creation — alerts are alerts, tickets are tickets.

## Data Model

### `alerts` table

| Column | Type | Purpose |
|--------|------|---------|
| `id` | bigint PK | |
| `asset_id` | FK nullable | Linked PSA asset |
| `client_id` | FK nullable | Denormalized from asset for fast filtering |
| `source` | string | Vendor: `tactical`, `ninja`, `comet`, `huntress` |
| `source_alert_id` | string | Vendor's unique alert identifier |
| `severity` | string | `critical`, `error`, `warning`, `info` |
| `status` | string | `active`, `acknowledged`, `ticketed`, `resolved` |
| `title` | string | Short summary (check name, condition name) |
| `message` | text nullable | Full alert message / check output |
| `hostname` | string nullable | Device hostname (display even if asset not linked) |
| `ticket_id` | FK nullable | Linked ticket when converted |
| `acknowledged_by` | FK nullable | User who acknowledged |
| `acknowledged_at` | timestamp nullable | |
| `resolved_at` | timestamp nullable | |
| `refired_count` | unsignedInteger default 0 | How many times the same alert re-fired |
| `metadata` | JSON nullable | Vendor-specific data (agent_id, public_ip, OS, action results, etc.) |
| `fired_at` | timestamp | When the alert originally fired |
| `created_at` / `updated_at` | timestamps | |

**Indexes:**
- `[source, source_alert_id]` — unique composite
- `[client_id, status]` — dashboard queries
- `[asset_id, status]` — asset detail queries
- `[status, severity]` — alert dashboard sorting
- `[ticket_id]` — ticket linkage

### Enums

**AlertStatus:** `active`, `acknowledged`, `ticketed`, `resolved`

**AlertSource:** `tactical`, `ninja`, `comet`, `huntress`

**AlertSeverity:** `critical`, `error`, `warning`, `info`

### Severity Mapping

Each vendor uses different severity terminology. Map to the unified enum on ingestion:

| Vendor | Vendor Value | Unified Severity |
|--------|-------------|------------------|
| Tactical | `error` | `error` |
| Tactical | `warning` | `warning` |
| Tactical | `info` / `informational` | `info` |
| Ninja | `CRITICAL` | `critical` |
| Ninja | `MAJOR` | `error` |
| Ninja | `MODERATE` | `warning` |
| Ninja | `MINOR` | `info` |
| Comet | job failure (status 7002) | `error` |
| Huntress | `CRITICAL` | `critical` |
| Huntress | `HIGH` | `error` |
| Huntress | `LOW` | `warning` |

### Source Alert ID Format

Each vendor provides a unique identifier for dedup:

| Vendor | `source_alert_id` value |
|--------|------------------------|
| Tactical | `alert_id` from webhook payload (Tactical's internal alert ID) |
| Ninja | `seriesUid` from webhook payload |
| Comet | `{DeviceID}:{Classification}` (synthesized — Comet has no unique job failure ID) |
| Huntress | Incident report URL extracted from CW payload body |

## Alert Lifecycle

### Statuses and Transitions

- `active` → `acknowledged` — tech clicks Acknowledge
- `active` → `ticketed` — tech clicks Create Ticket
- `acknowledged` → `ticketed` — tech decides it needs a ticket
- Any state → `resolved` — RMM reports cleared, or tech manually resolves

### Deduplication

When a new alert arrives with the same `source + source_alert_id` as an existing active/acknowledged/ticketed alert, update the existing record: increment `refired_count`, update `message` with latest output, update `fired_at` to latest timestamp. Don't create duplicates.

### Resolution from RMM

When a vendor sends a resolved/reset webhook:
- Alert moves to `resolved` regardless of current state
- `resolved_at` set to the resolution timestamp
- If `ticket_id` is set, add a system note to the ticket: "Alert resolved by {source} monitoring"
- Ticket is NOT auto-closed — tech decides

**Behavior change from current system:** Previously, Tactical auto-resolved tickets on alert resolution, and Ninja auto-resolved tickets that had no human-written notes. The new system never auto-closes tickets from alert resolution — it only adds a note. This gives technicians full control over ticket lifecycle.

### Ticket Creation

When a tech clicks "Create Ticket" on an alert:
- Alert moves to `ticketed`, stores `ticket_id`
- Ticket created with `source = TicketSource::Alert`, `source_ref = alert.id`
- Ticket pre-filled: hostname in subject, severity mapped to priority (critical→P1, error→P2, warning→P3, info→P4), alert message as description
- Asset linked to ticket if `asset_id` is set
- Contact resolved from asset's primary user

### Bulk Ticket Creation

Each selected alert gets its own ticket. No grouping — alerts are independent. If a tech wants to group related issues, they can create one ticket manually and reference the alerts.

## Webhook Processing

Each vendor's webhook handler writes to the `alerts` table instead of creating tickets.

### Unmapped Devices

Alerts for devices that can't be resolved to a PSA client are still created (with `client_id = null`). They appear in the alerts dashboard as unlinked, giving visibility into monitoring events for unmapped devices. This is a change from the current behavior where unmapped device alerts are silently dropped.

### TacticalAlertService

- `handleAlertFailure()` → upserts `Alert` record with `source=tactical`, `source_alert_id=alert_id`
- `handleAlertResolved()` → resolves the `Alert`, adds note to linked ticket if exists
- Existing noise filters stay (transient errors, empty output, overdue workstations)
- Severity threshold gates alert creation, not ticket creation

### NinjaAlertService

- `handleTriggered()` → upserts `Alert` with `source=ninja`, `source_alert_id=seriesUid`
- `handleReset()` → resolves the `Alert`, adds note to linked ticket if exists
- No more auto-ticketing for CRITICAL/MAJOR — all severities become alerts

### CometAlertService

- `handleJobFailure()` → creates `Alert` with `source=comet`, `source_alert_id={DeviceID}:{Classification}`
- `handleJobSuccess()` → resolves matching alert, adds note to linked ticket

### HuntressService

Huntress is special — it uses a ConnectWise compatibility shim where Huntress expects to create and update tickets via CW-format API calls. Huntress sends a ticket creation request and expects a ticket ID back.

**Approach:** Huntress webhooks create BOTH an alert AND a ticket simultaneously. The alert provides the unified monitoring view; the ticket satisfies the CW compat contract. The alert is created in `ticketed` status with the `ticket_id` already linked. This preserves the existing Huntress integration behavior while adding alert tracking.

- Incident reports → creates `Alert` (status=`ticketed`) AND `Ticket`, links them
- Post-remediation PATCH → resolves the alert, updates ticket status as before

### Reconciliation

- `tactical:reconcile-alerts` — adapted to resolve `Alert` records by checking Tactical API
- `ninja:reconcile-alerts` — adapted to query/resolve `Alert` records instead of `NinjaAlert`

## AlertService

`app/Services/AlertService.php` — shared logic for all alert operations:

- `upsert(source, sourceAlertId, data)` — create or update alert, handle dedup
- `acknowledge(Alert, User)` — set acknowledged status
- `createTicket(Alert, ?User)` — convert alert to ticket, link them
- `resolve(Alert, ?string reason)` — resolve alert, note on ticket if linked
- `bulkAcknowledge(alertIds, User)` — bulk action
- `bulkCreateTickets(alertIds, User)` — bulk action (one ticket per alert)
- `bulkResolve(alertIds)` — bulk action

## UI

### Dashboard Card

"Active Alerts" card on main dashboard:
- Count by severity (e.g., "2 critical, 5 errors, 12 warnings")
- List of 5-10 most recent/critical alerts: severity badge, source icon, hostname, title, time
- Link to full alerts page

### Alerts Index Page (`/alerts`)

Flat table, newest first:
- Columns: severity badge, source icon, hostname (linked to asset), title, client, time, status
- Filters: severity, source, client, status
- Sortable by time (default newest first), severity
- Row actions: Acknowledge, Create Ticket, Resolve
- Bulk actions: Acknowledge selected, Create tickets for selected, Resolve selected

### Asset Detail Page

Replace existing Ninja alerts section with alerts from the unified `alerts` table. Shows all active alerts for the asset regardless of source.

### Client Detail Page

Alert count badge or small section showing active alerts for the client.

### Ticket Detail Page

If ticket was created from an alert (`source = Alert`), show a link back to the alert with its current status.

### Routes

Standard resource routes under staff auth (`web` guard):

- `GET /alerts` — index (with filters)
- `POST /alerts/{alert}/acknowledge` — acknowledge
- `POST /alerts/{alert}/create-ticket` — convert to ticket
- `POST /alerts/{alert}/resolve` — manual resolve
- `POST /alerts/bulk-acknowledge` — bulk acknowledge
- `POST /alerts/bulk-create-tickets` — bulk ticket creation
- `POST /alerts/bulk-resolve` — bulk resolve

## Migration

### Data Migration

Migrate all `ninja_alerts` records into `alerts`:
- `ninja_alert_uid` → `source_alert_id`
- `source` = `ninja`
- `condition_name` → `title`
- `status` maps directly (`active`/`resolved`)
- `ninja_device_id` → `metadata` JSON
- `client_id` populated from `asset.client_id` where `asset_id` is not null
- Severity mapped: CRITICAL→critical, MAJOR→error, MODERATE→warning, MINOR→info
- Preserve `ticket_id`, `asset_id`, `fired_at`, `resolved_at`

Drop `ninja_alerts` table after migration.

### Existing Tickets

- Tickets with `source = tactical_rmm` or `source = ninja_alert` remain valid for history
- `TicketSource::Alert` is the new source for alert-created tickets going forward
- Old ticket sources are not modified

### Code Cleanup

- Remove `NinjaAlert` model
- Remove `ninja_alerts` / `activeNinjaAlerts` relationships from Asset model
- Add `alerts()` and `activeAlerts()` relationships to Asset model
- Update ContextBuilder to use `Alert` model for triage context
- Update JunkDetector exemption to check `TicketSource::Alert`
- `source_ref` on tickets stores `alerts.id`

## Files to Create

| File | Purpose |
|------|---------|
| `app/Models/Alert.php` | Unified alert model |
| `app/Enums/AlertStatus.php` | active, acknowledged, ticketed, resolved |
| `app/Enums/AlertSource.php` | tactical, ninja, comet, huntress |
| `app/Enums/AlertSeverity.php` | critical, error, warning, info |
| `app/Services/AlertService.php` | Shared alert operations |
| `app/Http/Controllers/Web/AlertController.php` | Alert dashboard and actions |
| `resources/views/alerts/index.blade.php` | Alerts index page |
| `resources/views/dashboard/_alerts-card.blade.php` | Dashboard alerts summary |
| Migration: create alerts, migrate data, drop ninja_alerts | |

## Files to Modify

| File | Change |
|------|--------|
| `app/Services/Tactical/TacticalAlertService.php` | Write to Alert instead of Ticket |
| `app/Services/Ninja/NinjaAlertService.php` | Write to Alert instead of NinjaAlert |
| `app/Services/Comet/CometAlertService.php` | Write to Alert instead of Ticket |
| `app/Services/Huntress/HuntressService.php` | Create Alert alongside Ticket (CW compat) |
| `app/Console/Commands/TacticalReconcileAlerts.php` | Resolve Alert records |
| `app/Console/Commands/NinjaReconcileAlerts.php` | Use Alert model |
| `app/Models/Asset.php` | Replace ninja alert relationships with unified alerts |
| `app/Enums/TicketSource.php` | Add `Alert` case |
| `app/Services/Triage/ContextBuilder.php` | Use Alert model for active alerts |
| `app/Services/Triage/JunkDetector.php` | Update exemption |
| `resources/views/assets/show.blade.php` | Replace ninja alerts section |
| `resources/views/dashboard/*.blade.php` | Add alerts card |
| `routes/web.php` | Alert routes |

## Scope Boundaries

- No auto-ticket creation — all alerts are manual-to-ticket for now (auto-rules are a future phase)
- No alert notification system (email/SMS for critical alerts) — future phase
- No alert grouping or correlation — each alert is independent
- AI triage still runs on tickets, not alerts — alerts feed context but triage acts on tickets
- No settings/config class for alerts — no toggles needed for MVP (each vendor's existing config gates whether alerts are received)
- No retention/cleanup policy for resolved alerts — future phase
