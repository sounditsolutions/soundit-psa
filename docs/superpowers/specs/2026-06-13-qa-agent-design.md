# QA Agent + Harness — Design

**Date:** 2026-06-13
**Status:** Approved design, pending implementation planning
**Scope:** New capability — an autonomous QA agent that drives the SoundPSA dev server through real MSP workflows, filing bug/UX/doc-gap findings. Plus a companion behavior change (mine wiki facts on Resolved, not only Closed).

## 1. Purpose

Take the human out of the QA loop. An autonomous agent simulates a real MSP technician against the running dev server — clicking through actual workflows in a real browser — to surface what breaks, what confuses, and what's undocumented, *before* the human hits it. It runs on demand when a feature ships or an issue is suspected, and works from a versioned library of simulation scenarios. Its findings flow into the same work pipeline as everything else.

The motivating example: enabling the wiki, "closing" a ticket (actually Resolving it), and seeing nothing happen — a workflow gap (Resolved ≠ Closed), an environment gap (no queue worker), and a UX gap (Resolve doesn't prompt for a resolution) that a QA agent driving the real flow would have caught.

### Goals
- Drive the real dev server in a real browser (not just HTTP) so visual/interaction UX issues are in scope.
- Run a versioned, growable scenario library; start with the ticket lifecycle + wiki.
- File findings as tracked work (beads) automatically, plus a human-readable run report.
- Be dispatchable on feature-release / issue-discovery; safe to run unattended against dev.

### Non-goals (v1)
- No production testing — dev/local host only, hard-guarded.
- No load/performance testing, no security pentesting (functional + UX only).
- No self-healing (the agent files findings; it does not fix code).
- Not a replacement for the PHPUnit suite — this is end-to-end/exploratory testing on top of it.

## 2. Decisions made during design

| Question | Decision |
|----------|----------|
| Interaction layer | Real browser automation (Playwright + headless Chromium), so visual/interaction UX is testable. |
| Agent form | A Gas Town agent role (`soundit-psa/gastown.qa`), modeled on the persona reviewers; dispatched on demand. |
| Findings output | Beads in the soundit-psa rig, labeled `qa` + `bug`/`ux`/`docs`, with repro + screenshot; plus a per-run markdown report mailed to the operator. The agent files them autonomously (no human pre-triage). |
| First scope | The hot path: ticket lifecycle + wiki. |
| Dev environment | Dev is disposable staged data — clear the stale job backlog freely and run a managed queue worker so async flows (triage, mining) actually process. |

## 3. Architecture

```
  dispatch (QA-run bead: scope)            ┌─────────────────────────────┐
        │                                  │   gastown.qa  (town agent)  │
        ▼                                  │                             │
  ┌───────────┐   reads    ┌────────────┐  │  • mission system prompt    │
  │ scenario  │──────────▶ │            │  │  • Playwright browser tools │
  │ library   │            │  QA agent  │──┤  • gc bd (file findings)    │
  │ docs/qa/  │            │            │  │  • read-only repo access    │
  └───────────┘            └─────┬──────┘  └─────────────────────────────┘
                                 │ drives (headless Chromium)
                                 ▼
                    https://soundit-dev  (dev-login bypass /dev/login/{id})
                                 │
                                 ▼ findings
              ┌──────────────────────────────────────┐
              │ beads (psa-, labels qa/bug/ux/docs)   │
              │ + per-run report (markdown, mailed)   │
              └──────────────────────────────────────┘
```

### 3.1 Browser harness
Playwright with headless Chromium, installed on the dev box. Exposed to the QA agent as **MCP browser tools** (navigate, accessibility-tree snapshot, click, fill, screenshot) so the agent interacts with — and "sees" — pages like a user, and can deviate from a script to chase anything that looks off. **Fallback** if wiring an MCP server into a town agent proves impractical: a Playwright **script-runner** the agent invokes via shell, returning a structured result + screenshot paths the agent reads back. The implementation targets MCP and falls back cleanly; either way the agent's interface is "drive a browser, observe, judge."

**Safety:** the harness refuses any base URL that is not the configured dev/local host (a hard check, not convention). The `/dev/login/{id}` bypass it depends on only exists when `APP_ENV=local`, so the same configuration cannot point it at production.

### 3.2 The QA agent (`soundit-psa/gastown.qa`)
A new Gas Town agent template, modeled on the persona reviewers. Its system prompt frames it as a skeptical MSP technician whose job is to find what breaks, confuses, or lacks documentation — and to file precise, reproducible findings. It has: the browser tools (§3.1), `gc bd` for filing findings, and read-only access to the repo so a finding can cite the actual route/view/code that produced it. It logs in via the dev bypass as a seeded staff user and operates only on dev data.

### 3.3 Scenario library
Versioned markdown under `docs/qa/scenarios/`, one file per workflow. Each scenario states: a **goal**, **setup/preconditions** (created by the scenario where possible, tolerant of existing state), **steps**, **functional expectations**, and **UX watch-fors** (confusing labels, missing feedback, dead ends, unintuitive flows). The library is the script; the agent may go off-script to investigate. First cut covers the ticket lifecycle and wiki (§5).

### 3.4 Findings pipeline
Each finding → a bead in the soundit-psa rig, labeled `qa` plus exactly one of `bug` / `ux` / `docs`, carrying: scenario name, steps to reproduce, expected vs. actual, severity, and a screenshot artifact. The agent dedupes against open `qa` beads (same scenario + same assertion) so repeated runs don't pile up duplicates. Each run also produces a **markdown report** (pass/fail per scenario + the findings filed) saved under `storage/qa-reports/` (non-web-accessible) and mailed to the operator.

### 3.5 Dispatch & triggers
Dispatched on demand: a "QA run" bead names a scope (a scenario set, or "smoke the wiki," or "regress tickets after PR #N"). You or the mayor file it; the QA agent claims and runs it. Scheduled/periodic runs are a possible later addition; v1 is on-demand.

## 4. Companion change — mine on Resolved, not only Closed

The wiki mining trigger currently fires only on `status === Closed`. In real MSP usage the terminal action is almost always **Resolved** (auto-close happens later, or never), so close-only mining leaves the wiki stale. Change:

- `TicketObserver::updated()` dispatches `MineTicketKnowledge` when **either** (a) status changed to **Resolved or Closed**, **or** (b) the **resolution changed while status is Resolved or Closed** — covering the common "resolve first, write the resolution afterward" path. Gated, as now, by `WikiConfig::autoMineEnabled()` and a non-empty resolution.
- The job's idempotency (content hash on `ticket_id | resolution`) means a later auto-close with the same resolution **does not re-mine**, and editing the resolution **does** re-mine (desired — captures the correction).
- The job's existing guards are unchanged (empty resolution → no-op; merge-closure → skip).

This is a small, well-understood trigger change; it ships independently of the QA agent and gets a decision-record update. (The QA agent's wiki scenarios then exercise the Resolved path as a regression guard.)

A related UX observation for the backlog (not part of this change): the Resolve action does not require/prompt for a resolution, so facts aren't captured until one is entered — exactly the kind of gap the QA agent is meant to file.

## 5. Scenario library — first cut (tickets + wiki)

Functional + UX expectations encoded in each:
- **Ticket lifecycle:** create from each source (manual, helpdesk-button, alert); triage; add notes; transition through statuses; **resolve with a resolution**; verify resolution capture; close; reopen; merge.
- **Wiki enablement & gate:** toggle `wiki_enabled` on/off in Settings; confirm `/wiki` and the client Wiki tab appear/404 accordingly.
- **Mining loop:** with auto-mine on and a queue worker running, resolve a ticket with a substantive resolution → confirm a `wiki_run` completes → open the client's wiki → verify the expected fact appears with provenance.
- **Fact verification UX:** confirm / correct / retire a fact; resolve a disputed pair; check the provenance panel renders cleanly (progressive disclosure, badges, addendum block).
- **Wiki browse/search:** global and per-client index, search, deviation runbook merge view.

## 6. Dev environment setup

Part of standing up QA (dev is disposable staged data):
- Clear the stale job backlog (`queue:clear`) — it's dev cruft.
- Run a **managed queue worker** (`queue:work` under the process manager, or a documented always-on dev process) so triage and mining jobs actually process. Without this, every async end-to-end flow stalls (the exact failure already observed).
- Document both in the dev-setup docs so the testing bed stays functional.

## 7. Error handling
- **Harness failures** (browser crash, navigation timeout): the agent records the scenario as errored (distinct from a product failure), screenshots the state, and continues to the next scenario.
- **Non-dev base URL:** hard refusal before any browser action.
- **Finding-filing failures** (beads unreachable): captured in the run report so findings are never silently lost.
- **Dev data drift:** scenarios tolerate pre-existing state and create their own fixtures; a scenario that can't establish preconditions reports a setup-error finding rather than a false product failure.

## 8. Testing strategy
- **Unit (no browser):** scenario-file parsing; the dev/local-host guard (rejects non-dev URLs); finding→bead mapping incl. label assignment and dedup keying; report generation shape.
- **Companion change:** the mine-on-Resolved trigger gets standard TicketObserver feature tests (Bus::fake) — dispatch on status→Resolved with resolution; dispatch on resolution-edit while Resolved; no dispatch when resolution empty; idempotency skip on later auto-close.
- **First supervised run:** the QA agent's judgment is validated by a first run reviewed together before it's trusted to file autonomously.

## 9. Phasing
1. Companion mine-on-Resolved trigger change + decision record (independent, ships first).
2. Dev environment: clear backlog, managed queue worker, dev-setup docs.
3. Harness: Playwright + headless Chromium, the browser-tool interface, the local-host guard.
4. The `gastown.qa` agent template + findings pipeline (bead filing, dedup, run report).
5. Scenario library first cut (tickets + wiki) + first supervised run.

## 10. Risks
- **Browser tooling weight/flakiness:** headless Chromium can be flaky/slow; the harness isolates harness-errors from product-failures so flake doesn't generate false bugs.
- **Finding noise:** dedup against open `qa` beads + severity labeling keeps the queue signal-rich; the first supervised run calibrates the agent's bar before autonomous filing.
- **Dev-data coupling:** scenarios that depend on specific seeded data are brittle; prefer self-created fixtures, tolerate drift.
- **Scope creep of the scenario library:** start narrow (tickets + wiki), grow deliberately; the library is versioned and reviewable.
- **MCP-into-town-agent integration:** if wiring Playwright MCP into a town agent is impractical, the script-runner fallback keeps the capability without the integration risk.
