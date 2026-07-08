# Client-Level Escalation De-Dup Plan

Bead: `psa-hziu`

## Scope

Build the deterministic brake for immediate owner pings on duplicate same-client escalations. Preserve current held-only behavior: Chet may record a cockpit flag, but repeated client-level noise should not fire a new Teams/email escalation.

## Code Changes

1. Add `App\Services\Agent\Escalation\ClientEscalationNoiseGate`.
   - Inputs: current `Ticket` and just-created `TechnicianRun`.
   - Output: suppression metadata array or `null`.
   - Checks open same-client flagged sibling runs with a durable delivery marker before human-engaged siblings.
   - Excludes current-ticket flags, closed-ticket flags, and already-suppressed flags.
   - Excludes undelivered/legacy held flags with no valid `notified_at`, so an old cockpit-only flag cannot create a zero-owner-ping path.
   - Reuses the same human-note predicate as `ClientSituationContextBuilder`.

2. Wire `FlagAttentionTool`.
   - Inject the noise gate.
   - Capture the `TechnicianActionGate` result.
   - After the run is recorded and gate-audited, if escalation notifications are enabled and the gate result is `awaiting_approval`, consult the noise gate.
   - Run the noise gate and notify decision inside a per-client cache lock sized to cover bounded delivery.
   - On suppression, merge `proposed_meta.escalation` with `status=suppressed`, `noise_to_owner=duplicate_client_escalation`, and no `notified_at`.
   - Otherwise call `EscalationNotifier::notify()` exactly as today.
   - If the lock cannot be acquired after the delivery window, fail open to notify rather than recording a sweep-skipped suppression.
   - If the gate result is `held` or `blocked`, do not notify.

3. Add cockpit visibility.
   - In the existing flag card, show a small "Not re-pinged" marker and reason when the run metadata says the escalation was suppressed.

4. Tests.
   - Existing enabled/new-flag notify test must still pass; this proves the current run does not self-suppress.
   - Same-client open flag suppresses notify but records a new `Flagged` cockpit run.
   - Same-client open flag without delivery metadata does not suppress notify.
   - Assigned same-client open sibling suppresses notify.
   - Recent non-AI staff note on same-client open sibling suppresses notify.
   - AI/system/stale notes and cross-client activity do not suppress.
   - Same-ticket different-reason flags do not suppress.
   - Closed sibling flags do not suppress.
   - Kill-switch/client-excluded gate results do not notify.
   - Client lock timeout notifies instead of terminally suppressing.
   - Suppressed metadata has no `notified_at`.

## Verification

Run the focused suite first:

```bash
php artisan test tests/Feature/Agent/Escalation/FlagAttentionEscalationTest.php
```

Then run the related agent/escalation suites:

```bash
php artisan test tests/Feature/Agent/FlagAttentionToolTest.php tests/Feature/Agent/Escalation
```

Before PR:

```bash
vendor/bin/pint
php artisan test tests/Feature/Agent/FlagAttentionToolTest.php tests/Feature/Agent/Escalation tests/Feature/Agent/ClientSituationContextTest.php
```

Final gate remains the project rule: relevant tests green, Pint clean, `/soundpsa-review-pr <branch>` clean, held PR for Mayor review.
