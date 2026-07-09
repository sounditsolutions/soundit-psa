# Teams Ask-Then-Act (Half-1) — Phased Build Plan

**Date:** 2026-07-09
**Bead:** psa-402b (revived-narrowed to Half-1 by Mayor/dev-lead disposition, 2026-07-09 17:29)
**Design (authoritative):** the `design` field of bead **psa-402b** (`bd show psa-402b`). This plan mirrors it; read the design first.
**Depends on:** psa-zajh's approved `HeldActionResolved` seam (the convergence callback).
**Out of scope:** Half-2 gated-write action surface → **psa-6m30** (Intune-wipe etc. behind Mayor plan-gate psa-6m30.5).
**Vault hygiene (manager):** the guidance-loop design doc `specs/2026-06-24-...` is vault-only/absent; this plan is derived from the code substrate and carries no raw vault content.
**Status:** Draft — build convergence-gated (post-cutover); do NOT start until owner-approved.

## Context (verified in-tree 2026-07-09)
Charlie's "ping me on Teams, ASK, then act on approval" — the ask-then-decide round-trip. Substrate exists: per-person Entra identity (`TeamsIdentityResolver`, fail-closed), send-auth allowlist (`TeamsBotConfig::operatorAllowlistUserIds:187` → `authorized_steer`), inbound pipe (`TeamsMessagesController` → `OperatorInbox`, drained by Chet's `poll_operator_messages`), outbound transport (`EscalationNotifier`/`OperatorDelivery`), and the cockpit approval funnel (`TechnicianApprovalService::approve*/deny`). The gap: a Teams reply is free text with NO `technician_run` linkage — nothing lets an authorized reply approve/deny a specific held run.

## Phases
**Phase 1 — New MCP approve/deny(run_id) tool (the core).**
- Add `approve_held_action`/`deny_held_action` to `OperatorBridgeToolExecutor::execute()` + `McpToolRegistry` (sensitive group). Funnels into `TechnicianApprovalService::approve*/deny` by `run->action_type`.
- **Send-auth crux:** `approverId` is server-derived from the correlated `OperatorInbox` row's `sender_user_id`, gated by `authorized_steer` — NEVER the shared MCP token, never caller-supplied. Fail-closed.
- Tests: authorized yes/no → matching approve/deny + real approverId; non-allowlisted/unresolved/shared-account → rejected; parity with cockpit approve; convergence on `HeldActionResolved`.

**Phase 2 — Ask ↔ reply correlation.**
- Record an outbound run-scoped ask linkage (run_id ↔ conversation_id): `TechnicianRun.proposed_meta` stamp vs a small `agent_pending_asks` table (design §6.1). Reply resolves only the run it was asked about; stale/mismatched run_id rejected.

**Phase 3 — Outbound "ask" helper.**
- Extend `EscalationNotifier`/`OperatorDelivery` (or a new `AskOnTeams` steering method beside `HeldActionResolved`) to post a run-scoped yes/no and record the linkage. Gate to an explicit "ask for a steer" trigger (not every hold — avoid Teams spam; §6.3).

**Phase 4 — Docs.**
- Note the new tool + round-trip; document any correlation-table migration. No `.env` secrets.

## Rails
- Held-only: the tool RELAYS a human's explicit decision; no agent self-approval, no auto-act threshold.
- Per-person identity preserved: audit + approval attribution name the real human, never "the bot".
- Reuse `TechnicianActionGate` grant/kill-switch/audit on every side-effectful path; no gate bypass.
- Convergence: reuse psa-zajh's `HeldActionResolved`; no divergent re-look mechanism.

## Charlie open-questions
See design §6 (correlation mechanism; which held actions are Teams-approvable — recommend in-PSA set first, tactical/CIPP later with sign-off; ask trigger; edited-reply approvals; allowlist vs per-action authorization).
