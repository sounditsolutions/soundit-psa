# Tactical RMM P6 — Remote Control (MeshCentral deep-links) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
>
> **This plan is PERSONA-GATED (5 reviewers, 2026-06-17) and owner-decided.** Amendments from the gate are folded in below; the gate's findings + owner sign-offs are in "Gate outcomes" at the bottom. Build to the gates.

**Goal:** Let a technician open a MeshCentral **remote-control / terminal / file** session for a Tactical-linked asset by clicking a button that mints a short-lived deep-link at click-time and audits every attempt.

**Architecture:** A new `TacticalClient::getMeshCentralLinks($agentId)` calls Tactical's `GET agents/<id>/meshcentral/` (inheriting X-API-KEY auth + request-time SSRF pin, no caching). A thin controller action (modeled on P4's `refreshTactical` live-read path — **NOT** the destructive action bus) fetches links fresh on each click, writes an immutable `tactical_action_logs` row for **every outcome** (success AND failure — no URL/token ever logged), and returns the requested URL as JSON with `Cache-Control: no-store`. Browser JS opens the URL **verbatim** (popup-safe) — PSA never constructs, stores, or server-fetches the deep-link. Throttled route, UI on the Tactical actions card.

**Tech Stack:** PHP 8 + Laravel + PHPUnit (sqlite `:memory:`). Reuses `TacticalClient` (P1), `TacticalActionLog` immutable audit (P2), `TacticalConfig`, `SafeTacticalWebUrl` (P4), the asset↔`tacticalAsset` relation (P4). No new dependencies. No schema changes.

**Spec:** `docs/superpowers/specs/2026-06-15-tactical-rmm-integration-design.md` §"P6 — Remote control" (lines ~264, 284–285, 317, 421, 435–439) + §11.

## Global Constraints (binding — every task includes these)

- **G1 — verbatim, never-cached, never-constructed, never-logged URLs.** control/terminal/file URLs come back FROM Tactical and are opened **exactly as returned**. Never build from parts, never cache (no `Cache::remember`/DB column/session), never log — not in the audit row, not in `message`/`output`/`target_label`, not via a re-`report()`ed exception. Mint fresh every click (short-lived `login=<token>` in the URL).
- **G2 — audit EVERY outcome, immutably, URL-free.** Each attempt — success, not-linked, invalid-type, Tactical-unreachable, invalid-returned-URL — writes one `TacticalActionLog::create([...])` row (insert is allowed; append-only guards fire only on update/delete). `action_key = tactical.remote_control`; `params` records only `{link_type}`; `result_status` ∈ `ok|offline|error`. **§11.2 is binding: audit-all, including denied/failed.** (Unauthenticated hits are rejected by `auth` before the controller — that's the app log, not here; state so in docs.)
- **G3 — net-new; do NOT touch `MeshClient`.** `app/Services/Mesh/MeshClient.php` is the **email-security** SaaS client — unrelated to MeshCentral (the bead's "reuse MeshClient" is a name-collision error, confirmed by the gate). P6 adds a `TacticalClient` method only.
- **G4 — bounded, SSRF-safe live call.** Through `TacticalClient` (X-API-KEY + `tactical_ssrf_pin` inherited) with a short timeout; never throws into the surface (catch `TacticalClientException`). The caught exception is swallowed into a generic response and **never `report()`ed** (its `responseBody` may carry a token).
- **G5 — auth + asset-link gate; ONE-CLICK, NO CONFIRM (owner sign-off).** Same single-role-tier as existing tactical actions: `middleware('auth')` + linked `tacticalAsset.agent_id` (422 otherwise) + CSRF (web POST) + a route `throttle`. **OWNER DECISION (Charlie, 2026-06-17): remote control opens on a single click with NO per-action confirm gate** — explicitly accepting LOWER assurance than P3's RCE (which has a typed-hostname + confirm-token gate). This is an informed sign-off; do NOT describe P6 as "P3 parity." (Capability/role gating is deferred to the `psa-hbh` seam — when it lands, this controller must grow the check too, since it bypasses the bus's authorize stage.)
- **G6 — validate the returned URL (do NOT trust verbatim).** Before opening, validate the returned URL with **`SafeTacticalWebUrl`** (https + parseable host) — REUSE the existing rule, do not create a near-duplicate. The gate was emphatic: unlike the public installer URL, this opens in the technician's *authenticated* browser, so a well-formed-https check is proportionate (not theater). A malformed/non-https value → audited error, not opened. *(Host-pinning to a configured mesh host is a noted future hardening, deferred — `mesh_site` isn't currently known to PSA.)*
- **G7 — Cache-Control: no-store** on the token-bearing JSON response (proxy/bfcache must not retain a live token).
- **G8 — no-bus direct audit is intentional** (read-class action). Add a code comment: params are a fixed enum (nothing to redact); if free-text params are ever added here, route through `ActionRedactor` (the bus does this automatically; this path does not).

**SHAPE — CONFIRMED FROM SOURCE (gate, The Critic):** amidaware/tacticalrmm `agents/urls.py` → `AgentMeshCentral` GET returns `{hostname, control, terminal, file, status, client, site}` where control/terminal/file are absolute `https://` URLs (built from the Tactical server's `mesh_site` + a `login=<token>` query param). So: GET ✓, keys `control|terminal|file` ✓, absolute https values ✓. The `$links[$type]` access + https validation are correctly shaped. **Remaining live-verify (Task 4, not a shape-blocker):** confirm dev Tactical has `mesh_site` configured + VM 105 has a mesh node, and capture a real response as a checked-in fixture. Handle the real-world "MeshCentral not wired up / agent has no node" case (a 200 with empty/unusable URL) as a clear 422, not a generic 502.

**Consumed interfaces (exist):** `TacticalClient::get(string,?int):array` (throws `TacticalClientException`); `TacticalActionLog::create([...])` (fillable incl. actor_id, actor_label, action_key, agent_id, asset_id, ticket_id, target_label, params, result_status, retcode, output, message, correlation_id; append-only insert OK); `TacticalConfig::isConfigured()`; `Asset->tacticalAsset` (->agent_id,->hostname); `SafeTacticalWebUrl` rule; controller model `AssetController@refreshTactical` (live read, JSON, not bus-routed) + `runTacticalCommand` (for the optional `ticket_id` validation pattern).

---

## File Structure

| File | Change | Responsibility |
|------|--------|----------------|
| `app/Services/Tactical/TacticalClient.php` | modify | add `getMeshCentralLinks(string $agentId, ?int $timeout = null): array` |
| `app/Http/Controllers/Web/AssetController.php` | modify | add `openTacticalMeshCentral(Request, Asset)` — fetch at click-time, validate (G6), audit every outcome (G2), no-store (G7) |
| `routes/web.php` | modify (~458) | `POST /assets/{asset}/tactical/meshcentral` (throttled) → `assets.tactical-meshcentral` |
| `tests/Feature/Tactical/TacticalMeshCentralTest.php` | create | client method + controller (success/all-failure-paths audited, URL never logged, no-store header, no-cache) |
| `resources/views/assets/show.blade.php` | modify (~806–818) | "Remote access" row: Control/Terminal/Files buttons → popup-safe open + loading state + actionable errors |
| `docs/INSTALL.md` | modify (§9) | document remote-control + verbatim/never-cache posture + no-session-duration limitation + audit-volume note |

---

## Task 1: `TacticalClient::getMeshCentralLinks` (G1/G4)

**Files:** Modify `app/Services/Tactical/TacticalClient.php`; Test `tests/Feature/Tactical/TacticalMeshCentralTest.php`.

**Interfaces:** Produces `getMeshCentralLinks(string $agentId, ?int $timeout = null): array` — returns Tactical's decoded JSON (`{hostname,control,terminal,file,status,client,site}`); throws `TacticalClientException`; no caching. Consumes existing `get()`.

- [ ] **Step 1: Failing test** (mirror the repo's existing TacticalClient MockHandler+history test helper):

```php
public function test_getMeshCentralLinks_hits_the_agent_meshcentral_endpoint(): void
{
    $history = [];
    $client = $this->tacticalClient([
        new \GuzzleHttp\Psr7\Response(200, [], json_encode([
            'hostname'=>'BOX','control'=>'https://mesh.example.com/?login=ctrl',
            'terminal'=>'https://mesh.example.com/?login=term','file'=>'https://mesh.example.com/?login=file',
        ])),
    ], $history);
    $links = $client->getMeshCentralLinks('AGENT-1');
    $this->assertSame('https://mesh.example.com/?login=ctrl', $links['control']);
    $this->assertStringContainsString('agents/AGENT-1/meshcentral', (string) $history[0]['request']->getUri());
}
```

- [ ] **Step 2: Run — verify FAIL** (method not found).
- [ ] **Step 3: Implement** (mirror `getAgent`):

```php
/**
 * Mint MeshCentral remote-control deep-links for an agent.
 * Tokens are short-lived — callers MUST fetch at click-time and NEVER cache or log the URLs.
 */
public function getMeshCentralLinks(string $agentId, ?int $timeout = null): array
{
    return $this->get("agents/{$agentId}/meshcentral/", $timeout);
}
```

- [ ] **Step 4: Run — verify PASS.**
- [ ] **Step 5: Commit** — `git add app/Services/Tactical/TacticalClient.php tests/Feature/Tactical/TacticalMeshCentralTest.php && git commit -m "feat(tactical-p6): TacticalClient::getMeshCentralLinks (click-time mint, no cache)"`

---

## Task 2: Controller action + throttled route + audit-every-outcome (G1/G2/G4/G5/G6/G7/G8)

**Files:** Modify `app/Http/Controllers/Web/AssetController.php`, `routes/web.php`; Test (add to) `TacticalMeshCentralTest.php`.

- [ ] **Step 1: Failing tests** — authed user + Tactical-linked asset:
  - `type=control` → 200 `{url}` + header `Cache-Control: no-store` + exactly one `tactical_action_logs` row (`action_key=tactical.remote_control`, `params.link_type=control`, `result_status=ok`) with **no URL/token** anywhere (`assertStringNotContainsString('login=', json_encode($log->getAttributes()))` AND host absent);
  - Tactical throws → 502 + an audited `result_status=error` row (URL-free);
  - returned URL not https → 502 + audited `result_status=error` row;
  - non-linked asset → 422 (audit optional — no agent_id to anchor);
  - invalid `type` → 422;
  - optional `ticket_id` is recorded on the row when supplied.

```php
public function test_open_meshcentral_returns_url_audits_and_sets_no_store(): void
{
    [$user,$asset] = $this->authedUserWithTacticalAsset(agentId:'AGENT-1');
    $this->bindTacticalClientReturning(['control'=>'https://mesh.example.com/?login=tok']);
    $res = $this->actingAs($user)->postJson("/assets/{$asset->id}/tactical/meshcentral", ['type'=>'control']);
    $res->assertOk()->assertJsonPath('url','https://mesh.example.com/?login=tok')
        ->assertHeader('Cache-Control','no-store, private');   // Laravel appends 'private'
    $log = \App\Models\TacticalActionLog::sole();
    $this->assertSame('tactical.remote_control',$log->action_key);
    $this->assertSame('control',$log->params['link_type']);
    $this->assertStringNotContainsString('login=', json_encode($log->getAttributes()));
    $this->assertStringNotContainsString('mesh.example.com', json_encode($log->getAttributes()));
}

public function test_open_meshcentral_audits_failures_without_logging_a_url(): void
{
    [$user,$asset] = $this->authedUserWithTacticalAsset(agentId:'AGENT-1');
    $this->bindTacticalClientThrowing();   // TacticalClientException
    $res = $this->actingAs($user)->postJson("/assets/{$asset->id}/tactical/meshcentral", ['type'=>'terminal']);
    $res->assertStatus(502);
    $this->assertSame('error', \App\Models\TacticalActionLog::sole()->result_status);
}
```

- [ ] **Step 2: Run — verify FAIL.**
- [ ] **Step 3: Route (throttled)** after the other tactical routes (~458):

```php
Route::post('/assets/{asset}/tactical/meshcentral', [AssetController::class, 'openTacticalMeshCentral'])
    ->middleware('throttle:30,1')->name('assets.tactical-meshcentral');
```

- [ ] **Step 4: Implement** (model on `refreshTactical`; audit a private helper for every path):

```php
public function openTacticalMeshCentral(Request $request, Asset $asset)
{
    $data = $request->validate([
        'type' => 'required|in:control,terminal,file',
        'ticket_id' => 'nullable|integer|exists:tickets,id',
    ]);
    $asset->load('tacticalAsset');
    $agentId = $asset->tacticalAsset->agent_id ?? null;
    if (! $agentId) {
        return response()->json(['error' => 'This device is not linked to a Tactical agent.'], 422);
    }

    // G2 helper: one URL-free audit row for any outcome.
    $audit = function (string $status) use ($request, $asset, $agentId, $data) {
        \App\Models\TacticalActionLog::create([
            'actor_id' => $request->user()?->id,
            'actor_label' => $request->user()?->email ?? 'unknown',
            'action_key' => 'tactical.remote_control',          // G8: fixed enum params — if you add free-text, route via ActionRedactor
            'agent_id' => $agentId,
            'asset_id' => $asset->id,
            'ticket_id' => $data['ticket_id'] ?? null,
            'target_label' => $asset->tacticalAsset->hostname ?? $asset->hostname ?? 'unknown',
            'params' => ['link_type' => $data['type']],         // G1/G2: never the URL
            'result_status' => $status,
            'message' => $status === 'ok' ? 'Remote session opened.' : 'Remote session open failed.',
            'correlation_id' => (string) \Illuminate\Support\Str::uuid(),
        ]);
    };

    try {
        $links = app(\App\Services\Tactical\TacticalClient::class)->getMeshCentralLinks($agentId);
    } catch (\App\Services\Tactical\TacticalClientException $e) {
        $audit('error');   // G4: do NOT report()/rethrow — $e->responseBody may carry a token
        return response()->json(['error' => 'Could not reach Tactical to open a remote session. Check that Tactical RMM is reachable and try again.'], 502);
    }

    $url = $links[$data['type']] ?? null;
    $valid = $url !== null && \Illuminate\Support\Facades\Validator::make(['u'=>$url], ['u'=>[new \App\Rules\SafeTacticalWebUrl]])->passes();
    if (! $valid) {
        $audit('error');
        // The realistic cause is MeshCentral not configured for this device, not a transport failure.
        return response()->json(['error' => "MeshCentral isn't available for this device. Confirm the RMM server's MeshCentral integration is configured and this agent has a mesh connection."], 422);
    }

    $audit('ok');
    return response()->json(['url' => $url])->header('Cache-Control', 'no-store');   // G1 verbatim, G7 no-store
}
```

- [ ] **Step 5: Run — verify PASS** + `php artisan test tests/Feature/Tactical`.
- [ ] **Step 6: Commit** — `git add app/Http/Controllers/Web/AssetController.php routes/web.php tests/Feature/Tactical/TacticalMeshCentralTest.php && git commit -m "feat(tactical-p6): meshcentral open action — throttled route, audit-every-outcome, no-store, https-validated (G1-G8)"`

---

## Task 3: UI — popup-safe remote-access buttons (G1; Staff-User gate fixes)

**Files:** Modify `resources/views/assets/show.blade.php` (a "Remote access" row in the Tactical actions card, after the Recover row ~806–818).

- [ ] **Step 1:** Three buttons (Control/Terminal/Files, **distinct icons**), each: open a blank tab **synchronously on click** (popup-safe), show a **loading state**, POST for the URL, then set the tab's location to the returned URL verbatim — or close it and show an **actionable** inline error. (No automated test — Blade/JS; verified live in Task 4.)

```blade
<div class="mt-3">
  <label class="form-label small text-muted d-block">Remote access (MeshCentral)</label>
  <div class="btn-group btn-group-sm" role="group">
    <button type="button" class="btn btn-outline-secondary" data-mesh-type="control"><i class="bi bi-display me-1"></i>Control</button>
    <button type="button" class="btn btn-outline-secondary" data-mesh-type="terminal"><i class="bi bi-terminal me-1"></i>Terminal</button>
    <button type="button" class="btn btn-outline-secondary" data-mesh-type="file"><i class="bi bi-folder me-1"></i>Files</button>
  </div>
  <div class="text-danger small mt-1 d-none" data-mesh-error></div>
</div>
<script>
document.querySelectorAll('[data-mesh-type]').forEach(btn => btn.addEventListener('click', async () => {
  const err = document.querySelector('[data-mesh-error]'); err.classList.add('d-none');
  const tab = window.open('', '_blank');                          // open SYNCHRONOUSLY in the gesture (popup-safe)
  const label = btn.innerHTML; btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
  try {
    const r = await fetch(@json(route('assets.tactical-meshcentral', $asset)), {
      method:'POST',
      headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content},
      body: JSON.stringify({type: btn.dataset.meshType}),
    });
    const j = await r.json();
    if (!r.ok) throw new Error(j.error || 'Could not open remote session.');
    if (tab) tab.location = j.url; else window.location = j.url;   // open verbatim (G1)
  } catch (e) {
    if (tab) tab.close();
    err.textContent = e.message; err.classList.remove('d-none');
  } finally { btn.disabled = false; btn.innerHTML = label; }
}));
</script>
```

- [ ] **Step 2: Commit** — `git add resources/views/assets/show.blade.php && git commit -m "feat(tactical-p6): remote-access buttons — popup-safe open, loading state, actionable errors (G1)"`

---

## Task 4: Docs + live-verify + limitations

**Files:** Modify `docs/INSTALL.md` (§9 Tactical).

- [ ] **Step 1:** Document the feature: click-time mint, never cached, URLs opened verbatim, **every attempt audited (success + failure), URL/token never stored**, requires Tactical configured + a linked agent with a mesh node. State the **no-session-duration limitation** (deep-links are fire-and-forget; PSA records "opened", not duration/close) and note remote-control opens are now the **highest-volume writer** to `tactical_action_logs` (retention is a future follow-up). Note unauthenticated requests are rejected by `auth` (app log), and record the **one-click, no-confirm owner sign-off** (lower assurance than P3 RCE, by design).
- [ ] **Step 2: LIVE-VERIFY (not a shape-blocker — shape is source-confirmed).** Against dev Tactical (rmm-api.dev.soundpsa.com) + VM 105: confirm `mesh_site` is configured and the agent returns usable `control/terminal/file` https URLs; capture a sanitized real response as a checked-in test fixture. If MeshCentral is NOT configured on dev Tactical, confirm the controller's 422 path fires with the actionable copy. Record findings in the PR.
- [ ] **Step 3: Commit** (explicit staging if `.beads` drift present) — `git commit -m "docs(tactical-p6): remote-control posture, limitations, live-verified meshcentral shape"`

---

## Self-Review

**Spec coverage:** click-time mint (T1) · never-cache/verbatim/never-log (T1,T2 G1/G7) · audit EVERY outcome URL-free (T2 G2) · control/terminal/file (T2 type, T3 buttons) · SSRF-safe bounded call (T1 G4) · validate-returned-URL via SafeTacticalWebUrl (T2 G6) · one-click no-confirm + throttle + auth/link gate (T2 G5) · UI popup-safe (T3) · docs + live-verify (T4). ✓

**Placeholder scan:** all code present; the only deferred item is the Task-4 live-verify (mesh_site/node confirmation + fixture) — flagged, not vague.

**Type consistency:** `getMeshCentralLinks(string,?int):array` (T1)→T2; `SafeTacticalWebUrl` reused (no new rule); `action_key=tactical.remote_control`, `params.link_type`, `result_status∈{ok,error}` consistent T2↔tests; route `assets.tactical-meshcentral` T2↔T3.

## Gate outcomes (persona review 2026-06-17 + owner decisions)
- **Verdicts:** Senior Dev APPROVE · Critic APPROVE · Security/Staff-User/ITIL REVISE → all REVISE points folded in above.
- **Confirmed from source:** the meshcentral endpoint shape (Critic) — risk retired.
- **Owner decisions (Charlie):** (auth) **one-click, no confirm — informed sign-off**, lower assurance than P3 RCE accepted; (G6) keep https validation, reuse SafeTacticalWebUrl; (audit) **audit failures too**; (audit key) one key + `link_type`; (call shape) POST per type; (UI) actions-card row. Brain: [[2026-06-17 tactical-p6-remote-control-gate]].
- **Folded amendments:** audit-all-outcomes (G2), no-store (G7), throttle, popup-safe open + loading + icons + actionable errors (T3), reuse SafeTacticalWebUrl (G6), optional ticket_id, no-`report()` of token-bearing exception (G4), 422-not-502 for "no mesh link", token-absence tests, document no-duration + audit-volume.
- **Deferred (noted):** host-pin SafeMeshDeepLink to a configured mesh host (no `mesh_site` known to PSA yet); capability/role gating (psa-hbh seam — this controller must grow it when that lands); `tactical_action_logs` retention policy.
