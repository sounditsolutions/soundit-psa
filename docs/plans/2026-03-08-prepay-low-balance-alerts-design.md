# Prepay Low-Balance Alerts & Auto Top-Up Design

**Goal:** Notify clients when their prepay balance is running low and optionally auto-generate a top-up invoice to prevent balance reaching zero.

## Data Model

Three new nullable columns on `contracts` table:

| Column | Type | Description |
|--------|------|-------------|
| `prepay_alert_threshold` | decimal:10,2, nullable | Balance level that triggers notification/top-up. Null = disabled. |
| `prepay_auto_topup_qty` | unsigned integer, nullable | Number of SKU units to auto-purchase. Null/0 = notifications only. |
| `prepay_auto_topup_enabled` | boolean, default false | Master toggle for auto-top-up. |

No new tables. Config lives on the contract alongside the existing `portal_prepay_sku_id`.

## Notification Flow

When balance drops below `prepay_alert_threshold`:

1. Email all **company-wide portal users** for that contract's client (`portal_enabled = true`, `company_wide_access = true`, `is_active = true`).
2. New `NotificationEventType::PrepayLowBalance` allows staff opt-in to also be notified.

Email content: contract name, current balance, threshold, link to portal prepay page (for clients) or contract detail (for staff).

## Auto Top-Up Flow

When balance drops below threshold AND `prepay_auto_topup_enabled = true` AND `prepay_auto_topup_qty > 0`:

1. **Guard:** Check for any unpaid invoice on that contract created by auto-top-up (`PrepayTransactionSource` or activity record). If one exists, skip — prevents repeated invoices while one is outstanding.
2. **Generate invoice** using `PrepayOrderService::createPrepayInvoice()` with the configured quantity and the contract's `portal_prepay_sku_id`.
3. **Push to billing backend** (same async `app()->terminating()` pattern as portal purchases). When triggered from a scheduled command, push synchronously.
4. **Notify company-wide portal users** that an invoice was auto-generated with payment link.
5. **Notify opted-in staff** via new `NotificationEventType::PrepayAutoTopUp`.

### Prerequisites for Auto Top-Up

Auto-top-up requires `portal_prepay_sku_id` to be set on the contract (same SKU used for portal purchases). Without it, only notifications fire.

## Trigger Points

### Real-time

In `PrepayService::debitFromTicketNote()`, after balance update, check if balance crossed below threshold. Call `PrepayAlertService::checkThreshold(contract)`.

Only triggers when balance crosses the threshold (was above, now at or below) — not on every debit while already below.

### Scheduled (safety net)

New `prepay:check-balances` command runs hourly. Iterates all contracts with `prepay_alert_threshold IS NOT NULL` where `prepay_balance <= prepay_alert_threshold`. Triggers notifications/top-up for any below threshold that haven't been notified recently.

Catches edge cases: manual debits, bulk imports, balance corrections.

### Notification Dedup

Track last notification timestamp to avoid spamming. A `prepay_alert_notified_at` column on `contracts` — set when notification fires, cleared when balance goes back above threshold. The scheduled command skips contracts where `prepay_alert_notified_at` is set (already notified for this dip).

## Portal UI

On the contract's prepay page in the portal (existing balance/ledger view), add a settings section visible only to company-wide portal users:

- **Low balance alert threshold** — numeric input (hours). "Notify me when balance drops below X hours."
- **Auto top-up toggle** — checkbox. "Automatically purchase more time when balance is low."
- **Auto top-up quantity** — numeric input (units). Label shows SKU name and hours per unit (e.g., "2 blocks of 5 hours = 10 hours").

Auto-top-up fields only shown when `portal_prepay_sku_id` is set on the contract.

## Staff UI

On the contract detail page (admin side), in the prepay card:

- Same three fields as portal (threshold, toggle, quantity).
- Staff can configure on behalf of clients.
- Shows current auto-top-up status: enabled/disabled, last triggered, any pending unpaid invoice.

## New Service: PrepayAlertService

`app/Services/PrepayAlertService.php` — orchestrates threshold checking:

- `checkThreshold(Contract $contract)` — main entry point. Checks balance vs threshold, determines if notification or auto-top-up needed, handles dedup.
- `sendLowBalanceNotification(Contract $contract)` — emails company-wide portal users + opted-in staff.
- `triggerAutoTopUp(Contract $contract)` — checks guards, creates invoice, pushes to backend, sends notifications.

## New NotificationEventTypes

- `PrepayLowBalance` — "When a client's prepay balance drops below their alert threshold"
- `PrepayAutoTopUp` — "When an auto top-up invoice is generated for a client"

## Design Decisions

- **Config on contract, not client** — a client may have multiple prepay contracts with different thresholds.
- **No auto-charge** — auto-top-up generates an invoice; payment happens through the client's normal billing flow (QBO, Stripe, etc.).
- **Unpaid invoice guard** — prevents invoice spam. Only one outstanding auto-generated invoice per contract at a time.
- **Notification dedup via timestamp** — simple, no extra table. Reset when balance recovers.
- **Company-wide portal users only** — they're the decision-makers who handle purchasing.
- **Hourly cron as safety net** — real-time trigger handles the common case, cron catches edge cases.

## Files Changed

| File | Change |
|------|--------|
| New migration | Add 4 columns to `contracts`: `prepay_alert_threshold`, `prepay_auto_topup_qty`, `prepay_auto_topup_enabled`, `prepay_alert_notified_at` |
| `app/Models/Contract.php` | Add fillable fields, casts |
| `app/Services/PrepayAlertService.php` | New service: threshold check, notifications, auto-top-up |
| `app/Services/PrepayService.php` | Call `PrepayAlertService::checkThreshold()` after debit |
| `app/Enums/NotificationEventType.php` | Add `PrepayLowBalance`, `PrepayAutoTopUp` |
| `app/Services/NotificationService.php` | Add methods for low-balance and auto-top-up notifications |
| `app/Jobs/SendTicketNotification.php` | Handle new notification types |
| `app/Console/Commands/CheckPrepayBalances.php` | New hourly command |
| `routes/console.php` | Schedule `prepay:check-balances` hourly |
| Portal: `PortalPrepayController` | Add settings endpoints (show/update) |
| Portal: prepay Blade views | Add settings section |
| `routes/portal.php` | Add settings routes |
| Staff: contract detail view | Add alert/auto-top-up fields to prepay card |
| `app/Http/Controllers/Web/ContractController.php` | Handle saving alert settings |
