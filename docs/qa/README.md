# QA Agent + Harness

An autonomous QA agent (`soundit-psa/gastown.qa`) drives the dev server in a real browser through a versioned scenario library, filing bug/UX/doc-gap findings as labeled `psa-` beads plus a per-run report. Spec/plan: `docs/superpowers/specs/2026-06-13-qa-agent-design.md`, `docs/superpowers/plans/2026-06-13-qa-agent.md`.

## Harness: Playwright script-runner (decision)
**Decision (Task 7 spike): the agent drives the browser via the script-runner, not a Playwright MCP server.** No Gas Town agent uses MCP today (zero MCP config in the town), so wiring an MCP server into a town agent would be net-new infra risk; the script-runner is proven end-to-end against the live dev server. MCP (interactive step-by-step browsing) remains a future enhancement if the batch model proves limiting.

- Driver: `scripts/qa/playwright-runner.mjs` (Playwright + headless Chromium).
- Install (per checkout where the agent runs): `cd scripts/qa && npm ci && npx playwright install chromium` (system deps via `sudo npx playwright install --with-deps chromium` once per box). `node_modules` is git-ignored.
- Invocation: pipe a JSON job on stdin, set `QA_ALLOWED_HOSTS` (comma-separated dev hosts) and optionally `QA_SCREENSHOT_DIR`:
  ```bash
  echo '{"baseUrl":"https://soundit-dev","loginUserId":1,"scenario":"wiki-mine","actions":[
    {"type":"goto","path":"/clients/6/wiki"},
    {"type":"expectText","text":"Infrastructure"},
    {"type":"snapshot"},
    {"type":"screenshot","name":"infra"}]}' \
    | QA_ALLOWED_HOSTS=soundit-dev node scripts/qa/playwright-runner.mjs
  ```
- Actions: `goto {path}`, `click {selector}`, `fill {selector,value}`, `expectText {text}`, `expectMissing {text}`, `snapshot` (accessibility tree), `screenshot {name}`, `axe {name}` (axe-core a11y scan → `result.axe[]`), and multi-viewport screenshots via a top-level `viewports:[{name,width,height},…]` job field.
- Output: JSON with per-step ok/detail, `finalUrl`, the accessibility `snapshot`, `screenshots[]`, `pageErrors[]`, and a `harnessError` flag distinguishing harness failures from product failures. Exit 0 = all steps ok, 1 = a step failed, 2 = harness/guard error.

## Safety
- The runner refuses any `baseUrl` whose host is not in `QA_ALLOWED_HOSTS`, contains a backslash, or has a non-http(s) scheme — mirroring `App\Services\Qa\QaTargetGuard`. QA never drives a non-dev host. The dev-login bypass only exists when `APP_ENV=local`, so the same config cannot reach production.

## The agent loop
The `gastown.qa` agent: reads a scenario from `docs/qa/scenarios/*.md` (parsed by `App\Services\Qa\QaScenarioLoader`), authors a runner job, runs it, reasons over the result + snapshot + screenshot (deviating to investigate anything off), and files findings via `App\Services\Qa\QaFindingFiler` (→ `psa-` beads labeled `qa` + `bug`/`ux`/`docs`, deduped). A per-run report (`App\Services\Qa\QaRunReport` / `php artisan qa:report`) lands under `storage/qa-reports/`. On design-audit runs the agent also invokes the impeccable skill on the captured multi-viewport screenshots + axe results, filing design findings (label qa+design).

See `dev-environment.md` for the dev-server contract (queue worker is required for async flows).
