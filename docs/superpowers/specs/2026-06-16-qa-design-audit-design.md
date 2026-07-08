# QA Agent — Design-Audit + Verify-a-Change — Design

**Date:** 2026-06-16
**Status:** Approved design, pending implementation planning
**Builds on:** `2026-06-13-qa-agent-design.md` (the QA agent + harness). This extends `soundit-psa/gastown.qa` and `scripts/qa/playwright-runner.mjs` — it adds **no new agent or harness infrastructure**.

## 1. Purpose

The QA agent already drives the dev server in a real browser and files functional + interaction-UX findings from scenario "watch-fors." Two gaps remain:

1. It captures screenshots but does **not** systematically critique **visual design quality** (hierarchy, spacing, typography, color, states) or **accessibility / responsive** behavior — the screenshots are evidence, not audited.
2. It runs a versioned scenario library but cannot **verify one specific change** end-to-end (drive the screens a PR touches and confirm the change works) — a human still does that by hand (e.g. the manual `psa-ymw8` browser verification).

This adds both: a structured **design-audit** (powered by the `impeccable` skill) and a **verify-a-change** run mode.

### Goals
- Structured design-audit on key screens across two dimensions — **visual polish** and **a11y/responsive** — using `impeccable` as the critique engine.
- A **verify-a-change** mode: point the agent at a change; it drives the touched screens, confirms intent, and design-audits them.
- Findings flow into the existing beads pipeline with a new `design` category, severity, and dedup so the queue stays signal-rich.

### Non-goals
- No new harness/agent infra — extend the existing ones.
- No production target — the existing host-guard + `/dev/login` (`APP_ENV=local`-only) constraints are unchanged.
- The agent files findings; it does not fix code.
- Not a replacement for the existing functional/UX scenarios — additive.

## 2. Decisions made during design

| Question | Decision |
|----------|----------|
| Design-critique engine | `gastown.qa` invokes the **live `impeccable` skill** (`~/.claude/skills/impeccable/` — machine-global, loadable by the rig agent) on captured screenshots + axe data. Not a ported static rubric: avoids drift, full fidelity. Confirmed-loadable; fallback in §7. |
| Dimensions | **Both.** Visual polish (impeccable's judgment over screenshots) AND a11y/responsive (axe-core + multi-viewport screenshots feeding impeccable). |
| Finding category | New 4th label **`design`**, distinct from `ux` (interaction). Visual-design vs flow-confusion are different concerns and triage differently. |
| Verify-a-change | A new run mode that **generalizes the manual `psa-ymw8` verify**: derive touched screens from a change, drive + confirm intent + design-audit. |
| Harness | Extend `scripts/qa/playwright-runner.mjs` (multi-viewport + `axe` action). **Retire** the ad-hoc `/home/charlie/verify-harness` (a duplicate; the QA harness's `/dev/login` beats its cookie-mint workaround). |
| Responsive scope | Multi-viewport included now (mobile 390 / tablet 768 / desktop 1440), per the "robust" framing. |

## 3. Architecture

```
  QA-run bead (scenario set  OR  verify-change: PR#/branch/screens)
        │
        ▼
  ┌─────────────────────┐   drives    ┌──────────────────────────────┐
  │   gastown.qa        │────────────▶│ playwright-runner.mjs        │
  │  • mission prompt   │             │  • multi-viewport screenshots│
  │  • impeccable skill │◀────────────│  • axe-core a11y action      │
  │  • gc bd filer      │  captures   │  • a11y snapshot (existing)  │
  └──────────┬──────────┘             └──────────────────────────────┘
             │ invokes impeccable on (screen intent + screenshots + axe)
             ▼ structured critique  → maps to findings
   beads (psa-, labels qa + design / bug / ux / docs) + per-run report
```

### 3.1 Harness extensions — `scripts/qa/playwright-runner.mjs`
- **`viewports`**: optional array of `{name,width,height}` (default mobile 390 / tablet 768 / desktop 1440). The `screenshot` action captures the screen once per viewport (e.g. `infra@mobile.png`), so broken breakpoints are observable.
- **`axe` action**: inject axe-core, run against the current page, return `{violations:[{id,impact,help,nodes}]}`.
- Output JSON gains `axe[]` and per-viewport screenshot sets. **Host-guard and exit codes unchanged.** `axe-core` added as a `scripts/qa` dev dependency.

### 3.2 Design-audit pass — `gastown.qa` prompt instruction
A new section in the agent's mission prompt: after driving a key screen, **capture multi-viewport screenshots + run `axe`, then invoke the `impeccable` skill**, providing (a) the screen's identity and intended purpose, (b) the screenshot paths, (c) the axe violations. `impeccable` returns a structured critique across its dimensions (hierarchy, spacing, typography, color, states, a11y, responsive). The agent maps each **material** issue → a `design` finding carrying: dimension, severity, the screenshot, the issue, and impeccable's suggested fix.

"Key screens" = in verify-change mode (§3.3), all touched screens; in scenario runs, a subset designated on the QA-run bead. The design-audit is heavier than a functional pass — a vision-model critique per screen — so a run **scopes which screens get it** rather than auditing every screen by default. This keeps cost and finding-volume bounded.

### 3.3 Verify-a-change mode
- **Input** (on the QA-run bead): a change reference — PR number / branch / an explicit screen list.
- **Derive touched screens**: from the diff, map changed Blade views / route files → the URLs that render them (e.g. `resources/views/assets/show.blade.php` → `/assets/{id}`). Where derivation is ambiguous, the bead may name screens explicitly (the escape hatch).
- **For each screen**: drive it and confirm the change's **stated intent** works (functional PASS/FAIL — the agent reasons from the change description), then run the design-audit (§3.2).
- **Output**: a per-screen verdict + findings + screenshots in the run report — the automated form of the manual `psa-ymw8` verification.

### 3.4 Findings — `App\Services\Qa\QaFinding` / `QaFindingFiler`
- New category **`design`** (4th, alongside `bug`/`ux`/`docs`). `QaFinding` gains it; `QaFindingFiler` labels `qa` + `design`.
- **Severity** (to fight noise): **blocker** (broken layout / serious axe violation) · **major** (clearly unprofessional) · **minor/polish** (refinement). The agent assigns; the supervised run (§5) calibrates the bar.
- **Dedup** key = scenario/screen + dimension + assertion, so repeated runs don't duplicate a standing design issue.

## 4. Error handling
- **Non-dev host**: hard refusal before any browser action (existing guard, unchanged).
- **`impeccable` / skill-load failure**: recorded as a harness-error finding (distinct from a product failure), the agent continues to the next screen.
- **axe failure**: the screen's a11y dimension is reported as errored, not as "passed."
- **Finding-filing failure** (beads unreachable): captured in the run report so design findings are never silently lost (existing behavior).

## 5. Testing
- **Unit (no browser):** axe-result parsing; the multi-viewport option (shapes the screenshot set); `design`-finding → bead mapping incl. label + severity + dedup keying; change→screens derivation.
- **First supervised design-audit run:** the agent's `design` bar is validated on a known screen set (tickets + assets) and reviewed together before it files `design` findings autonomously — mirroring how the original QA agent was calibrated.

## 6. Phasing
1. **Harness**: multi-viewport + `axe` action + unit tests.
2. **Design-audit pass**: the `impeccable`-invocation prompt instruction + `design` findings + dedup.
3. **Verify-a-change mode**: change→screens derivation + the new run type.
4. **Supervised calibration run** → then autonomous `design` filing.

## 7. Risks
- **`impeccable` in batch**: it is built for interactive iteration, not headless batch. Mitigation: a tight invocation framing — "audit these screenshots for {screen}, return findings by dimension + severity" — and the supervised calibration run.
- **Design-finding subjectivity / noise**: severity + dedup + the supervised calibration bar.
- **Rig-agent skill access**: verified loadable (`~/.claude/skills/impeccable`), but confirm at implementation. **Fallback**: port impeccable's rubric into a versioned `docs/qa/design-rubric.md` the agent applies (loses live-fidelity, keeps the capability).
- **Change→screens derivation brittleness**: the explicit-screen-list input on the bead is the escape hatch when a diff doesn't map cleanly to URLs.
