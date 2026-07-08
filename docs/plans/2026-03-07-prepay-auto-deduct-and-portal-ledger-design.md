# Prepay Auto-Deduction & Portal Ledger — Design

## Goal

1. Automatically deduct prepaid time when billable time is logged on ticket notes
2. Show a prepaid time activity ledger on the portal Service Agreement page

## Feature 1: Auto-Deduct Prepay Time

### Billability default

When a technician logs time on a ticket note, `is_billable` is set automatically based on the ticket's triage classification:

- **Triage says work is covered by managed services** → `is_billable = false`
- **Break/fix, no contract, no classification, or no triage run** → `is_billable = true`
- Technician sees a pre-checked/unchecked "Billable" checkbox and can override

### Deduction flow

When a note is saved with `is_billable = true` and `time_minutes > 0`:

1. Check if the ticket's contract has prepay (`contract.prepay_balance IS NOT NULL`)
2. Create a `PrepayTransaction` debit:
   - `source`: new enum value `TicketTime`
   - `hours`: `-time_minutes / 60`
   - `description`: "Ticket #123: [subject snippet]"
   - `ticket_note_id`: FK back to the note (new column on `prepay_transactions`)
3. Update contract `prepay_used` and `prepay_balance`

### Edits and deletes

- Note time changed → update existing debit transaction amount
- Note deleted or time zeroed → delete the debit transaction and adjust balance
- `is_billable` toggled off → delete debit; toggled on → create debit

### Where logic lives

- `TicketNoteObserver` (new) — triggers on created/updated/deleted
- `PrepayService::debitFromTicketNote()` / `reverseDebitForTicketNote()` — new methods

## Feature 2: Portal Prepay Ledger

### Visibility

- **Company-wide access users only** (`$portalPerson->company_wide_access = true`)
- Non-company-wide users continue to see only the balance number, no ledger

### Display

On the portal Service Agreement detail page, a "Prepaid Time Activity" card below the balance:

| Date | Description | Hours | Balance |
|------|-------------|-------|---------|
| Mar 7 | Invoice #1042 — Prepaid Time | +5.00 | 12.50 |
| Mar 5 | Ticket #456: Printer not working | -0.50 | 7.50 |
| Mar 3 | Ticket #440: Email setup | -1.25 | 8.00 |

- Sorted newest first, paginated (20 per page)
- Credits green, debits red
- Running balance column computed from cumulative sum
- Ticket subjects visible only to company-wide users (inherent from visibility gate)

## Files Changed

| File | Change |
|------|--------|
| `app/Enums/PrepayTransactionSource.php` | Add `TicketTime` case |
| `database/migrations/...` | Add `ticket_note_id` nullable FK to `prepay_transactions` |
| `app/Models/PrepayTransaction.php` | Add `ticketNote()` relationship |
| `app/Services/PrepayService.php` | Add `debitFromTicketNote()`, `reverseDebitForTicketNote()` |
| `app/Observers/TicketNoteObserver.php` | New — triggers prepay debit on time entry changes |
| `app/Providers/AppServiceProvider.php` | Register `TicketNoteObserver` |
| `app/Services/TicketService.php` | Set `is_billable` default in `addNote()` based on triage classification |
| `resources/views/tickets/show.blade.php` | Add billable checkbox to time entry form |
| `app/Http/Controllers/Portal/PortalContractController.php` | Eager-load prepay transactions for company-wide users |
| `resources/views/portal/contracts/show.blade.php` | Add prepay ledger table (company-wide only) |
