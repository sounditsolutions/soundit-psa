#!/usr/bin/env node
// QA harness driver. Reads a JSON job on stdin, drives headless Chromium against
// the dev server (via the /dev/login/{id} bypass), and emits a JSON result on
// stdout. The QA agent authors the job, runs this, and reasons over the result +
// screenshots + accessibility snapshot.
//
// Job shape (stdin JSON):
// {
//   "baseUrl": "https://soundit-dev",
//   "loginUserId": 1,
//   "scenario": "wiki-mine",
//   "actions": [
//     {"type": "goto", "path": "/clients/6/wiki"},
//     {"type": "click", "selector": "text=Infrastructure"},
//     {"type": "fill", "selector": "input[name=q]", "value": "FortiGate"},
//     {"type": "selectOption", "selector": "#client_id", "value": "Acme Corp"},
//     {"type": "expectText", "text": "SonicWall"},
//     {"type": "snapshot"},
//     {"type": "screenshot", "name": "infra"}
//   ]
// }
//
// SAFETY: refuses any baseUrl whose host is not in QA_ALLOWED_HOSTS (comma-separated
// env var). Mirrors app/Services/Qa/QaTargetGuard at the Node layer. QA never drives
// a non-dev host.

import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { dirname } from 'node:path';
import { createRequire } from 'module';

const require = createRequire(import.meta.url);

function readStdin() {
  return new Promise((resolve, reject) => {
    let data = '';
    process.stdin.setEncoding('utf8');
    process.stdin.on('data', (c) => (data += c));
    process.stdin.on('end', () => resolve(data));
    process.stdin.on('error', reject);
  });
}

function assertAllowedHost(baseUrl) {
  const allowed = (process.env.QA_ALLOWED_HOSTS || '')
    .split(',')
    .map((h) => h.trim())
    .filter(Boolean);
  if (baseUrl.includes('\\')) {
    throw new Error(`QA target rejected — URL contains a backslash: ${baseUrl}`);
  }
  let host;
  try {
    const u = new URL(baseUrl);
    if (u.protocol !== 'http:' && u.protocol !== 'https:') {
      throw new Error(`scheme must be http/https: ${baseUrl}`);
    }
    host = u.hostname;
  } catch (e) {
    throw new Error(`QA target rejected — unparseable URL: ${baseUrl} (${e.message})`);
  }
  if (!allowed.includes(host)) {
    throw new Error(
      `QA target rejected — host '${host}' not in QA_ALLOWED_HOSTS [${allowed.join(', ')}]. QA never runs against non-dev hosts.`
    );
  }
  return host;
}

async function run(job) {
  const { baseUrl, loginUserId = 1, scenario = 'unnamed', actions = [] } = job;
  assertAllowedHost(baseUrl);

  const screenDir = process.env.QA_SCREENSHOT_DIR || '/tmp/qa-screens';
  const result = { scenario, ok: true, steps: [], screenshots: [], finalUrl: null, snapshot: null, axe: [], error: null };

  const browser = await chromium.launch();
  const context = await browser.newContext({ ignoreHTTPSErrors: true }); // dev uses a self-signed cert
  const page = await context.newPage();
  const pageErrors = [];
  page.on('pageerror', (e) => pageErrors.push(String(e)));

  try {
    // Authenticate via the dev-login bypass before anything else. Use an API request
    // with Accept: application/json — the dev-login route returns JSON (no redirect)
    // in that case and sets the session cookie in the context's cookie jar (shared
    // with pages). This avoids following the post-login redirect to APP_URL, which may
    // point at a host the box isn't currently on.
    const loginResp = await context.request.get(`${baseUrl}/dev/login/${loginUserId}`, {
      headers: { Accept: 'application/json' },
      timeout: 30000,
    });
    if (!loginResp.ok()) {
      throw new Error(`dev-login failed: HTTP ${loginResp.status()} at ${baseUrl}/dev/login/${loginUserId}`);
    }

    for (const [i, action] of actions.entries()) {
      const step = { i, type: action.type, ok: true, detail: '' };
      try {
        switch (action.type) {
          case 'goto':
            await page.goto(`${baseUrl}${action.path}`, { waitUntil: 'networkidle', timeout: 30000 });
            step.detail = `status=${(await page.title()) ? 'loaded' : '?'} url=${page.url()}`;
            break;
          case 'click':
            await page.click(action.selector, { timeout: 10000 });
            break;
          case 'fill':
            await page.fill(action.selector, action.value, { timeout: 10000 });
            break;
          case 'selectOption': {
            // Drive native <select> controls. A bare string matches an option by
            // value OR visible label; {label}/{index} select explicitly.
            const target = action.label != null ? { label: action.label }
              : action.index != null ? { index: action.index }
              : action.value;
            const selected = await page.selectOption(action.selector, target, { timeout: 10000 });
            step.detail = `selected ${JSON.stringify(selected)}`;
            break;
          }
          case 'expectText': {
            const body = await page.textContent('body');
            if (!body || !body.includes(action.text)) {
              step.ok = false;
              step.detail = `expected text not found: ${JSON.stringify(action.text)}`;
              result.ok = false;
            }
            break;
          }
          case 'expectMissing': {
            const body = await page.textContent('body');
            if (body && body.includes(action.text)) {
              step.ok = false;
              step.detail = `text unexpectedly present: ${JSON.stringify(action.text)}`;
              result.ok = false;
            }
            break;
          }
          case 'snapshot':
            // page.accessibility was removed in modern Playwright; the locator
            // ariaSnapshot (YAML role tree) is the supported page-reading primitive.
            result.snapshot = await page.locator('body').ariaSnapshot();
            break;
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
          case 'screenshot': {
            const path = `${screenDir}/${scenario}-${action.name || i}.png`;
            mkdirSync(dirname(path), { recursive: true });
            await page.screenshot({ path, fullPage: true });
            result.screenshots.push(path);
            break;
          }
          default:
            step.ok = false;
            step.detail = `unknown action type: ${action.type}`;
            result.ok = false;
        }
      } catch (e) {
        step.ok = false;
        step.detail = String(e.message || e).split('\n')[0];
        result.ok = false;
      }
      result.steps.push(step);
    }

    result.finalUrl = page.url();
    result.pageErrors = pageErrors;
  } catch (e) {
    result.ok = false;
    result.error = String(e.message || e).split('\n')[0]; // harness error (distinct from a product-failure step)
    result.harnessError = true;
  } finally {
    await browser.close();
  }

  return result;
}

const input = await readStdin();
let job;
try {
  job = JSON.parse(input);
} catch (e) {
  console.log(JSON.stringify({ ok: false, harnessError: true, error: `invalid job JSON: ${e.message}` }));
  process.exit(2);
}

try {
  const result = await run(job);
  console.log(JSON.stringify(result, null, 2));
  process.exit(result.ok ? 0 : 1);
} catch (e) {
  // Guard rejections (non-dev host) and launch failures land here.
  console.log(JSON.stringify({ ok: false, harnessError: true, error: String(e.message || e).split('\n')[0] }));
  process.exit(2);
}
