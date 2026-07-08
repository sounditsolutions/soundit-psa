# Forward Attribution on Token-Threaded Emails

**Date:** 2026-05-28
**Status:** Approved design, pending implementation plan

## Problem

A customer sometimes emails a technician directly (at their personal work
address) instead of the helpdesk shared mailbox. The technician wants that
message to land on the *existing* ticket it relates to. The only mailbox the
PSA ingests is the helpdesk mailbox, so the email has to be forwarded there.
Today that path feels messy:

- Forwarding to helpdesk creates a brand-new ticket.
- Cleaning up means merging the new ticket into the right one, which fires
  triage on the throwaway ticket and leaves clutter.

The same friction applies when a customer emails the helpdesk directly about an
existing ticket.

## What already exists (and works)

`EmailService::matchToExistingTicket()` (app/Services/EmailService.php:500-552)
already routes an inbound email to an existing ticket via, in order:

1. Graph `conversation_id` (same thread)
2. RFC 5322 `In-Reply-To` header chain
3. Subject token `[T-123]` → `Ticket::find(123)`
4. Subject token `[ID:123]` → `halo_id`
5. Subject token `[#123]` → `halo_id`

This runs inside `processInbound()` (app/Services/EmailService.php:206-265)
**before** the auto-create branch, and every message pulled from the helpdesk
mailbox is mapped as `direction = inbound`
(`mapGraphMessage()`, app/Services/EmailService.php:1420) — including a forward
sent *from* a staff member. So:

- **Forward a direct email to helpdesk with `[T-123]` in the subject** → it
  threads onto T-123 as a reply note, no new ticket, triage never fires.
- A customer reply to a ticket notification already carries `[T-123]` (outbound
  notifications use `[{display_id}]`), so it threads with no editing.

The docblock at app/Services/EmailService.php:491-498 wrongly states subject
matching is "excluded — too noisy for MVP." That comment is stale; the code
does subject matching. This spec corrects it.

## Remaining gap (the actual work)

When a technician forwards a customer email, the resulting ticket note is
mis-attributed:

- `linkEmailToTicket()` (app/Services/EmailService.php:595-606) sets
  `author_name` to the forward's `from_address` — i.e. the technician — and
  `who_type = EndUser`. The timeline shows the *technician* as a green
  "end-user reply," with the customer's real identity buried inside the
  forwarded `From:/Sent:/To:/Subject:` wrapper in the body.

This spec fixes attribution so the note is credited to the original customer,
with a short provenance line recording who forwarded it.

## Design

### Trigger (narrow, to avoid touching normal replies)

Reattribution happens inside `linkEmailToTicket()` — the single chokepoint all
threading and manual-link paths flow through — and fires **only** when the
linked email is genuinely a forward:

1. Subject carries a forward prefix (`FW:`, `FWD:`, `Fwd:`, `Forwarded:`), **and**
2. the body contains a parseable forward header block, **and**
3. the parsed original sender's address differs from the email's `from_address`.

A staff member replying to a ticket thread from their own mailbox (token in the
subject, but not a forward) is therefore left attributed to them. This prevents
misfiring on quoted reply history.

### New unit: `ForwardedEmailParser`

- **Location:** `app/Services/Email/ForwardedEmailParser.php`,
  namespace `App\Services\Email`. Mirrors the static-helper pattern of
  `App\Services\Mesh\MeshEmailParser` so it is isolated and unit-testable.
- **API:**
  - `isForwarded(Email $email): bool` — true when the subject prefix and a
    forward header block are both present.
  - `parseOriginalSender(Email $email): ?array` — returns
    `['name' => ?string, 'email' => string]` parsed from the topmost `From:`
    line of the forward block; `null` if it cannot parse a sender.
- **Formats handled:** Outlook (`From: ... \nSent: ... \nTo: ... \nSubject: ...`)
  and Gmail (`---------- Forwarded message ---------\nFrom: ... <addr>\nDate:
  ...`). Parses the **topmost** `From:` only (nested forwards resolve to the most
  recent forwarder's quoted original — the first block encountered).
- **Robustness:** best-effort. Anything it cannot parse yields `null` and the
  caller falls back to current behavior. Operates on `body_text`
  (or `extractPlainText(body_html)` when text is absent).

### Changed unit: `EmailService::linkEmailToTicket()`

At note creation (app/Services/EmailService.php:595-606), before building the
`TicketNote`:

- If `ForwardedEmailParser::isForwarded($email)` and
  `parseOriginalSender()` returns a sender whose email differs from
  `$email->from_address`:
  - `author_name` ← original sender name, falling back to the original sender
    email when no name is parsed.
  - Prepend a provenance line to `body`:
    `[Forwarded into {ticket->display_id} by {forwarder}]` + blank line +
    original body. `{forwarder}` is `$email->from_name ?? $email->from_address`.
  - When `body_html` is set, prepend an equivalent HTML provenance line so the
    rich view matches.
- `who_type` stays `EndUser` (the content is the customer's words).
- If not a forward, or the sender cannot be parsed, behavior is unchanged
  (attributed to the forwarder). Strict improvement, safe default.

### No schema change, no new setting

- Audit of *who forwarded* is preserved automatically: the note's `email_id`
  still points at the forwarded `Email`, whose `from_address` is the forwarder.
- Always-on. There is nothing to gate behind a setting.

### Out of scope

- Stripping the forward wrapper from the body (decided: keep full body).
- Plus-addressing / dedicated per-ticket reply address.
- Any UI change (the note simply renders with corrected attribution).
- Triage changes — none needed; no ticket is created on the threading path.

## Data flow

```
Inbound email (forward from tech, subject "FW: ... [T-123]")
  -> mapGraphMessage(): direction = inbound
  -> processInbound()
       -> isAutoReply()? no
       -> matchToExistingTicket(): subject [T-123] -> Ticket T-123
       -> linkEmailToTicket(email, T-123)
            -> ForwardedEmailParser::isForwarded()? yes
            -> parseOriginalSender() -> Jane Doe <jane@acme.com>
            -> author_name = "Jane Doe", who_type = EndUser
            -> body = "[Forwarded into T-123 by Charlie Coutts]\n\n" + original
            -> TicketNote created, email_id links back to the forward
            -> existing auto-reopen + notifyEmailAdded behavior unchanged
       -> returns (no auto-create, no triage)
```

## Error handling

- Parser failure or ambiguous format → `parseOriginalSender()` returns `null` →
  note attributed to the forwarder, as today. No exceptions propagate.
- Original sender equals forwarder (tech forwarded their own message) → no
  reattribution.
- Missing `body_text` → parser falls back to `extractPlainText(body_html)`.

## Testing

**Parser unit tests (`ForwardedEmailParser`):**
- Outlook forward block parses name + email.
- Gmail forward block parses name + email.
- Non-forward message → `isForwarded()` false.
- Forward prefix present but no parseable `From:` → `parseOriginalSender()` null.
- Email-only `From:` (no display name) → name null, email set.

**Feature tests (`EmailService` threading):**
- Staff-forwarded email with `[T-123]` in subject:
  - note attributed to the original customer (not the forwarder),
  - provenance line present in body,
  - `who_type = EndUser`,
  - **no new ticket created**.
- Normal staff reply (token, no forward block) → **not** reattributed.
- Forward with unparseable sender → attributed to forwarder, no error,
  still linked to the ticket.

## Touch points

| File | Change |
| --- | --- |
| `app/Services/Email/ForwardedEmailParser.php` | New static parser. |
| `app/Services/EmailService.php` (`linkEmailToTicket`, ~595-606) | Reattribute + provenance when forwarded. |
| `app/Services/EmailService.php` (docblock 491-498) | Correct stale "subject matching excluded" note. |
| `tests/Unit/...ForwardedEmailParserTest.php` | Parser unit tests. |
| `tests/Feature/...` (email threading) | Forward attribution + guard tests. |
