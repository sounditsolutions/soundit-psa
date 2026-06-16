# Tactical RMM — Phase 4: Visibility (Insight read layer + asset panels + refresh-now)

**Status:** PLAN (pre-persona-review). Epic psa-8sgu, phase **psa-glcs**.
**Builds on:** P1 (harden) + P2 (action bus) + **P3 (remote actions, merged & LIVE on dev, 4199d1c)**.

**Architecture:** Phase 4 of `docs/superpowers/specs/2026-06-15-tactical-rmm-integration-design.md`
(§5.3 the Endpoint Insight read layer, §5.5 conventions, §6, and the binding §11 carry-forwards). P4 is
a **read / visibility** phase — no new endpoint-affecting actions, no action-bus changes. It surfaces
the latent telemetry (software, patches, checks-health) the integration already *can* fetch but never
shows, behind one normalized read layer that P5 (AI context) will also consume.

**Tech Stack:** Laravel 12 (PHP 8.3), MariaDB (prod) + SQLite `:memory:` (tests), Guzzle (the P1
injected singleton), Bootstrap 5.3 + Icons via CDN, **no build step**. Secrets via encrypted `Setting`.

## The headline concern: this is a read phase — performance + freshness honesty, not RCE

P3's risk was destructive execution; P4's risk is the *opposite shape* — turning the asset page into a
pile of slow, unbounded live API calls, or (worse) presenting stale snapshot data as if it were live.
The whole design hinges on three rules, which are the spine of this plan and the focus of the gates:

1. **Snapshot is the instant base; live is opportunistic + bounded.** The asset page renders from the
   `tactical_assets` snapshot **immediately** (as it does today — zero live calls on initial render).
   Live reads happen only (a) on an explicit **"refresh now"**, and (b) for the lazy-loaded panels
   (software/patches/checks), fetched **on demand via AJAX**, never eagerly on page load.
2. **Every live read is time-bounded and degrades to snapshot.** A live call uses a short timeout
   (~2–3 s, §11.5); on timeout / `natsdown` / offline / HTTP error it falls back to the snapshot and
   **says so in-band** — never an unbounded or silently-swallowed call (the documented `ContextBuilder`
   foot-gun). No live read ever throws to the view.
3. **`freshAsOf` never lies.** `EndpointInsight` stamps each signal as **live-refreshed vs snapshot**
   (a per-signal marker or a dual stamp), so neither the UI nor (P5) the AI can read a stale value as
   current. "Synced 18h ago" and "refreshed just now" must be visually distinct.

**P5-readiness (binding):** `TacticalInsightService` is the **single** read layer for two consumers —
the P4 UI and the P5 `TacticalContextProvider` (spec §5.3/§5.4). Design `EndpointInsight` as the
normalized shape P5 will serialize (token-budgeted, secret-scrubbed) so P5 adds a provider over it
without a second set of client calls. P4 does NOT build the AI block and does NOT yet replace the
un-timed `ContextBuilder::buildAssetSection` live-check (~lines 690–706) — that is P5's job (§5.4.5) —
but P4 establishes the bounded-read primitive it will use. (See Open Questions.)

**Reference analogs to read first (reuse, don't reinvent):**
- `app/Services/Tactical/TacticalClient.php` — the read methods already exist: `getAgent` (detail:
  total_ram, boot_time, os, disks, services), `getSoftware` (`GET software/<id>/`), `getPatches`
  (`GET winupdate/<id>/` — currently called NOWHERE), `getAgentChecks` (`GET agents/<id>/checks/`).
- `app/Services/Tactical/TacticalDeviceSyncService.php::mapAgentToTacticalAsset` — the daily list-sync;
  it persists most fields but **never writes `ram_gb`/`os_version`** (the P4 detail-read fills them).
- `app/Models/TacticalAsset.php` + its migration — the snapshot schema (`ram_gb`/`os_version` columns
  exist, unfilled; `status`, `last_seen_at`, `synced_at`, `needs_reboot`, `has_patches_pending`).
- `app/Http/Controllers/Web/AssetController.php` — `refresh()` (~306) + `quickLook()` (~1068) +
  `deviceData()` (~1182, AJAX sections network/storage/software/patches) are wired for **Ninja/Level
  only** — P4 adds the Tactical branch, mirroring `fetchLiveRmmData()`/`NinjaSyncService::syncDeviceDetail`.
- `app/Services/Triage/TriageToolExecutor.php` (~1136–1385) — already maps `getAgent`→ram_gb/uptime,
  `getSoftware`, `getAgentChecks` for the AI tools; reuse its field-mapping logic in the insight service.
- `resources/views/assets/show.blade.php` (~358–505) — the Tactical card (snapshot fields) + the
  "Open in Tactical RMM" link (psa-6h5r bug: points at `TacticalConfig::apiUrl()`, the API root).
- `app/Models/TacticalActionLog.php` — recent-action history for the insight (`where('asset_id')`).

---

## Scope

**In P4:**
- **`TacticalInsightService::forAsset(Asset, bool $live = false): EndpointInsight`** + the
  `EndpointInsight` value object — the normalized, P5-ready read layer (snapshot base + bounded live
  refresh + `freshAsOf` per-signal markers + recent actions).
- **A bounded-read primitive** — short-timeout live reads that degrade to snapshot (used by the service;
  reused by P5).
- **Detail-read populates `ram_gb`/`os_version`** — `TacticalDeviceSyncService::syncDeviceDetail(Asset)`
  (mirror Ninja/Level) reads `getAgent` and writes the two unfilled columns + refreshes status.
- **Asset-page panels** (lazy AJAX via the `deviceData` Tactical branch): **software**, **patches**,
  **checks-health**. Plus a **"refresh now"** button (manual; calls `syncDeviceDetail` + a bounded
  status/checks refresh) and a **"Recent Tactical actions"** panel (from `tactical_action_logs`).
- **psa-6h5r** — fix the "Open in Tactical" link: a separately-configured `tactical_web_url` setting
  (spec §11 MSP-agnostic), defaulting sensibly; the link points at the web dashboard, not the API root.
- Tests + INSTALL.md docs.

**Deferred OUT of P4 (explicit, tracked):**
- **The AI context block** (`TacticalContextProvider`) + replacing the un-timed `ContextBuilder` live
  check → **P5** (psa-vu2w). P4 builds the bounded read layer P5 consumes.
- **Opportunistic auto-refresh** (refresh-on-page-load / background polling) → spec §11 says ship
  manual "refresh now" first; add auto-refresh only if manual proves insufficient. Defer.
- Bulk/multi-agent anything (psa-d76b). Remote-control / MeshCentral deep-links (P6).

---

## File Structure

| File | Responsibility | Action |
|---|---|---|
| `app/Services/Tactical/TacticalInsightService.php` | `forAsset(Asset,$live)->EndpointInsight`: snapshot base + bounded live refresh + recent actions | Create |
| `app/Services/Tactical/EndpointInsight.php` | Normalized value object (P5-ready): signals + per-signal freshness + `freshAsOf` | Create |
| `app/Services/Tactical/TacticalClient.php` | A bounded-timeout read variant (per-request timeout) for the cheap live signals | Modify |
| `app/Services/Tactical/TacticalDeviceSyncService.php` | `syncDeviceDetail(Asset)`: `getAgent` → write `ram_gb`/`os_version` + refresh status/last_seen | Modify |
| `app/Http/Controllers/Web/AssetController.php` | Tactical branches in `deviceData` (software/patches/checks) + `refresh`/`quickLook` (refresh-now) | Modify |
| `resources/views/assets/show.blade.php` | Lazy panels (software/patches/checks-health), refresh-now button, recent-actions panel, freshAsOf badges; fix the Open-in-Tactical link | Modify |
| `app/Support/TacticalConfig.php` + `IntegrationsController` | `tactical_web_url` setting (read + save) | Modify |
| `docs/INSTALL.md` | Panels, refresh-now, `tactical_web_url`, ram_gb/os_version detail-read | Modify |
| `tests/Feature/Tactical/*`, `tests/Unit/Tactical/*` | Insight service, bounded-degrade, detail-read, panel endpoints, link | Create |

---

### Task 1: `EndpointInsight` value object + `TacticalInsightService` (snapshot base + recent actions)

The read-layer spine, built snapshot-first (no live calls yet — that's Task 2/3).

**Files:** Create `EndpointInsight.php`, `TacticalInsightService.php`. Tests:
`tests/Unit/Tactical/EndpointInsightTest.php`, `tests/Feature/Tactical/TacticalInsightServiceTest.php`.

- [ ] **Step 1: Failing tests** — `forAsset($asset)` (no `$live`) assembles from the `tactical_assets`
      snapshot + recent `tactical_action_logs`: status, lastSeen, uptime, needsReboot, maintenance,
      pendingPatchCount, hardware (cpu/ram/disk), recentActions (last N). Every signal is marked
      `fromSnapshot` and `freshAsOf` = the snapshot's `synced_at`. An asset with no `tacticalAsset` →
      a clear "not linked" insight (no throw).
- [ ] **Step 2: Run → fail.**
- [ ] **Step 3: Implement** the value object (readonly, P5-friendly accessors) + the snapshot assembly.
      Reuse `TriageToolExecutor`'s field mapping (ram_gb, uptime-from-boot_time) where it reads the
      detail object; here the base is the snapshot columns.
- [ ] **Step 5: Commit** `feat(tactical): EndpointInsight + insight service (snapshot base) (P4)`.

### Task 2: Bounded live refresh + `syncDeviceDetail` (ram_gb/os_version)

**Files:** Modify `TacticalClient.php` (bounded read), `TacticalDeviceSyncService.php`
(`syncDeviceDetail`). Tests: `TacticalInsightServiceTest`, `tests/Feature/Tactical/TacticalDetailSyncTest.php`.

- [ ] **Step 1: Failing tests** — a bounded live read with a fault-injecting mock: on success it
      refreshes the cheap signals (status/checks) and marks them `live` with `freshAsOf=now`; on
      timeout/ConnectException/HTTP-error it **falls back to the snapshot** and marks them
      `fromSnapshot` (NEVER throws to the caller). `syncDeviceDetail($asset)` reads `getAgent` and writes
      `ram_gb` (total_ram bytes → GB) + `os_version`, updates `status`/`last_seen_at`/`synced_at`;
      a fetch failure leaves the prior snapshot intact + returns a clear result.
- [ ] **Step 3: Implement** the short-timeout read (a per-request Guzzle `timeout` option on a dedicated
      read path, ~2–3 s — NOT the 30 s singleton default), the classify-and-degrade wrapper, and
      `syncDeviceDetail`. `forAsset($asset, live: true)` opportunistically refreshes the **cheap** signals
      only (status/checks); software/patches stay lazy (Task 3).
- [ ] **Step 5: Commit** `feat(tactical): bounded live refresh + detail-read for ram_gb/os_version (P4)`.

### Task 3: Asset-page lazy panels — software / patches / checks-health

Wire Tactical into the existing `deviceData` AJAX pattern (Ninja/Level already use it). Panels fetch
on demand (tab open / button), never on initial page render.

**Files:** Modify `AssetController::deviceData` (+ route if needed), `show.blade.php`. Tests:
`tests/Feature/Tactical/TacticalPanelsTest.php`.

- [ ] **Step 1: Failing tests** — `deviceData($asset, 'software'|'patches'|'checks')` for a
      Tactical-linked asset returns the mapped list (software: name/version/publisher; patches:
      pending list/count from `getPatches`; checks: name/status/retcode/clipped-stdout from
      `getAgentChecks`); an offline/timeout/error → a graceful `{error|degraded}` payload the JS renders
      as "couldn't refresh — showing nothing / try again", never a 500; output is length-bounded.
- [ ] **Step 3: Implement** the Tactical branch (bounded reads via Task 2) + the Blade panels
      (Bootstrap, lazy-loaded, with a freshAsOf/"live" badge). Checks-health summarizes failing checks
      prominently.
- [ ] **Step 5: Commit** `feat(tactical): asset-page software/patches/checks-health panels (P4)`.

### Task 4: "Refresh now" + status freshness UI

**Files:** Modify `AssetController::refresh`/`quickLook` (Tactical branch → `syncDeviceDetail` + bounded
status refresh), `show.blade.php` (refresh-now button + freshAsOf badge on the card). Test:
`tests/Feature/Tactical/TacticalRefreshNowTest.php`.

- [ ] **Step 1: Failing tests** — the refresh endpoint for a Tactical asset calls `syncDeviceDetail`,
      returns the new `freshAsOf` + refreshed status; on a live failure it returns the prior snapshot +
      a "couldn't reach the agent" note (200 with a degraded flag, not a 500). The card shows "Synced
      <ago>" by default and "Refreshed just now" after. **Refresh-now is a READ — it does NOT go through
      the action bus and is NOT a destructive/audited action.**
- [ ] **Step 5: Commit** `feat(tactical): refresh-now for Tactical device status (P4)`.

### Task 5: Recent Tactical actions panel

**Files:** Modify `AssetController` (load recent `tactical_action_logs` for the asset) + `show.blade.php`.
Test: `tests/Feature/Tactical/TacticalRecentActionsTest.php`.

- [ ] **Step 1: Failing tests** — the asset page surfaces the last N `tactical_action_logs` rows for the
      asset (actor, action, result_status, when), newest first; already-redacted (the rows were redacted
      at write — no re-leak); an asset with no actions → an empty-state, no error.
- [ ] **Step 5: Commit** `feat(tactical): recent Tactical actions panel on the asset page (P4)`.

### Task 6: psa-6h5r — fix "Open in Tactical RMM" link (configurable web URL)

**Files:** Modify `TacticalConfig`/`IntegrationsController` (`tactical_web_url` setting), `show.blade.php`.
Test: `tests/Feature/Tactical/TacticalWebUrlTest.php`.

- [ ] **Step 1: Failing tests** — a `tactical_web_url` setting (free-form, validated `https://`, reuse
      the SSRF-ish URL hygiene where sensible) is saved/read; the "Open in Tactical" link uses it (the
      web dashboard base), NOT `apiUrl()`. When unset, the link is hidden or falls back gracefully (no
      broken API-root link). Per spec §11: PSA must not *derive* a web URL from the API URL — it's a
      separate config; cross-domain deep-link URLs that come back from Tactical's API are used verbatim.
- [ ] **Step 5: Commit** `fix(tactical): configurable tactical_web_url for the Open-in-Tactical link (psa-6h5r, P4)`.

### Task 7: Docs

**Files:** Modify `docs/INSTALL.md` §9.

- [ ] Document the visibility panels (software/patches/checks-health), the manual refresh-now (and that
      auto-refresh is deferred), the new `tactical_web_url` setting (+ where to find the web URL), and
      that `ram_gb`/`os_version` populate on detail-read/refresh (not the daily list sync). Note the
      freshAsOf semantics (snapshot vs live). Assert the doc-negatives (no new cron unless a detail-sync
      schedule is added; confirm `.env`/README posture).
- [ ] **Commit** `docs(tactical): P4 visibility — panels, refresh-now, tactical_web_url (P4)`.

---

## Testing strategy

- Unit: `EndpointInsight` shape + per-signal freshness; the bounded-read degrade wrapper (success→live,
  timeout/offline/error→snapshot, never throws).
- Feature: `forAsset` snapshot + live paths; `syncDeviceDetail` writes ram_gb/os_version; the panel
  endpoints (software/patches/checks) incl. the **degraded** path; refresh-now; recent-actions; the
  web-url link.
- Mock the Tactical reads (MockHandler seam); the panel/refresh shapes are live-verified later.
- Full `php artisan test` + Pint + secret-guard green before push.

## Live-verify (VM 105)

- Insight `forAsset(live:true)` against the real agent: status/checks refresh works + is fast (<3 s).
- The software/patches/checks panels return real data; **confirm the response shapes** (`getSoftware`,
  `getPatches`/`winupdate`, `getAgentChecks`) match the mocks — `getPatches` has **never** been called
  in prod, so its shape is unverified (treat as merge-relevant, the P2/P3 lesson).
- `syncDeviceDetail` populates ram_gb/os_version from the live `getAgent`.
- The bounded-degrade path: simulate slowness/offline → confirm it falls back to snapshot + says so.

## Open questions for the persona review

1. **Should P4 replace the un-timed `ContextBuilder::buildAssetSection` live check now**, or strictly
   leave it to P5? (Spec assigns the replacement to P5/§5.4.5, but P4 builds the bounded primitive — is
   it worth routing the existing call through it now to kill the foot-gun a phase early?)
2. **Panels: lazy AJAX vs eager server-render.** Proposed lazy (on tab/button) to keep initial render
   snapshot-fast. Agree, or render checks-health eagerly (it's the most operationally useful)?
3. **Patches in P4:** full pending-patch list panel, or just a count + "view in Tactical"? (Spec §11 AI
   guidance says "pending-patch count not list" — that's the AI block; the UI panel could show the list.)
4. **Detail-read trigger:** ram_gb/os_version populate on refresh-now (proposed). Should there also be a
   scheduled detail-sync (a cron) for all linked agents, or is on-demand enough for P4? (Spec defers
   speculative freshness — lean on-demand.)
5. **`tactical_web_url` when unset:** hide the link, or fall back to the API root (today's broken
   behavior)? Proposed: hide (no broken link).
6. **Is "refresh now" correctly a non-audited read?** It changes no endpoint state (it reads + updates
   the local snapshot). Proposed: not bus-routed, not audited. Agree?

---

## Persona-review amendments (binding) — 2026-06-16

5-reviewer persona panel (Senior Dev+Critic, AI Expert, Staff+Solo-Owner, ITIL+MSP-Ops, PM+Docs+Security)
per `docs/REVIEW_PERSONAS.md`. **Architecture + freshness spine APPROVED** (snapshot-instant base, bounded
degrade, freshAsOf honesty); all five returned REVISE on specifics, converging hard on a few themes. All
binding before/at build. The reviewers verified the plan's load-bearing claims against the live code.

### A. `EndpointInsight` is the P5-serialization CONTRACT, not a UI dump (AI Expert BLOCKER, §5.3 "no duplicated client calls")
Rewrite Task 1's shape as the explicit thing P5's `TacticalContextProvider` serializes WITHOUT re-fetching:
- **`failingChecks: FailingCheck[]`** — each `{name, status, retcode, stdout}` with **RAW (un-clipped)
  stdout** (P5 clips to its own ~200-char budget; the UI clips for display — do NOT pre-clip in the value
  object).
- **Deterministic flags computed in the service (§11.4)** — `needsReboot`, **`lowDisk`** (defined %/GB
  threshold), **`longOffline`** (defined duration), **`stale`** (freshAsOf older than the live TTL). The
  model must never invent thresholds.
- **`userLoggedIn: bool`** — NOT the raw `logged_in_username` (§11.6 PII; `redact()` won't strip it). If the
  raw username is kept at all, it lives in a field the P5 serializer is structurally not wired to read.
- **Per-section AVAILABILITY state** (`enum: Live | Snapshot | Unavailable`) per signal-group — distinct from
  freshness. "couldn't fetch checks" (Unavailable) must NOT read as "0 failing checks" (clean) (§11.7).
- **`openAlerts` (count + small list)** from `Alert::where('asset_id',$id)->open()` — a **local-DB read, zero
  live cost, always available** (spec §5.3 lists open alerts; the plan omitted it). 
- `pendingPatchCount: int` is the AI-facing member (§11.3 count-not-list); any full patch list for the UI is a
  separately-named member P5 does not serialize.
- Plain-text-friendly accessors (so P5 redacts **flattened plain text**, never `json_encode` — §11.1 gotcha);
  secret-bearing free-text (check stdout, software/hostname) are plain-string members.
- Test: each P5-essential field present; an offline-checks fetch → `Unavailable`, never empty-clean.

### B. Eager at-a-glance health on the CARD; detail panels stay lazy (ITIL BLOCKER + Staff MAJOR; resolves OQ2)
- Render an **eager health-summary line on the Tactical card** (Task 4, not a lazy tab): **failing-checks
  count · open-alerts count · pending-patches · overdue/last-seen** — all snapshot/local-DB derived (zero
  live cost). The detailed checks/software/patches panels stay **lazy** (rule 1 preserved).
- **`openAlerts`** and `overdue`/`last_seen` and `has_patches_pending` are already cheap (local DB / snapshot).
- **Failing-checks count needs persistence** → **one small additive migration** (the ONLY P4 schema change;
  changes the plan's earlier "no schema" note): add nullable `checks_failing` + `checks_total` to
  `tactical_assets`, populated by the daily list-sync **if** the agent-list payload carries the checks
  summary (verify during build — Tactical's agent table serializer typically includes a `checks` count dict),
  AND by `syncDeviceDetail`/refresh-now. If the list payload lacks it, the eager count populates on
  refresh-now only (blank until first refresh) — degraded, no per-agent daily fan-out; note which at build.
- Tests: the card renders the eager health line from the snapshot with zero live calls; a stale/overdue
  state is visible.

### C. ONE generic bounded-read primitive — per-request timeout, not a second client (Dev/Critic BLOCKER + AI MAJOR)
- **Extend `TacticalClient::get()` (and the read methods) with an optional per-request `timeout`/`options`**,
  merged into the Guzzle call — **mirroring the existing `NinjaClient::getDevice(timeout:)` precedent**. Do
  NOT build a separate client (it would duplicate the constructor's `allow_redirects=false` exfil guard +
  encrypted-key + singleton wiring). The 30s default stays for the action bus's NATS-blocking writes; only
  reads get the ~2–3s override. The injected-`?Client` test seam stays intact.
- The **degrade classifier lives in `TacticalInsightService`** (not in `get()` — the action bus depends on
  `get()`/`post()` throwing on non-2xx). It's a **generic wrapper**: takes a read closure + a snapshot
  fallback, catches `TacticalClientException`/`\Throwable`, logs at debug (records offline-vs-error for
  live-verify), returns `{value, state: Live|Snapshot|Unavailable}`. Signal-agnostic — Task 3's panel reads
  (software/patches/checks) go through the SAME wrapper (just non-eagerly). One bounded-read code path,
  reused by panels + P5.
- Tests: a thrown read → snapshot-marked insight, never a propagated throw; the wrapper degrades identically
  regardless of which read closure; **assert the ~2–3s timeout option is set on the request (not the 30s
  default)** so a refactor can't silently regress to 30s.

### D. Kill the `ContextBuilder` foot-gun IN P4 (AI + Security BLOCKER; resolves OQ1)
`ContextBuilder::buildAssetSection` (~690–706) makes an **un-timed (30s), exception-swallowed**
`getAgentChecks()` **inside `foreach ($ticket->assets …)`** (N serial blocking calls on the AI triage hot
path) AND injects ~150 chars of check **stdout unredacted** into the prompt. Four reviewers: fix it now.
- **In P4**, route that existing call through the new bounded primitive (bound + classify-degrade, no silent
  swallow) and **redact the stdout** it injects (`WikiRedactor::redact()`), **keeping its output shape
  identical** (so AI behavior is unchanged except it no longer hangs/leaks). This is foot-gun removal, NOT the
  AI block — the full token-budgeted/injection-fenced `TacticalContextProvider` + the inline-summary
  *replacement* remain P5 (§5.4.5). Resolves the Dev/Critic concern (untested AI-path behavior) — shape is
  preserved + a fault-injection test added.
- Test: a slow/offline Tactical mock during `buildAssetSection` returns within the bounded timeout, never
  throws; a planted secret in check stdout is redacted before it reaches the prompt string.

### E. Extract the shared field mappers — no copy-paste drift (Dev/Critic + AI MAJOR)
`total_ram`→GB, `boot_time`→uptime, and the checks-summary logic are currently inline-duplicated across
`TriageToolExecutor` (×several), `quickLook`. **Extract to a shared, tested mapper** (e.g.
`TacticalFieldMap` static helpers) consumed by `TriageToolExecutor` + `TacticalInsightService` +
`syncDeviceDetail`, so the AI sees ONE `ram_gb`/check-status (no divergence between a tool's value and the
context block's). Keep the `TriageToolExecutor` refactor behavior-preserving (its triage tests stay green).
`EndpointInsight` stores **raw/un-pre-clipped** values (the tool's alphabetical-first-50 software slice is
NOT a relevance ranking — don't inherit it; P5 defines §11.3 top-N relevance).

### F. `getPatches`/`winupdate` shape is UNVERIFIED — count-first panel, degrade on mismatch (Dev/Critic MAJOR + ITIL/Staff/PM; resolves OQ3)
`getPatches` (`winupdate/<id>/`) has **never been called in prod** — its shape is a guess; do NOT analogize
from Ninja's `{status,installDate}` shape. Mock from the **Tactical source** (`winupdate` serializer). The
patches panel **leads with a compliance summary** (pending count, + critical/important severity rollup if the
shape exposes it, + needs-reboot tie-in) and a **view-in-Tactical** link; the full per-patch list is
**secondary / opt-in** ("show all"). On shape-mismatch (expected fields absent) → "couldn't read patch
detail", NEVER an empty list rendered as "no patches pending". **Add `getPatches` shape-confirmation to the
merge-blocking live-verify checklist** (the P2/P3 reboot/cmd-body lesson).

### G. Partial-insight honesty in the UI + redact check stdout (Dev/Critic + Security MAJOR + Staff MINOR)
- Each panel renders **three distinct states**: (a) loaded-with-data, (b) **loaded-and-genuinely-empty**
  ("✓ All checks passing" / "No pending updates" — positive copy), (c) **could-not-load** (degraded "couldn't
  reach the agent — try again"). (b) and (c) MUST be visually + textually unambiguous — a degraded checks
  panel must never look like "all passing"; a degraded patches panel never like "fully patched".
- **Redact check `stdout` before rendering** to the panel (`WikiRedactor::redact()`) in addition to
  length-clipping — a check echoing a password would otherwise render it verbatim in the browser/screen-share.
  Test: a planted secret in a check's stdout is redacted in the panel response.

### H. freshAsOf prominence — adjacent to status + escalate a stale "online" (Staff MAJOR)
The current card shows the status badge at the top and "synced 18h ago" buried at the bottom — a confident
green "Online" that's really 18h stale is the dangerous misread. Put the **freshAsOf badge immediately
adjacent to the status badge** (read as one unit: `[Online] · synced 18h ago`); when `synced_at` is beyond a
staleness threshold for an "online" claim (e.g. >60 min), render the age in a **warning treatment** (amber),
not muted grey. After refresh-now, flip to `Refreshed just now` in the same spot. Acceptance test asserts the
adjacency + the warning-on-stale-online.

### I. Panel placement — co-locate ALL Tactical panels under the Tactical card (Staff MAJOR)
Do NOT scatter Tactical software/patches into the page-top Ninja/Level `deviceData` tabs. The `deviceData`
endpoint can be **shared** (add the Tactical branch — see below), but the Tactical panels **render in the
Tactical card region** (collapsible accordions or a tab-strip scoped to that card), so all Tactical telemetry
is one contiguous place (the migrating owner shouldn't hunt between a Tactical card and Tactical-data-in-Ninja
-tabs). `deviceData`: **add `'checks'` to `$allowedSections`** and **add an `if ($asset->tacticalAsset &&
TacticalConfig::isConfigured())` branch** (ordering relative to Ninja/Level stated explicitly; reuse the
existing `{error:…}` degrade payload the JS already renders). No new route (the `assets.deviceData` GET +
`assets.refresh` routes exist).

### J. Refresh-now = in-place AJAX, not a page-reload (Staff MAJOR + Dev/Critic MINOR; resolves OQ6)
Refresh-now is a **dedicated AJAX POST returning JSON** `{status, freshAsOf, degraded, message}` (mirror the
maintenance-toggle JS already in the card), updating the status + freshness **in place** (no full-page
reload, no lost scroll on mobile). It does **NOT** reuse `refresh()`'s redirect path or `quickLook()`'s 60s
cache (cached refresh-now would serve stale data — rule 3). In-button spinner that re-enables on
completion/failure (the bounded ~2–3s timeout guarantees no infinite spin — assert). Degrade copy adjacent to
status: "Couldn't reach the agent — showing last sync." **It is a READ — not bus-routed, not audited** (it
mutates only the local snapshot, not the CI), but **POST + CSRF + same-auth-as-page** (confirm in the test).

### K. Recent-actions panel = ITIL CHANGE HISTORY, not an activity log (ITIL BLOCKER)
- **Link each action row to its `ticket_id`** (the column + `ticket()` relation exist) so endpoint changes
  tie to the incident they were performed under — "Reboot · J.Smith · under #1423 · 2h ago". A row without a
  ticket renders cleanly (out-of-band action).
- **Distinguish action outcomes** with distinct badges — ok (success) / offline (warning, "no-op, agent
  unreachable") / error (danger) / rejected|denied|blocked (secondary). "a reboot **succeeded**" vs "was a
  no-op" vs "was blocked" are different facts. Reuse `TacticalAsset::statusBadgeClass()`'s color vocabulary.
- **Cap N (~10) newest-first + a "view all" affordance** (consistent with the existing Alerts card pattern);
  rows already redacted at write (no re-leak — assert); plain-English (actor/action/outcome/relative-time).

### L. `tactical_web_url` link fix (psa-6h5r; Security/Docs MINOR; resolves OQ5)
- A new **DB-backed `Setting`** (plain, not encrypted — not a secret), saved via the existing
  `IntegrationsController::updateTactical` (extend its `$validated` array — ~3 lines, not new save logic).
- Validate **`https://` + parseable host**; reject `javascript:`/non-URL. Reusing `SafeTacticalUrl`/
  `SafeUrlInspector` is acceptable for consistency BUT its error copy says "API URL" — wrap/parameterize the
  message so it labels the web-URL field. (Lower threat than `api_url`: it's a browser link target, no
  server-side fetch / no key exfil — so full SSRF private-range blocking is optional, https+host is the bar.)
  Per spec §11: **never derive it from `api_url`** (separate config).
- The "Open in Tactical" link uses it (the dashboard base); **hidden when unset** (OQ5 — never fall back to
  the API root, today's bug). Render with `rel="noopener noreferrer"` (+ existing `target="_blank"`). A muted
  Settings hint ("set a web URL to enable the link") is a nice-to-have.

### M. Docs Task 7 — concrete INSTALL §9 checklist (Docs MAJOR)
Named edits to `docs/INSTALL.md` §9 (~526–576): add the visibility-panels paragraph (lazy, snapshot-first,
freshAsOf semantics); document **refresh-now** as a manual non-audited read + state **auto-refresh is
deferred**; document **`tactical_web_url`** (optional, the *dashboard* base distinct from the API URL,
hidden-when-unset, never-derived) and **extend the existing line ~575 "all Tactical config is DB-backed"
inventory** to include it; note **ram_gb/os_version populate on detail-read/refresh, not the daily list
sync**; **update line ~565** (psa-6h5r now shipped). **Assert the doc-negatives:** no new cron (OQ4 =
on-demand → psa-0npd is the future cron), no `.env.example` change (DB-backed), no README route-table change
(house style omits asset AJAX/POST sub-actions; no new routes — existing controllers branch). NIT: note the
software/patches/checks shapes aren't yet in the `/api/schema/` drift fixture.

### N. Open Questions — resolved (binding)
1. **ContextBuilder foot-gun → bound it in P4** (D). Full provider/replacement → P5.
2. **Panels → eager health *summary* on the card + lazy *detail* panels** (B).
3. **Patches → count/compliance-summary leads; full list secondary/opt-in** (F).
4. **Detail-sync → on-demand only in P4; scheduled cron deferred → [[psa-0npd]]** (the fleet-compliance
   prerequisite).
5. **`tactical_web_url` unset → hide the link**, never fall back to the API root (L).
6. **Refresh-now → non-bus, non-audited read; POST + CSRF + auth-gated** (J).

### O. Task-list deltas
- **One small additive migration** (B: `checks_failing`/`checks_total` on `tactical_assets`) — the only P4
  schema change. Plus the `tactical_web_url` `Setting` (no schema).
- **Renumber task steps contiguously** (the template skips Step 4 — the build agent follows steps literally).
- **Build in listed order** (Tasks 3/4/5/6 all touch `show.blade.php`/`AssetController` — sequence to avoid
  intra-branch churn; don't parallelize).
- **Task 1** gains the §A EndpointInsight contract + §B openAlerts + the eager health signals.
- **Task 2** gains the §C generic bounded wrapper + §D ContextBuilder fix + §E shared mapper extraction +
  §B the checks-summary sync.
- **Task 3** gains §F count-first patches + §G three-state panels & stdout redaction + §I card-co-located
  placement + the `deviceData` `checks`/Tactical-branch edits.
- **Task 4** gains §H freshAsOf-adjacent-to-status + §J in-place AJAX refresh + the eager health line.
- **Task 5** gains §K change-history (ticket link, outcomes, cap+view-all).
- **Task 6** gains §L validation + `rel=noopener`.
- **Task 7** = §M concrete checklist.
- **Merge-blocking live-verify:** `getPatches`/`winupdate` shape (F); +375px mobile panel layout; + dump
  `EndpointInsight::forAsset(live:true)` for a real agent and confirm every P5-essential field is present.
