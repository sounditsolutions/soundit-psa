# Tactical RMM P7 — Onboarding "Hum" Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development. Steps use checkbox (`- [ ]`) syntax.
>
> **PERSONA-GATED (5 reviewers, 2026-06-17) + owner-decided.** The gate caught real Criticals (a flapping open/close loop; a 6-caller blast radius on `AlertService::resolve()`; LLM/wiki amplification; a webhook-key leak via the API *response*). All amendments are folded below; gate findings + owner sign-offs are in "Gate outcomes" at the end. **Build to the gates.**

**Goal:** Make Tactical alert→ticket **zero-config to set up** — PSA auto-provisions the Tactical-side `URLAction` + `AlertTemplate` from the integration settings — and provide an **opt-in** auto-ticket flow (above an error threshold, deduped, burst-guarded) that **resolves** (not hard-closes) the ticket when the alert clears. Plus a webhook-records prune job.

**Architecture:** A provisioning flow (mirroring `autoConfigureComet`) calls Tactical's API: `POST /core/urlaction/` (REST action → PSA `/api/webhooks/tactical` with `X-Webhook-Key`) → `POST /alerts/templates/` (wiring failure+resolved to the URLAction) → `PUT /core/settings/` (set as default, GET-first so we never clobber an existing default). Idempotent via stored ids (PUT-update, POST-create fallback on 404). Auto-ticketing wires the EXISTING `AlertService::createTicket()` into the **Tactical** alert path (OFF by default, gated by a separate error-level threshold, deduped, burst-guarded). On Tactical alert-resolve, the auto-created untouched ticket is set **Resolved** (the existing `CloseResolvedTickets` sweep closes it after the confirmation window) — **Tactical-scoped, not via the shared `resolve()`**. A scheduled command prunes old webhook rows.

**Tech Stack:** PHP 8 + Laravel + PHPUnit (sqlite `:memory:`). Reuses `TacticalClient`, `AlertService`, `TicketService`, `CloseResolvedTickets`, `TacticalConfig`/`Setting`, the webhook/alert pipeline. No new schema (config keys only).

**Spec:** `docs/.../2026-06-15-tactical-rmm-integration-design.md` §6 (P7), §7, §11.

## Scope
Ships: (1) auto-provision, (2) **opt-in** auto-ticket, (3) auto-**resolve**-on-resolve, (4) prune. The "configurable alert→ticket rules" = a **simple global config** (auto-ticket toggle + error threshold). A **rich per-client/per-check rules engine is DEFERRED to a follow-up bead** (spec calls it "optional"; gate unanimous to defer).

## Owner decisions (SETTLED — Charlie 2026-06-17, gate-informed)
- **Auto-ticket default OFF / opt-in** (NOT the bead's ON) — provisioning is zero-config, but auto-ticketing is a toggle the operator enables after tuning. Avoids first-run flood.
- **Auto-close = RESOLVE, then time-based close** (NOT hard-close-on-resolve) — alert-resolve sets the ticket `Resolved`; the existing `CloseResolvedTickets` closes it after the confirmation window. Avoids the flapping loop + preserves SLA accuracy.
- **Separate auto-ticket threshold, default `error`** (not reusing the `warning` inbound gate).
- **Idempotency: store ids + PUT-update, POST-create on 404.**
- **Defer the rich rules engine.**

## Global Constraints (binding — every task includes these)

- **G1 — API shapes CONFIRMED from amidaware/tacticalrmm source** (master, 2026-06-17). URLAction `POST /core/urlaction/` `{name,desc,pattern,action_type:"rest",rest_method:"post",rest_headers:<json-str with X-Webhook-Key>,rest_body:<json-str with {{alert.*}}>}`→`{id}`. AlertTemplate `POST /alerts/templates/` `{name,is_active:true,action_type:"rest",action_rest:<id>,resolved_action_type:"rest",resolved_action_rest:<id>,agent_script_actions:true,check_script_actions:true,task_script_actions:true}`→`{id}`. Default `PUT /core/settings/` `{alert_template:<id>}`. **MERGE-BLOCKING live-verify (Task 6).**
- **G2 — least-privilege 403 surfaced.** Needs the API key's role to have `can_run_urlactions`+`can_manage_alerttemplates`+core-settings write. A 403 → actionable error, never a 500. **Provisioning is audited with actor (who/when/outcome), secret-free.**
- **G3 — webhook key never logged/audited, REQUEST *and* RESPONSE.** The key is in the URLAction `rest_headers` sent to Tactical AND **echoed back in the API response** (Tactical serializes `fields="__all__"`). After decoding any urlaction create/update response, **`unset($r['rest_headers'],$r['rest_body'])`** before it can reach a log/audit/exception. Test the key absent from logs AND any audit row, on both paths. Reuse `TacticalConfig::generateWebhookKey()`.
- **G4 — idempotent provisioning, no clobber.** Store `tactical_url_action_id`/`tactical_alert_template_id`/`tactical_webhook_provisioned_at`. Re-provision: stored id → PUT-update; **PUT 404 (human deleted it in Tactical) → POST-create + overwrite the stored id** (never wedge). Before `PUT /core/settings/`: **GET current settings; if a *different* `alert_template` is already the default, record the prior id + warn (reversible) — do not silently clobber the MSP's existing default.**
- **G5 — bounded, SSRF-safe calls** via `TacticalClient` (X-API-KEY + request-time SSRF pin inherited), bounded timeouts; never hang.
- **G6 — auto-ticket is OPT-IN, gated, deduped, burst-guarded.** Auto-create a ticket ONLY when: `auto_ticket` toggle ON (default OFF) AND severity ≥ `auto_ticket_min_severity` (default `error`, validated) AND `!$alert->ticket_id`. **Tactical-scoped** (wire it in `TacticalAlertService::handleAlertFailure` after `upsert`, NOT in shared `AlertService`). Auto-tickets are created with `created_by = TriageConfig::systemUserId()` (never null) so the triage-pipeline recursion guard holds. **Burst guard:** if > N auto-tickets would be created for a client within a window (e.g. 10/5min), stop auto-ticketing and raise a single "alert storm" ticket instead — bound the flood + the downstream triage/LLM jobs.
- **G7 — auto-RESOLVE (not close), conservative, Tactical-scoped.** On Tactical alert-resolve, if the linked ticket was **auto-created from this alert (`TicketSource::Alert`), still open, and `Ticket::isUntouchedByHuman()`**, set it **`Resolved`** (with a resolution string from the alert reason — so `GenerateTicketResolution` does NOT fire) + keep the existing resolve-note. The existing `CloseResolvedTickets` sweep closes it later. **Do NOT modify the shared `AlertService::resolve()` to close/resolve tickets** (it's called by Ninja/Comet/Huntress/reconcile/manual — Ninja documents "resolve does NOT close"); do this in `TacticalAlertService::handleAlertResolved` only. Consider suppressing wiki-mining for auto-`TicketSource::Alert` closes.

**`Ticket::isUntouchedByHuman(): bool`** — true iff: no `TicketNote` with `note_type NOT IN NoteType::systemGenerated()` (i.e. no human Note/Reply/PhoneCall/Resolution), AND no **portal reply** (`who_type == EndUser`, author_id null — must count as human), AND `responded_at` is null AND status is still `New`. (Evaluate BEFORE the resolve-note is added, or exclude that note by type.) Reusable + unit-tested in isolation.

**Owner-decisions are SETTLED (above) — no open owner-decisions remain for the gate.**

**Consumed interfaces (exist):** `TacticalClient::post()/put()/createClient()`; `TacticalConfig::get/generateWebhookKey/isConfigured/alertMinSeverity`; `Setting::setValue/getValue`; `AlertService::createTicket(Alert):Ticket` + `upsert`/`resolve`; `TicketService::changeStatus(...,TicketStatus::Resolved,...)`; `CloseResolvedTickets` (`tickets:close-resolved`); `TriageConfig::systemUserId()`; `NoteType::systemGenerated()`/`isSystemGenerated()`; `WhoType::EndUser`; `TicketSource::Alert`; `TacticalWebhook`/`NinjaWebhook`; `autoConfigureComet()` (mirror); `TacticalReconcileAlerts`/`CleanOrphanAttachments` (command patterns).

---

## File Structure

| File | Change | Responsibility |
|------|--------|----------------|
| `app/Services/Tactical/TacticalClient.php` | modify | `createUrlAction/updateUrlAction/createAlertTemplate/updateAlertTemplate/setDefaultAlertTemplate/getCoreSettings`; ensure `put()` returns `array` |
| `app/Services/Tactical/TacticalProvisioningService.php` | create | idempotent provision (ensure key→upsert URLAction→upsert template→GET-then-set default→store ids); 403→actionable; audit w/ actor; strip rest_headers/body from response |
| `app/Http/Controllers/Web/IntegrationsController.php` | modify | `provisionTacticalAlerts()` action (mirror autoConfigureComet) |
| `routes/web.php` | modify | `POST settings/integrations/tactical/provision-alerts` (auth+web group) |
| `app/Support/TacticalConfig.php` | modify | keys: url_action_id, alert_template_id, webhook_provisioned_at, prior_default_alert_template_id, auto_ticket(bool,default false), auto_ticket_min_severity(default 'error', validated) |
| `app/Models/Ticket.php` | modify | `isUntouchedByHuman(): bool` |
| `app/Services/Tactical/TacticalAlertService.php` | modify | after `upsert` (failure): opt-in auto-ticket (G6); on resolved: auto-RESOLVE the untouched auto-ticket (G7) |
| `app/Console/Commands/PruneIntegrationWebhooks.php` | create | prune processed `tactical_webhooks`/`ninja_webhooks` older than retention; `--dry-run` |
| `routes/console.php` | modify | schedule the prune (guarded) |
| tests (Feature) | create | provisioning (idempotent/404-fallback/no-clobber/key-never-logged-req+resp/403), auto-ticket (opt-in/threshold/dedup/burst/created_by), auto-resolve (untouched/resolve-not-close/human-touched/portal-reply/manual), prune |
| `docs/INSTALL.md` | modify | one-click provision; role perms + grant-then-revoke; deprovision/cleanup; opt-in auto-ticket + auto-resolve defaults |

---

## Task 1: TacticalClient provisioning methods (G1/G3/G5)
**Files:** `TacticalClient.php`; Test `tests/Feature/Tactical/TacticalProvisioningTest.php`.
- [ ] **Step 1: failing tests** — `createUrlAction`/`updateUrlAction` POST/PUT `core/urlaction/[<id>/]`; `createAlertTemplate`/`updateAlertTemplate` POST/PUT `alerts/templates/[<id>/]`; `setDefaultAlertTemplate` PUT `core/settings/ {alert_template:id}`; `getCoreSettings` GET `core/settings/`. Each returns decoded JSON. **Assert `put()` returns `[]` not null on an empty 200 body** (add `?? []`).
- [ ] **Step 2: run — FAIL.**
- [ ] **Step 3: implement** the methods (mirror existing `post()`/`put()`; G1 shapes). Fix `put()` to `?? []`.
- [ ] **Step 4: run — PASS.**
- [ ] **Step 5: commit** (explicit staging) — `feat(tactical-p7): TacticalClient URLAction/AlertTemplate provisioning methods (G1)`

## Task 2: TacticalProvisioningService + settings action (G2/G3/G4/G5)
**Files:** create `TacticalProvisioningService.php`; modify `IntegrationsController.php`, `routes/web.php`, `TacticalConfig.php`; Test (add).
- [ ] **Step 1: failing tests** (mock TacticalClient): provision (a) generates key if absent, (b) POSTs URLAction with the PSA webhook URL + `X-Webhook-Key`, (c) POSTs AlertTemplate wiring action_rest+resolved_action_rest, (d) **GET-then-PUT default only if no different default already set; if a different default exists → records prior id + does not silently clobber** (G4), (e) stores ids+provisioned_at. **Re-provision reuses ids via PUT (no dup POST); PUT 404 → POST-create + overwrites id** (G4). **403 → actionable error, not 500** (G2). **The webhook key appears in NEITHER logs NOR the audit row — on the request AND on the (rest_headers-echoing) response** (G3). **Provision writes an audit entry with the actor id + outcome (secret-free)** (G2).
- [ ] **Step 2: run — FAIL.**
- [ ] **Step 3: implement** the service (ensure-key→upsert URLAction [strip rest_headers/body from the decoded response immediately]→upsert template→GET core/settings→set default unless a different one is present [record prior]→store ids; catch TacticalClientException, map 403→actionable, PUT-404→POST; audit with `auth()->id()`, outcome, no secret) + controller action (mirror autoConfigureComet) + route + the TacticalConfig keys + the settings-card button (Blade, render-checked).
- [ ] **Step 4: run — PASS** + `php artisan test tests/Feature/Tactical`.
- [ ] **Step 5: commit** — `feat(tactical-p7): idempotent, no-clobber, perms-aware alert provisioning (G2-G5)`

## Task 3: Opt-in auto-ticket, gated + deduped + burst-guarded (G6)
**Files:** modify `TacticalAlertService.php`, `TacticalConfig.php`; Test `tests/Feature/Tactical/TacticalAutoTicketTest.php`.
- [ ] **Step 1: failing tests** — auto_ticket OFF (default) → NO ticket even above threshold; ON + severity≥`error` → ticket created, `created_by == systemUserId()`, alert.status=Ticketed; ON + below error → no ticket; re-fired/ticketed alert → no second ticket; **burst: >N auto-tickets for a client in the window → stop + ONE "alert storm" ticket** (assert the (N+1)th does not create a normal ticket).
- [ ] **Step 2: run — FAIL.**
- [ ] **Step 3: implement** — after `AlertService::upsert(...)` in `handleAlertFailure`, if `TacticalConfig::autoTicket()` && level(severity) ≥ level(`autoTicketMinSeverity()` [validated, default error]) && `!$alert->ticket_id` && burst-guard-ok → `AlertService::createTicket($alert)` with the system user; else if burst exceeded → raise/maintain one storm ticket. (createTicket already guards double-create.)
- [ ] **Step 4: run — PASS.**
- [ ] **Step 5: commit** — `feat(tactical-p7): opt-in auto-ticket (error-threshold, deduped, burst-guarded) (G6)`

## Task 4: Auto-RESOLVE untouched auto-ticket on Tactical resolve (G7) + `isUntouchedByHuman`
**Files:** modify `Ticket.php` (predicate), `TacticalAlertService.php` (`handleAlertResolved`); Test (add to auto-ticket test).
- [ ] **Step 1: failing tests** — `Ticket::isUntouchedByHuman()`: true for system-notes-only; **false for a human Note, a human Reply, a portal reply (who_type=EndUser/null author), a non-New status / non-null responded_at**. And: a Tactical alert-resolve whose ticket is auto-created+open+untouched → ticket **Resolved (NOT Closed)** + resolution text set + resolve-note present; human-touched → note only, not resolved; manually-created linked ticket → not resolved; **assert NO modification to `AlertService::resolve()` shared behavior (Ninja/Comet path unchanged)**.
- [ ] **Step 2: run — FAIL.**
- [ ] **Step 3: implement** — add `Ticket::isUntouchedByHuman()` (the G7 predicate); in `TacticalAlertService::handleAlertResolved`, after `AlertService::resolve()` (which adds the note), if the linked ticket is `TicketSource::Alert` && open && `isUntouchedByHuman()` → `TicketService::changeStatus(..., TicketStatus::Resolved, systemUserId())` with a resolution string. Do NOT touch shared `AlertService::resolve()`.
- [ ] **Step 4: run — PASS** + `php artisan test tests/Feature/Tactical tests/Feature/Tickets`.
- [ ] **Step 5: commit** — `feat(tactical-p7): auto-resolve (not close) untouched auto-ticket on Tactical alert resolve (G7)`

## Task 5: Webhook prune command
**Files:** create `app/Console/Commands/PruneIntegrationWebhooks.php`; modify `routes/console.php`; Test.
- [ ] **Step 1: failing tests** — `integrations:prune-webhooks` deletes processed/terminal `tactical_webhooks`+`ninja_webhooks` older than retention (e.g. 30d), keeps recent + unprocessed; `--dry-run` reports without deleting.
- [ ] **Step 2: run — FAIL.** **Step 3: implement** (mirror CleanOrphanAttachments/TacticalReconcileAlerts; bounded delete; --dry-run; log) + schedule daily (guarded). **Step 4: run — PASS.** **Step 5: commit** — `feat(tactical-p7): prune-integration-webhooks scheduled command`

## Task 6: Docs + deprovision + merge-blocking live-verify
**Files:** `docs/INSTALL.md`.
- [ ] **Step 1:** one-click **Provision alert→ticket**; the 3 role perms + **grant-then-revoke** guidance; **deprovision/manual-cleanup** note (the URLAction/template remain in Tactical if the integration is disabled — document removal); the **opt-in auto-ticket** + **auto-resolve (not close)** defaults + the error threshold; keep the manual path as fallback.
- [ ] **Step 2: MERGE-BLOCKING live-verify** — run the real provision against dev Tactical (create URLAction+template, GET-then-set default), trigger/await a real VM-105 alert, confirm it fires PSA `/api/webhooks/tactical` → alert → (with auto-ticket ON for the test) ticket end-to-end, then alert-resolve → ticket Resolved. Confirm the key role perms (or surface 403). **Clean up the test URLAction/template** (proves the deprovision path). Adjust Tasks 1-2 if dev's API differs. No key/token committed. Record in PR.
- [ ] **Step 3: commit** — `docs(tactical-p7): zero-config provisioning + opt-in auto-ticket/auto-resolve; live-verified`

---

## Self-Review
**Spec coverage:** auto-provision failure+resolved (T1/T2) · zero-config setup (T2) · opt-in auto-ticket above error threshold, deduped, burst-guarded (T3) · auto-resolve-not-close on resolve (T4) · prune (T5) · simple-global-config rules + rich engine DEFERRED. ✓
**Placeholder scan:** T1 bodies are G1 source-confirmed; only deferred item = Task-6 live-verify. **Type consistency:** client method sigs (T1)→service (T2); TacticalConfig keys consistent T2/T3/T4; `isUntouchedByHuman` (T4) reused; createTicket/resolve/changeStatus reused unchanged.

## Gate outcomes (persona review 2026-06-17)
- **Verdicts:** Senior Dev/MSP-Ops/Security APPROVE-w/fixes; Critic + ITIL REVISE (Criticals) — all folded.
- **Criticals fixed:** flapping open/close loop → auto-RESOLVE-not-close + the existing close-sweep (G7); shared `AlertService::resolve()` blast radius (Ninja/Comet/Huntress) → Tactical-scoped only (G7); LLM/wiki amplification → resolution-string on auto-resolve + burst guard (G6/G7); webhook-key leak via API *response* → strip rest_headers/body + test req+resp (G3).
- **Owner decisions (Charlie):** auto-ticket **OFF/opt-in**; auto-close **RESOLVE-then-window**; **separate error threshold**; **store+PUT+404-fallback** idempotency; **defer** rich rules engine. Brain: [[2026-06-17 tactical-p7-onboarding-gate]].
- **Also folded:** no-clobber existing default (GET-first+record prior); 403-actionable + audit-with-actor; precise `isUntouchedByHuman` (portal-reply null-author counted); created_by=systemUser; put()→array; grant-then-revoke + deprovision docs; auto_ticket_min_severity validation.
- **Deferred (noted):** rich rules engine; capability-tiering (psa-hbh) touches the provision route too; webhook retention is THIS plan's prune (Task 5).
