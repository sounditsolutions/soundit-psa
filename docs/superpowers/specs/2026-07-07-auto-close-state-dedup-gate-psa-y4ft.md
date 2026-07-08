# Auto-close eligibility: the state/dedup gate (psa-y4ft)

**Status:** built 2026-07-07. Ships **dormant** (auto-close execution does not fire; these are held-safe eligibility guards). Bead: psa-y4ft.

## Why this shape (the pivot)

The original design gated auto-close on a **confidence threshold** (`propose_close_auto_threshold`). Charlie's steer (07-03) — *"a static threshold is the wrong shape for a reasoning agent; ditch it"* — plus the prod calibration data killed that framing:

- Read-only prod run (`agent:eval-close-band`, psa-91f2): **63 propose_close proposals in 14 days, 60 approved, 0 declined**. Confidence barely discriminates and is **not monotonic** (the `0.90–0.95` band was 20/20 clean; the "most confident" `≥0.95` band carried the only correction, on thin N=6).
- The **3 "corrections"** were not judgment misses: all three were **duplicate close proposals** on tickets Charlie **closed himself** (7/6 18:56–57), then flagged as *"duplicate close request. Already closed."* ~1 minute later. Two were the **same ticket** (22482) proposed twice with reworded reasons — so the content-hash idempotency missed the second.

Conclusion: Chet's close **judgment is essentially flawless and operator-confirmed**; the only real gap is **state/dedup**, not calibration. So the gate is a **confidence-agnostic state rule** — Charlie's "reasoned eligibility", now data-driven.

## The three guards

1. **Already-closed rejection** (`ProposeCloseTool::executeInternal`): a `propose_close` on a ticket whose status is already `Closed` returns a clear failure (`"Ticket #N is already closed — nothing to do."`) and creates **no** run — so Chet learns in-band and moves on instead of leaving a redundant held proposal for a human to dismiss.
2. **Ticket-level dedup** (`ProposeCloseTool::executeInternal`): if a `propose_close` run is already **pending** (`awaiting_approval`) for the ticket, a second proposal is refused (`"A close is already proposed…"`). This tightens the prior **content-hash** idempotency to the **ticket** — closing the reworded-reason gap that let 22482 be proposed twice. Only a *pending* run blocks; a terminal outcome (denied/superseded/done) leaves Chet free to re-propose later.
3. **Auto-withdraw on close** (`TicketObserver::updated` → `TechnicianRun::withdrawHeldClosesForClosedTicket`): when a ticket transitions to `Closed` **by anyone**, its held (`awaiting_approval`) close proposals are bulk-transitioned to a new terminal state **`Withdrawn`**. Scoped to `awaiting_approval` only, so an in-flight **approval** (which claims its run to `Executing` *before* closing) is never clobbered.

## Notes

- **`TechnicianRunState::Withdrawn`** is deliberately distinct from `Superseded` (an operator correction) so calibration never miscounts an auto-withdrawal as a human veto. `CloseBandEvaluator` buckets it as **other** (excluded from the approve rate), not corrected.
- **Held-safe / dormant**: none of the three guards close a ticket. They only prevent or clean up redundant *held* proposals. Auto-close execution remains off (Charlie's flip, a separate step).

## Files

- `app/Services/Agent/ProposeCloseTool.php` — parts 1 & 2
- `app/Observers/TicketObserver.php` + `app/Models/TechnicianRun.php` + `app/Enums/TechnicianRunState.php` — part 3
- `app/Services/Agent/CloseBandEvaluator.php` — `Withdrawn` → other
- `tests/Feature/Agent/CloseStateDedupGuardTest.php` (+ `CloseBandEvaluatorTest.php`)
