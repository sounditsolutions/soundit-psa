# QA Design-Audit + Verify-a-Change Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extend the existing QA agent (`gastown.qa` + `scripts/qa/playwright-runner.mjs`) with a structured design-audit (the `impeccable` skill as the critique engine, across visual-polish + a11y/responsive) and a verify-a-change run mode.

**Architecture:** No new infra — extend the existing harness (add a multi-viewport `screenshot` capability + an `axe` a11y action), add a 4th `design` finding category to the PHP services, and add two new sections to the QA agent's mission prompt (a design-audit pass that invokes `impeccable`, and a verify-a-change mode). Findings flow through the existing `QaFindingFiler` beads pipeline.

**Tech Stack:** Node ESM + Playwright + axe-core (the harness, `scripts/qa/`); PHP 8 + Laravel + PHPUnit (the services, `app/Services/Qa/`); Gas Town agent template (markdown + TOML, `/home/charlie/gascity/agents/qa/`); the `impeccable` Claude Code skill (`~/.claude/skills/impeccable/`).

**Spec:** `docs/superpowers/specs/2026-06-16-qa-design-audit-design.md`

**Branch:** create `feat/qa-design-audit` off `main` before Task 1.

---

## File Structure

| File | Change | Responsibility |
|------|--------|----------------|
| `scripts/qa/package.json` | modify | add `axe-core` dependency |
| `scripts/qa/playwright-runner.mjs` | modify | add `axe` action + multi-viewport `screenshot` |
| `app/Services/Qa/QaFinding.php` | modify | add `design` to `KINDS` |
| `tests/Feature/Qa/QaFindingFilerTest.php` | modify | cover the `design` category |
| `/home/charlie/gascity/agents/qa/agent.toml` | modify (empty → populated) | provision the agent (provider/model/work_dir) |
| `/home/charlie/gascity/agents/qa/prompt.template.md` | modify | add `## Design audit` + `## Verify-a-change` sections; teach `axe`/multi-viewport/`design` |
| `docs/qa/README.md` | modify | document the `axe` action, `viewports`, and the `design` category |
| `docs/qa/dev-environment.md` | modify | note the `design`-audit run needs no new env beyond existing |

**Harness-testing note:** `playwright-runner.mjs` has no PHPUnit test by design — it runs only against the live dev server (confirmed: `scripts/qa/package.json` `test` is a no-op stub, no `tests/**/Runner` exists). So harness tasks are verified by a **live-dev smoke run** with the exact command + expected output (the codebase's established convention), not a unit test. The PHP service change (Task 3) uses real TDD.

**Pre-req for harness smoke runs:** the dev server must be reachable and a viewer logged in via `/dev/login/1`. The smoke commands below set `QA_ALLOWED_HOSTS=soundit-dev,192.168.1.51` and target `https://192.168.1.51` (the IP the box answers on). Confirm reachable first: `curl -sk -o /dev/null -w '%{http_code}\n' https://192.168.1.51/` → expect `302` (redirect to login).

---

## Task 0: Branch + install harness dep

- [ ] **Step 1: Branch off main**

Run:
```bash
cd /home/charlie/repos/soundit-psa
git checkout main && git checkout -b feat/qa-design-audit
```
Expected: `Switched to a new branch 'feat/qa-design-audit'`. (Note: uncommitted `psa-ymw8` working-tree changes, if present, are unrelated — do not stage them in this plan's commits.)

- [ ] **Step 2: Confirm dev server reachable**

Run: `curl -sk -o /dev/null -w '%{http_code}\n' https://192.168.1.51/`
Expected: `302`

---

## Task 1: Add the `axe` a11y action to the harness

**Files:**
- Modify: `scripts/qa/package.json`
- Modify: `scripts/qa/playwright-runner.mjs` (imports near top; `result` init ~line 72; action `switch` ~line 97)

- [ ] **Step 1: Add the axe-core dependency**

Edit `scripts/qa/package.json` — add `axe-core` to `dependencies`:
```json
  "dependencies": {
    "playwright": "^1.60.0",
    "axe-core": "^4.10.2"
  }
```

- [ ] **Step 2: Install it**

Run: `cd /home/charlie/repos/soundit-psa/scripts/qa && npm install`
Expected: `added 1 package` (or `up to date` if cached); `node_modules/axe-core/axe.min.js` now exists — verify: `ls node_modules/axe-core/axe.min.js` → prints the path.

- [ ] **Step 3: Add a `createRequire` import to the runner**

`playwright-runner.mjs` is ESM, so `require.resolve` needs `createRequire`. At the top of the file, with the other imports (the file already imports `chromium` from `'playwright'`, `mkdirSync` from `'fs'`, `dirname` from `'path'`), add:
```js
import { createRequire } from 'module';

const require = createRequire(import.meta.url);
```

- [ ] **Step 4: Initialize `result.axe`**

In `run(job)`, find the `result` initializer (~line 72):
```js
  const result = { scenario, ok: true, steps: [], screenshots: [], finalUrl: null, snapshot: null, error: null };
```
Change it to add `axe: []`:
```js
  const result = { scenario, ok: true, steps: [], screenshots: [], finalUrl: null, snapshot: null, axe: [], error: null };
```

- [ ] **Step 5: Add the `axe` action handler**

In the action `switch`, add a `case 'axe'` (place it just before `case 'screenshot'`):
```js
          case 'axe': {
            // Inject the axe-core bundle into the page and run it; record only the
            // violations (the actionable half). Read-only: axe never mutates the DOM.
            await page.addScriptTag({ path: require.resolve('axe-core/axe.min.js') });
            const axeRun = await page.evaluate(async () => await window.axe.run());
            const violations = (axeRun.violations || []).map((v) => ({
              id: v.id,
              impact: v.impact,
              help: v.help,
              nodes: v.nodes.flatMap((n) => n.target),
            }));
            result.axe.push({ url: page.url(), label: action.name || `axe-${i}`, violations });
            step.detail = `axe @ ${page.url()}: ${violations.length} violation(s)`;
            break;
          }
```

- [ ] **Step 6: Smoke-test against live dev**

Run:
```bash
cd /home/charlie/repos/soundit-psa
echo '{"baseUrl":"https://192.168.1.51","loginUserId":1,"scenario":"axe-smoke","actions":[
  {"type":"goto","path":"/assets/23"},
  {"type":"axe","name":"asset-detail"}]}' \
  | QA_ALLOWED_HOSTS=soundit-dev,192.168.1.51 node scripts/qa/playwright-runner.mjs 2>/dev/null \
  | node -e 'const r=JSON.parse(require("fs").readFileSync(0));console.log("ok="+r.ok,"axeEntries="+(r.axe?.length),"firstViolationCount="+(r.axe?.[0]?.violations?.length))'
```
Expected: a line like `ok=true axeEntries=1 firstViolationCount=<N>` where `<N>` is a number ≥ 0 (a real count, not `undefined`). If `axeEntries=undefined`, `result.axe` wasn't initialized (Step 4); if the step errors, check `require.resolve('axe-core/axe.min.js')` resolves (Step 2/3).

- [ ] **Step 7: Commit**

```bash
cd /home/charlie/repos/soundit-psa
git add scripts/qa/package.json scripts/qa/package-lock.json scripts/qa/playwright-runner.mjs
git commit -m "feat(qa): add axe-core a11y action to the playwright harness"
```

---

## Task 2: Add multi-viewport screenshots to the harness

**Files:**
- Modify: `scripts/qa/playwright-runner.mjs` (job destructure ~line 68; `screenshot` handler ~line 141)

- [ ] **Step 1: Destructure `viewports` from the job**

Find the job destructure (~line 68):
```js
  const { baseUrl, loginUserId = 1, scenario = 'unnamed', actions = [] } = job;
```
Add `viewports`:
```js
  const { baseUrl, loginUserId = 1, scenario = 'unnamed', actions = [], viewports = null } = job;
```

- [ ] **Step 2: Make the `screenshot` handler viewport-aware**

Replace the existing `case 'screenshot'` block:
```js
          case 'screenshot': {
            const path = `${screenDir}/${scenario}-${action.name || i}.png`;
            mkdirSync(dirname(path), { recursive: true });
            await page.screenshot({ path, fullPage: true });
            result.screenshots.push(path);
            break;
          }
```
with a loop over `viewports` (falling back to a single default-viewport capture when none given, preserving current behavior):
```js
          case 'screenshot': {
            // With a `viewports` job field, capture the same screen at each breakpoint
            // (named suffix) so responsive regressions are observable. Without it,
            // capture once at the default viewport (unchanged behavior).
            const vps = viewports && viewports.length ? viewports : [null];
            for (const vp of vps) {
              if (vp) await page.setViewportSize({ width: vp.width, height: vp.height });
              const suffix = vp ? `@${vp.name}` : '';
              const path = `${screenDir}/${scenario}-${action.name || i}${suffix}.png`;
              mkdirSync(dirname(path), { recursive: true });
              await page.screenshot({ path, fullPage: true });
              result.screenshots.push(path);
            }
            break;
          }
```

- [ ] **Step 3: Smoke-test multi-viewport against live dev**

Run:
```bash
cd /home/charlie/repos/soundit-psa
echo '{"baseUrl":"https://192.168.1.51","loginUserId":1,"scenario":"vp-smoke",
  "viewports":[{"name":"mobile","width":390,"height":844},{"name":"tablet","width":768,"height":1024},{"name":"desktop","width":1440,"height":900}],
  "actions":[{"type":"goto","path":"/assets/23"},{"type":"screenshot","name":"asset"}]}' \
  | QA_ALLOWED_HOSTS=soundit-dev,192.168.1.51 QA_SCREENSHOT_DIR=/tmp/qa-vp node scripts/qa/playwright-runner.mjs 2>/dev/null \
  | node -e 'const r=JSON.parse(require("fs").readFileSync(0));console.log(r.screenshots)'
```
Expected: an array of **three** paths ending `vp-smoke-asset@mobile.png`, `…@tablet.png`, `…@desktop.png`. Confirm the files exist: `ls /tmp/qa-vp/` → the three PNGs.

- [ ] **Step 4: Confirm the no-viewport path is unchanged**

Run the same command but **without** the `viewports` field (one `goto` + one `screenshot`):
```bash
echo '{"baseUrl":"https://192.168.1.51","loginUserId":1,"scenario":"vp-default","actions":[{"type":"goto","path":"/assets/23"},{"type":"screenshot","name":"asset"}]}' \
  | QA_ALLOWED_HOSTS=soundit-dev,192.168.1.51 QA_SCREENSHOT_DIR=/tmp/qa-vp node scripts/qa/playwright-runner.mjs 2>/dev/null \
  | node -e 'const r=JSON.parse(require("fs").readFileSync(0));console.log(r.screenshots)'
```
Expected: a **single** path `vp-default-asset.png` (no `@` suffix) — proving backward compatibility.

- [ ] **Step 5: Commit**

```bash
cd /home/charlie/repos/soundit-psa
git add scripts/qa/playwright-runner.mjs
git commit -m "feat(qa): multi-viewport screenshot capture in the harness"
```

---

## Task 3: Add the `design` finding category (TDD)

**Files:**
- Modify: `app/Services/Qa/QaFinding.php:7` (the `KINDS` const)
- Test: `tests/Feature/Qa/QaFindingFilerTest.php` (add one method)

- [ ] **Step 1: Write the failing test**

Add this method to `tests/Feature/Qa/QaFindingFilerTest.php` (follows the existing pattern — injected `$runner`/`$existing` closures, `assertContains` on the argv array):
```php
    public function test_files_a_design_finding_as_a_labeled_bead(): void
    {
        $calls = [];
        $runner = function (array $cmd) use (&$calls) {
            $calls[] = $cmd;

            return 'psa-DSGN1';
        };

        $filer = new QaFindingFiler($runner, fn () => []);
        $finding = new QaFinding(
            scenarioId: 'asset-show',
            title: '[spacing] Action buttons are cramped in the asset-detail header',
            kind: 'design',
            severity: 'minor',
            steps: ['Open /assets/23', 'Observe the header action row at desktop width'],
            expected: 'Comfortable, consistent spacing between action buttons',
            actual: 'Buttons abut with <4px gap; visually crowded',
            screenshotPath: '/tmp/qa/asset-show@desktop.png',
        );

        $id = $filer->file($finding);

        $this->assertSame('psa-DSGN1', $id);
        $create = collect($calls)->first(fn ($c) => in_array('create', $c, true));
        $this->assertNotNull($create, 'expected a bd create call');
        $this->assertContains('qa', $create);
        $this->assertContains('design', $create);
    }
```

- [ ] **Step 2: Run it — verify it FAILS for the right reason**

Run: `cd /home/charlie/repos/soundit-psa && php artisan test tests/Feature/Qa/QaFindingFilerTest.php --filter test_files_a_design_finding_as_a_labeled_bead`
Expected: FAIL with `InvalidArgumentException: Invalid finding kind 'design'. One of: bug, ux, docs` (the filer's `in_array($finding->kind, QaFinding::KINDS, true)` guard rejects `design`).

- [ ] **Step 3: Add `design` to `KINDS`**

In `app/Services/Qa/QaFinding.php`, change:
```php
    public const KINDS = ['bug', 'ux', 'docs'];
```
to:
```php
    public const KINDS = ['bug', 'ux', 'docs', 'design'];
```

- [ ] **Step 4: Run it — verify it PASSES, and the suite is green**

Run: `cd /home/charlie/repos/soundit-psa && php artisan test tests/Feature/Qa/QaFindingFilerTest.php`
Expected: all methods PASS (the new one + the existing three). Then run the full QA test group to confirm no regression: `php artisan test tests/Unit/Qa tests/Feature/Qa`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
cd /home/charlie/repos/soundit-psa
git add app/Services/Qa/QaFinding.php tests/Feature/Qa/QaFindingFilerTest.php
git commit -m "feat(qa): add 'design' finding category"
```

---

## Task 4: Provision + teach the QA agent — design-audit pass

**Files:**
- Modify: `/home/charlie/gascity/agents/qa/agent.toml` (currently 0 bytes)
- Modify: `/home/charlie/gascity/agents/qa/prompt.template.md` (new `## Design audit` section; extend harness + filing sections)

These files are outside the repo (city-owned). Commit them in the city's git if it tracks them; otherwise they are live-edited config (note in the run report which files changed).

- [ ] **Step 1: Populate the empty `agent.toml`**

Write `/home/charlie/gascity/agents/qa/agent.toml`, mirroring `/home/charlie/gascity/agents/ux-reviewer/agent.toml` but with a stronger model (design critique + `impeccable` + vision is demanding):
```toml
provider = "claude"
wake_mode = "fresh"
idle_timeout = "30m"
work_dir = "/home/charlie/repos/soundit-psa"

min_active_sessions = 0
max_active_sessions = 1
option_defaults = { model = "opus" }
```

- [ ] **Step 2: Add the `## Design audit` section to the prompt**

Insert this section into `/home/charlie/gascity/agents/qa/prompt.template.md` **between** the `## The harness (Playwright script-runner)` section and the `## Filing findings` section:
```markdown
## Design audit (impeccable-powered)

On a design-audit run (or for any screen a verify-a-change run touches), after you
drive a key screen, critique its *visual design* — not just whether it works:

1. Capture it across breakpoints and scan a11y, in one runner job:
   - add `"viewports":[{"name":"mobile","width":390,"height":844},{"name":"tablet","width":768,"height":1024},{"name":"desktop","width":1440,"height":900}]` to the job,
   - a `{"type":"screenshot","name":"<screen>"}` action (captures one PNG per viewport, suffixed `@mobile/@tablet/@desktop`),
   - a `{"type":"axe","name":"<screen>"}` action (records `result.axe[].violations`).
2. Invoke the design-critique engine: **`Skill: impeccable`**. Give it: what the
   screen is and its intended purpose, the screenshot paths from `result.screenshots`,
   and the `result.axe` violations. Ask it to audit across its dimensions — visual
   hierarchy, spacing/alignment, typography, color, consistency, empty/loading/error
   states, and responsive behavior across the three breakpoints.
3. Turn each **material** issue impeccable returns into a `design` finding (see
   Filing findings). Encode the design dimension in the title in brackets, e.g.
   `[spacing] …`, `[hierarchy] …`, `[a11y] …`, `[responsive] …` — this is what makes
   the dedup key (`scenario|title`) stable across runs. Attach the most illustrative
   screenshot path. Severity: **blocker** (broken layout / serious axe violation) ·
   **major** (clearly unprofessional) · **minor** (polish). Hold a high bar — file
   the issues a paying customer would notice, not every nitpick.

Do not file `design` findings autonomously until the operator has reviewed your first
calibration run (see Guardrails).
```

- [ ] **Step 3: Teach `axe` + multi-viewport in the harness section**

In the `## The harness (Playwright script-runner)` section's action list, add two bullets after the `screenshot` entry:
```markdown
- `axe {name}` — runs axe-core against the current page; violations land in `result.axe[]` (`{url,label,violations:[{id,impact,help,nodes}]}`).
- multi-viewport: add a top-level `viewports:[{name,width,height},…]` job field; each `screenshot` then captures one PNG per viewport, named `…@<name>.png`.
```

- [ ] **Step 4: Add `design` to the filing/classification guidance**

In the `## Filing findings` section, where it lists the `bug`/`ux`/`docs` labels and classification, add `design` as the 4th category:
```markdown
- `design` — a *visual* design problem (hierarchy, spacing, typography, color,
  consistency, responsive breakage, or an axe a11y violation), as judged by the
  design-audit pass. Distinct from `ux` (a confusing *flow*); use `design` for how
  it *looks*, `ux` for how it *works*. Label: `--labels qa --labels design`.
```

- [ ] **Step 5: Verify the prompt is well-formed + the agent is dispatchable**

Run: `gc agent show soundit-psa/gastown.qa 2>&1 | head -30` (or `gc config show 2>&1 | grep -iA3 'agents.*qa'`).
Expected: the QA agent resolves with `provider=claude`, `model=opus`, `work_dir=/home/charlie/repos/soundit-psa` (no "empty config" / "agent not found"). If the city tracks these files in git, commit:
```bash
cd /home/charlie/gascity && git add agents/qa/agent.toml agents/qa/prompt.template.md && git commit -m "feat(qa): provision qa agent + add design-audit (impeccable) pass"
```
(If `/home/charlie/gascity` is not a git repo, skip the commit — the files are live config; record the change in the run report.)

---

## Task 5: Add the verify-a-change mode to the prompt

**Files:**
- Modify: `/home/charlie/gascity/agents/qa/prompt.template.md` (new `## Verify-a-change mode` section)

- [ ] **Step 1: Add the `## Verify-a-change mode` section**

Insert after the `## Design audit` section:
```markdown
## Verify-a-change mode

When the QA-run bead names a **change** to verify (a PR number, a branch, or an
explicit screen list) instead of a scenario set, your job is to confirm that one
change works — functionally and visually — the way a human reviewer would:

1. Determine the touched screens:
   - If the bead lists screens/URLs explicitly, use those.
   - Otherwise derive them: `git -C /home/charlie/repos/soundit-psa diff <base>...<ref> --name-only`,
     then map changed `resources/views/**/*.blade.php` and `routes/*.php` to the URLs
     that render them (e.g. `resources/views/assets/show.blade.php` → `/assets/{id}`,
     pick a representative seeded id). When a mapping is unclear, say so in the report
     and ask for an explicit screen list rather than guessing.
2. For each screen: drive it with the harness and confirm the change's **stated
   intent** (from the bead / PR description) actually happened — click through the
   specific behavior the change adds, `expectText`/`expectMissing` the concrete
   evidence. Record a per-screen **PASS/FAIL**.
3. Run the design audit (above) on each touched screen.
4. Report: a per-screen verdict + any `bug`/`design` findings + the screenshots — the
   automated equivalent of a manual browser verification. A functional FAIL is a
   `bug` finding; visual problems are `design` findings.
```

- [ ] **Step 2: Verify the section parses + reads cleanly**

Run: `sed -n '/## Verify-a-change mode/,/^## /p' /home/charlie/gascity/agents/qa/prompt.template.md | head -40`
Expected: the full section prints, headings intact, no broken markdown.

- [ ] **Step 3: Commit (if city is tracked)**

```bash
cd /home/charlie/gascity && git add agents/qa/prompt.template.md && git commit -m "feat(qa): add verify-a-change run mode to the qa agent prompt" 2>/dev/null || echo "(city not git-tracked; live config updated)"
```

---

## Task 6: Update docs + retire the ad-hoc harness

**Files:**
- Modify: `docs/qa/README.md`
- Modify: `docs/qa/dev-environment.md`
- Remove: `/home/charlie/verify-harness/` (the ad-hoc mayor harness — superseded)

- [ ] **Step 1: Document the new actions in the README**

In `docs/qa/README.md`, in the Actions list (the bullet beginning "Actions: `goto {path}` …"), append:
```markdown
  `axe {name}` (axe-core a11y scan → `result.axe[]`), and multi-viewport screenshots via a top-level `viewports:[{name,width,height},…]` job field.
```
And in "## The agent loop", add a sentence: `On design-audit runs the agent also invokes the impeccable skill on the captured multi-viewport screenshots + axe results, filing design findings (label qa+design).`

- [ ] **Step 2: Note the design-audit needs no new dev env**

In `docs/qa/dev-environment.md`, add a one-line note: `The design-audit + verify-a-change modes use the same dev contract — no new env beyond QA_ALLOWED_HOSTS, /dev/login, and the queue worker. axe-core installs with the harness (npm install in scripts/qa).`

- [ ] **Step 3: Retire the duplicate ad-hoc harness**

Run: `rm -rf /home/charlie/verify-harness`
Expected: no output. (It duplicated `scripts/qa/playwright-runner.mjs`; the QA harness's `/dev/login` supersedes its cookie-mint.)

- [ ] **Step 4: Commit**

```bash
cd /home/charlie/repos/soundit-psa
git add docs/qa/README.md docs/qa/dev-environment.md
git commit -m "docs(qa): document axe + multi-viewport + design-audit; retire ad-hoc harness"
```

---

## Task 7: Supervised calibration run (gate before autonomous design filing)

This is a **human-in-the-loop** task — the spec's first-supervised-run gate. Do not mark the feature done until it passes.

- [ ] **Step 1: Run a design-audit on a known screen set**

Dispatch the QA agent (or drive the harness directly) to design-audit two representative screens — the asset detail (`/assets/23`) and a ticket screen — capturing multi-viewport screenshots + axe, invoking `impeccable`, and producing **candidate** `design` findings WITHOUT filing them (dry run: print the would-be findings).

Smoke the capture half directly:
```bash
cd /home/charlie/repos/soundit-psa
echo '{"baseUrl":"https://192.168.1.51","loginUserId":1,"scenario":"calib",
  "viewports":[{"name":"mobile","width":390,"height":844},{"name":"desktop","width":1440,"height":900}],
  "actions":[{"type":"goto","path":"/assets/23"},{"type":"screenshot","name":"asset"},{"type":"axe","name":"asset"}]}' \
  | QA_ALLOWED_HOSTS=soundit-dev,192.168.1.51 QA_SCREENSHOT_DIR=/tmp/qa-calib node scripts/qa/playwright-runner.mjs 2>/dev/null \
  | node -e 'const r=JSON.parse(require("fs").readFileSync(0));console.log("screens:",r.screenshots,"\naxe:",JSON.stringify(r.axe?.map(a=>({label:a.label,violations:a.violations.length})))'
```
Expected: screenshots for both viewports + an axe entry with a violation count.

- [ ] **Step 2: Review the candidate `design` findings with the operator**

Present the candidate findings (with screenshots) to the operator. Calibrate the severity bar together — confirm the agent files the issues a paying customer would notice, not nitpicks. Adjust the `## Design audit` prompt wording if the bar is off.

- [ ] **Step 3: Enable autonomous filing**

Once calibrated, remove the "do not file autonomously until reviewed" sentence from the `## Design audit` prompt section (Task 4 Step 2). Commit the prompt change (if city tracked). The QA agent may now file `design` findings on its own.

- [ ] **Step 4: Open the PR**

```bash
cd /home/charlie/repos/soundit-psa
gh pr create --repo $(git remote get-url origin | sed 's/.*github.com[:/]\(.*\)\.git/\1/') \
  --title "QA: design-audit (impeccable) + verify-a-change mode" \
  --body "Implements docs/superpowers/specs/2026-06-16-qa-design-audit-design.md. Adds axe + multi-viewport to the harness, a design finding category, the impeccable-powered design-audit pass, and a verify-a-change mode. Calibrated via a supervised first run."
```

---

## Self-Review

**Spec coverage:**
- §3.1 harness multi-viewport + axe → Tasks 1, 2 ✓
- §3.2 design-audit pass invoking impeccable → Task 4 (Steps 2–4) ✓
- §3.3 verify-a-change mode → Task 5 ✓
- §3.4 `design` category + severity + dedup → Task 3 (`design` in KINDS) + Task 4 Step 2 (severity vocabulary blocker/major/minor in the body; dimension-in-title makes the existing `scenario|title` dedup key stable) ✓
- §4 error handling: harness-error vs product-failure is unchanged (existing `harnessError` flag); axe failure surfaces as a failed step (the `switch` `catch`) ✓
- §5 testing: Task 3 is TDD; harness via live smoke (codebase convention, justified); Task 7 = the supervised calibration run ✓
- §6 phasing (harness → design-audit → verify-change → calibration) → Tasks 1-2 → 4 → 5 → 7 ✓
- §7 fallback (port impeccable rubric) → not built (live skill is the chosen path; fallback documented in spec, no task needed unless skill-load fails at runtime) ✓
- Retire ad-hoc harness → Task 6 Step 3 ✓
- agent.toml provisioning (gotcha from code map) → Task 4 Step 1 ✓

**Placeholder scan:** No TBD/TODO; every code step has complete code; every command has expected output. ✓

**Type/name consistency:** `result.axe` (array of `{url,label,violations}`) is initialized in Task 1 Step 4 and pushed in Step 5 and read in Task 7 — consistent. `viewports:[{name,width,height}]` job field defined in Task 2 Step 1, consumed in Step 2, used identically in the prompt (Task 4) and smokes (Tasks 2, 7) — consistent. `KINDS` includes `design` (Task 3) and the prompt/filing guidance uses `--labels qa --labels design` matching the filer's existing `--labels qa --labels <kind>` — consistent. ✓

**One known soft spot (flagged, not a gap):** verify-a-change auto-derivation of URLs from a diff is best-effort; the explicit-screen-list escape hatch (Task 5 Step 1) is the deterministic path. This matches spec §7's stated risk + mitigation.
