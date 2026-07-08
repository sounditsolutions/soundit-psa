# Client Wiki — Mining Coverage Decisions

**Date:** 2026-06-13
**Status:** Accepted
**Context:** Decisions taken after Phase 3 (ticket-close mining) shipped (PR #15), scoping how mining coverage extends in Phase 5. Both decisions concern *when and over what* the wiki mines tickets. They extend the original design spec (`docs/superpowers/specs/2026-06-12-client-wiki-design.md`, §5.1 triggers, §12 phasing) and should be folded into the Phase 5 plan.

Reference architecture (already shipped, Phase 3): `App\Jobs\MineTicketKnowledge` mines a ticket's *current* state — gather → redact → extract → write-time scan/quarantine → dispute-aware merge → compose. It is idempotent (keyed on `sha256(ticket_id | resolution)`), budget-aware (daily token ceiling with graceful defer), and per-client serialized (`WithoutOverlapping`). The extractor enforces a deliberately aggressive documentation-worthiness filter: most tickets are expected to yield zero facts.

---

## Decision 1 — Historical backfill: a thin command over the existing job

**Decision:** Add `wiki:backfill` (Phase 5) as an artisan command that mines previously-closed historical tickets by dispatching the existing `MineTicketKnowledge` job per ticket. Do **not** build a separate mining path — backfill reuses the shipped pipeline wholesale.

**Design:**
- Select closed tickets that have a non-empty resolution (excluding merge-closures, same guard as the live trigger).
- Process **oldest-first**, so the most recent resolution for any given `subject_key` lands last and wins reaffirmations/disputes (matches the original spec §5.1 trigger 4).
- Throttle under the existing daily token ceiling; when the ceiling is hit, defer and resume the next day (the job already does this).
- Idempotency (content-hash keying) makes overlap with live mining and re-runs safe — no double-processing, no duplicate facts.
- **`--dry-run` is required**: report the candidate ticket count and a rough token/cost estimate *before* committing spend. Backfilling years of history is where the real cost lives; the operator must see the number first.
- Respect the master switch and mining opt-in (`wiki_enabled` + `wiki_auto_mine`), or gate behind an explicit command confirmation.

**Consequences:** Low new surface — redaction, quarantine, and dispute merge are all reused. The only genuinely new logic is candidate selection, ordering, throttled dispatch, and the dry-run estimator. Risk is concentrated in cost/volume, which the dry-run and daily ceiling bound.

**Status vs. prior planning:** Already anticipated — listed as trigger 4 in the spec §5.1 and as a Phase 5 item in the Phase 3 plan's deferral notes. This record confirms the implementation approach (reuse the job; oldest-first; mandatory dry-run).

---

## Decision 2 — Mining trigger: close-triggered stays primary; reject per-note/per-call mining; add a stale-open-ticket sweep for coverage

**Question considered:** Should mining run incrementally on each note/phone call as it arrives, instead of waiting for ticket close?

**Decision:** **No.** Close-triggered mining remains the primary trigger. Coverage gaps (long-lived or never-closing tickets) are addressed by a *supplementary* periodic sweep of stale-but-active open tickets in the Phase 5 maintenance loop — **not** by switching to per-note/per-call mining.

**Rationale (why close-triggered is correct):**
- **Durable documentation lives in settled outcomes, not in-flight chatter.** This is the same premise as the extractor's worthiness filter. A mid-ticket note ("user thinks it's the firewall") is speculation; the resolution ("replaced FortiClient, disabled DTLS, stable") is the fact. Per-note mining inverts the signal-to-noise ratio.
- **Cost.** One AI call per close vs. one per note — a busy ticket has 10–30 notes, most yielding nothing or speculation. Per-note mining would multiply AI spend by ~an order of magnitude and consume the daily budget on churn.
- **Dispute thrash.** Incrementally-mined facts contradict each other as a ticket evolves, generating a storm of disputes/supersessions that all resolve to whatever the close-state says anyway. Close-triggered mining sees the whole arc and extracts the settled truth once.
- **Richer context at close.** At close the AI sees the full conversation + resolution + triage analysis together and can compose a coherent fact no single note contains.

**The real gap and its fix:** Long-lived or abandoned/merged tickets never mine at all. Address this with a periodic sweep (Phase 5 nightly maintenance) that mines open tickets meeting activity/age criteria (e.g. open > N days with significant accrued notes). This reuses `MineTicketKnowledge` against the ticket's current state; idempotency keeps repeated sweeps safe. Phone calls — the one sub-case with a self-contained, outcome-independent disclosure — are already pulled into the close-time context, so they are only "lost" on tickets that never close, which the stale-sweep also catches.

**Consequences:** Trigger model stays simple and cheap. The architecture is not locked in — `MineTicketKnowledge` mines current state, so adding the stale-sweep trigger later is a thin dispatch loop, and idempotency makes it safe to re-mine a ticket as it accrues more resolution-grade content.

**Alternatives rejected:**
- *Per-note/per-call mining* — rejected for cost, noise, and dispute-thrash reasons above.
- *Per-call-only incremental mining* — rejected as unnecessary; call summaries are already in the close-time context, and the stale-sweep covers never-closing tickets.

---

## Phase 5 planning implications

The Phase 5 plan should include: `wiki:backfill` (Decision 1) and a stale-open-ticket maintenance sweep (Decision 2), both reusing `MineTicketKnowledge`. Neither changes the Phase 4 security prerequisite: spec §6 structured-serving must land before any task wires the wiki into triage/Assistant/MCP AI context, since both backfill and the stale-sweep increase the volume of mined facts in the store while the write-time scan remains the sole injection layer until §6 exists.

---

## Decision 3 — Mine on Resolved (and resolution-edit), not only Closed

**Date:** 2026-06-13
**Status:** Accepted (implemented)

**Decision:** The wiki mining trigger fires when a ticket reaches **Resolved OR Closed** with a non-empty resolution, and also when the **resolution is added/edited while already in a terminal status** (the resolve-first-write-the-resolution-later path).

**Rationale:** Confirmed from real MSP usage — the terminal action is almost always **Resolved**; "Closed" is rare (auto-close happens later, or never). Close-only mining left the wiki stale for the common workflow. Idempotency (the run hash is keyed on `ticket_id | resolution`) means the later auto-close with the same resolution does **not** re-mine, while editing the resolution **does** re-mine (captures the correction). Supersedes the Phase-3 close-only trigger.

**Implementation:** `TicketObserver::updated()` — the top-level `wasChanged('status')` early-return was removed (so resolution-only edits are seen); T2T retains its own status-change guard; mining fires on `terminal && (status-changed || resolution-changed) && filled(resolution) && autoMineEnabled()`.

**Related UX gap (backlog, not this change):** the Resolve action does not require/prompt for a resolution, so facts aren't captured until one is entered — a candidate finding for the QA agent.
