# Client-Level Escalation De-Dup Design

Bead: `psa-hziu`

## Problem

`flag_attention` currently de-duplicates only an identical flag on the same ticket:

`ticket_id + action_type + content_hash`

That protects against retry spam on one ticket, but it does not protect the owner from sibling-ticket noise for the same client. If one ticket for a client is already flagged, assigned to a human, or has a recent human staff note, a second Chet run can still create a new flag and immediately send a fresh Teams/email escalation.

For escalation notifications, that is the wrong failure mode. The cockpit should retain the new flag for visibility, but the owner should not get another immediate buzz when the same client is already in front of a human.

## Decision

Add a deterministic client-level noise gate immediately before `EscalationNotifier::notify()` in `FlagAttentionTool`.

The gate runs only when `agent_escalation_enabled=1`. When it suppresses a notification:

- The new `flag_attention` run remains `Flagged`, so it is still visible in the cockpit.
- `EscalationNotifier::notify()` is not called, so Teams/email do not fire.
- `proposed_meta.escalation` records a suppressed status, an owner-noise reason, and the linked sibling run or ticket.
- No `notified_at` is written, so `agent:escalation-sweep` will not re-deliver the suppressed run later.

The existing `TechnicianActionGate` result is also honored: only an `awaiting_approval` flag can page. Kill-switch, blocked, or client-excluded gate outcomes keep the cockpit/audit record but do not notify.

The gate and notify decision run under a client-level cache lock sized to cover the bounded delivery path. A sibling flag waits for that delivery window, re-checks for a durable `notified_at` marker after acquiring the lock, and then suppresses only if delivery really happened. If the lock still cannot be acquired after the delivery window, the run fails open and notifies instead of recording a terminal suppression; duplicate noise is preferable to a zero-owner-ping escalation.

## Suppression Criteria

Suppress the immediate owner ping when the current ticket has a client and either condition is true:

1. Same client has another open `flag_attention` run:
   - `action_type = flag_attention`
   - `state = Flagged`
   - `client_id = current.client_id`
   - its ticket is still open
   - not the current ticket
   - not a previously suppressed run
   - has a valid `proposed_meta.escalation.notified_at` marker, proving the owner was already paged

2. Same client has a human-engaged open sibling ticket:
   - open ticket
   - same `client_id`
   - not the current ticket
   - either `assignee_id` is set, or it has a recent genuine human staff note

The human-note predicate mirrors the context-gathering implementation:

- `who_type = Agent`
- `ai_authored = false`
- `note_type` is not system-generated
- `noted_at >= now() - 7 days`

The same ticket does not suppress itself. A prior different-reason flag on the current ticket may still notify, while exact same-reason duplicates continue to return through the existing idempotency path.

## Non-Goals

- Do not change `RunTechnicianAgent` wake behavior in this increment.
- Do not change the per-ticket unique key.
- Do not add a schema migration.
- Do not alter auto-act thresholds or make any AI action automatic.
- Do not change Teams markdown escaping/readability; that is separate polish.

## Safety Invariants

- Cross-client data never affects suppression.
- The current ticket cannot suppress itself.
- AI-authored/system/stale notes do not count as human engagement.
- Closed/resolved sibling tickets, suppressed flags, and undelivered/legacy held flags do not become suppressors.
- Suppressed runs remain operator-visible and manually resolvable.
- Suppressed runs cannot be picked up by the degradation sweep because they have no `notified_at`.
