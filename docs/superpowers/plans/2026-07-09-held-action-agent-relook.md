# Human-Acted Held Action → Agent Re-Look — Phased Build Plan

**Date:** 2026-07-09
**Bead:** psa-zajh
**Design (authoritative):** the `design` field of bead **psa-zajh** (`bd show psa-zajh`). This plan mirrors it; read the design first.
**Related:** psa-402b (Teams ask-then-act + gated writes) — the SAME unified callback serves both channels; do not build divergent re-look mechanisms.
**Status:** Draft — build is convergence-gated (do NOT start until the owner approves and the freeze lifts).

## Context (verified against the tree, 2026-07-09)
When a human resolves a held agent-action (`TechnicianRun`) in the cockpit, the agent is not told and never re-looks. Only `correct()` re-looks today, via `ReassessTrigger::reassess()` → `RunTechnicianAgent(correctionDriven:true)` (`TechnicianCockpitController.php:299`).

**Key correction to the requirement:** the HUMAN notification system (`NotificationService`/`NotificationEventType`) is email/staff-only with NO agent addressee — it is NOT the hook. The correct existing agent-notification substrate is **`RunTechnicianAgent`** (in-process re-look) + **`SignalHub`→`McpSink`→`poll_signals`** (external Chet). `SignalEventTypes` already declares an unused `agent.proposal_held` (`:60`) — a ready symmetric slot.

## Phases
**Phase 1 — The unified seam (PSA-native half).**
- New `app/Enums/HumanVerdict.php` (approved/denied/cancelled/reconfirmed/corrected/acknowledged/dismissed + re-look policy helpers).
- New `app/Services/Agent/Steering/HeldActionResolved::record(TechnicianRun, HumanVerdict, ?int operatorUserId)` — (a) wakes `RunTechnicianAgent` for the warranted verdict set, (b) emits a Signal for Chet.
- New `SignalEventTypes` case `agent.held_action_resolved` (mirror `agent.proposal_held:60`).
- Wire one `HeldActionResolved::record()` call into each cockpit verdict method (`deny:125`, `cancel:143`, `reconfirm:160`, `acknowledge:177`, `dismiss:191`, `intakeDismiss:209`); route `correct:285` through it.

**Phase 2 — Re-look safety policy (the core design point).**
- Verdict→re-look table (design §4.3). Critically: `deny` must NOT trigger an immediate identical re-proposal (keep the change-throttle for non-corrective verdicts, and/or carry the denied `action_type`+`content_hash` into run context). Prevents the agent-acts→human-denies→agent-re-proposes loop.
- Per-run "already re-looked for this verdict" idempotency guard (`proposed_meta` stamp).

**Phase 3 — Teams channel convergence (coordinate with psa-402b).**
- New MCP `approve_held_action`/`deny_held_action(run_id)` tool → funnels into the SAME `TechnicianApprovalService` methods the cockpit uses → hits `HeldActionResolved` identically. (Tool + Entra send-auth belong to psa-402b; psa-zajh delivers the seam it targets.)

**Phase 4 — Signal resolution + docs.**
- Optionally extend `SignalResolutions` so the outstanding signal auto-resolves when the run leaves its held state (mirrors `agent.flag_attention`). Note the new Signal type in docs. No `.env`/schema changes.

## Tests (TDD, empirical)
Each verdict fires the seam; `deny` causes no immediate identical re-proposal (anti-loop); `correct` unchanged (regression); Signal carries `run_id`/`verdict`/`client_id`; with `agent_enabled=0` the in-process re-look no-ops but the Signal still emits; replayed verdict does not stack dispatches.

## Rails
Held-only: the re-look runs the already-dormancy-gated, held-first `RunTechnicianAgent` — it PROPOSES; humans still approve. NO new auto-act threshold. Reuse existing transport (Signals + RunTechnicianAgent); do not touch the human `NotificationService`.

## Open questions
See design §7 (anti-loop policy for deny, verdict set, Teams↔run correlation, dormancy split for MCP-origin actions, recursion ceiling).
