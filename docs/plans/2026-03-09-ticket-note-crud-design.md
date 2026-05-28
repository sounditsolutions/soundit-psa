# Ticket Note Edit & Delete Design

**Goal:** Allow staff to edit and soft-delete ticket notes, with prepay debit auto-correction and edit tracking.

## Data Model

Three new columns on `ticket_notes`:

| Column | Type | Description |
|--------|------|-------------|
| `deleted_at` | timestamp, nullable | SoftDeletes (Eloquent trait) |
| `edited_at` | timestamp, nullable | Last edit timestamp |
| `edited_by` | FK to users, nullable | Who last edited |

Add `SoftDeletes` trait to `TicketNote` model.

## Edit

- **Editable fields:** body, time_minutes, is_billable, note_type, is_private
- **Who:** Any authenticated staff member (matches existing trust model)
- **What:** Non-system notes only (Note, Reply, PhoneCall, Resolution). System-generated types (StatusChange, System, AiTriage, Escalation) are not editable.
- On save: re-render `body_html` via `MarkdownRenderer`, set `edited_at` and `edited_by`
- `TicketNoteObserver::updated()` already re-syncs prepay debits when time/billable changes
- Note type selector restricted to non-system types
- Timeline shows "(edited)" indicator next to timestamp when `edited_at` is set

## Delete (Soft)

- Soft-delete sets `deleted_at` timestamp
- On delete: set `is_private = true` so portal users never see deleted notes
- `TicketNoteObserver::deleted()` already calls `reverseDebitForTicketNote()` — SoftDeletes fires the `deleted` event, so prepay reversal works automatically
- In the timeline, deleted notes render as a compact grey row: "Note deleted by [name] on [date]" with a clickable toggle to reveal original content
- System-generated notes cannot be deleted

## UI

- Edit (pencil) and delete (trash) icon buttons in the note header row, right-aligned next to timestamp
- Only shown for non-system notes, staff view only (not portal)
- Delete: confirmation dialog before action
- Edit: Bootstrap modal with form fields (body textarea, time input, billable checkbox, note type select, private checkbox)

## Prepay Integration

- **Edit with time change:** Observer fires `debitFromTicketNote()` which updates existing `PrepayTransaction` and adjusts contract balance
- **Edit billable→non-billable:** Observer fires `debitFromTicketNote()` which calls `reverseDebitForTicketNote()` when `is_billable = false`
- **Delete:** Observer fires `reverseDebitForTicketNote()` which removes the `PrepayTransaction` and credits back the hours
- No manual prepay handling needed — the existing observer covers all cases

## Files Changed

| File | Change |
|------|--------|
| New migration | Add `deleted_at`, `edited_at`, `edited_by` to `ticket_notes` |
| `app/Models/TicketNote.php` | Add SoftDeletes, fillable fields, casts, `editor()` relation |
| `app/Http/Controllers/Web/TicketNoteController.php` | Add `update()` and `destroy()` methods |
| `routes/web.php` | Add PUT and DELETE routes for notes |
| `resources/views/tickets/show.blade.php` | Edit/delete buttons, edit modal, deleted note rendering, "(edited)" indicator |
