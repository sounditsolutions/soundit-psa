# Teams AI-Staff Personas P1 (psa-kh22) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development. Steps use `- [ ]`.
> **Spec:** vault `plans/2026-07-04-teams-personas-spec.md` v1.0 — §1-§3, §7, §8 P1. Bead psa-kh22 task-list is the authoritative P1 scope.
> **Orientation/anchors:** source-verified 2026-07-05 (opus drift-check). All line numbers below are CURRENT (post psa-0htk merge). Where the spec's cited anchors drifted, the corrected anchor is noted.

**Goal:** Add the PSA-side rails for multiple Teams bot "personas" (Gus first, hand-registered as a scaffold) — a persona registry, signed-audience-bound inbound routing, per-persona outbound, and a persona-laned + token-scoped operator inbox — all dormant until a persona's creds are entered and it is enabled. (The office-city persona *brain* is the Mayor's separate half.)

**Architecture:** A new `teams_personas` registry is the single source of persona identity (app_id, encrypted secret, tenant, mcp_token_label, conversation_refs, actor link, enabled). `TeamsBotConfig::appIds()` becomes legacy-id ∪ enabled-persona-ids so the existing JWT-audience SET check keeps working; `forAppId()` returns the persona row. Inbound routing binds to the SIGNED aud (new middleware attribute) and rejects an aud↔recipient mismatch. Outbound is parameterized per persona across the four coupling clusters. The operator inbox gains a persona lane and its poll becomes token-scoped (persona derived server-side from the authenticated MCP token — never a caller param), mirroring the existing `pollSignals` pattern. Legacy single-bot behavior is preserved throughout.

**Tech Stack:** Laravel 12, MariaDB (encrypted casts via `Crypt`/`Setting::setEncrypted`), existing Bot Framework JWT middleware, existing MCP staff-token scoping (`McpToken`/`McpStaffToken`/`SignalDestination.mcp_token_label`).

## Global Constraints (security invariants — bind every task)

- **Single authorization boundary (§7):** the MCP-token → persona mapping authorizes poll/read-back/outbound. NO persona-selecting, conversation-selecting, or sender-selecting value EVER comes from a caller/brain/tool parameter. Persona is always derived server-side (from the authenticated token, or from the signed aud on inbound).
- **Signed-aud-bound routing (§3):** inbound persona resolution binds to the SIGNED `aud` claim. `strip28(activity.recipient.id) === validated aud`; a mismatch is **reject + audit**, never a fallback. (Drift: the middleware today discards the matched aud — Task 2 surfaces it first.)
- **Secret non-exposure (§7):** persona `bot_client_secret` is `encrypted`-cast at rest; the UI/API exposes only a `hasSecret` boolean + a `SECRET_MASK` placeholder (mirror `IntegrationsController::SECRET_MASK` + `updateTeamsBot()` blank/mask = keep). **No reveal endpoint. Ever.**
- **Legacy preserved / fail-closed:** `appIds()` = legacy single-bot id ∪ enabled persona ids. Unconfigured → empty → middleware fails closed. Existing single-bot flows stay byte-identical when no persona is enabled.
- **Dormant:** no persona is active until its creds are entered AND `enabled` is flipped. No seeded/enabled Gus by default (the scaffold seeder inserts a DISABLED Gus with no secret).
- **P1 = MINIMAL registry (§2):** P1 fields only — NO `provision_state`/`entra_object_ids`/ARM fields (P2), NO ambient dials (P3).
- **Parallel-plane for the operator poll:** persona-laning must not change single-lane behavior when only the legacy lane exists. Existing Teams/operator tests stay green.
- `vendor/bin/pint` clean; targeted suites green (`tests/Feature` Teams/Operator/Mcp + new persona tests), then full `php artisan test`.

## Drift notes (spec "source-verified" 07-04 → corrected 07-05)
- **§3 aud surfacing:** `VerifyBotFrameworkJwt:82` validates aud against the SET but DISCARDS the match (only `teams_bot_service_url` surfaced at :94). `recipient.id` is re-derived independently in `TeamsIdentityResolver:28-30`. → Task 2 must surface the matched appId as a request attribute BEFORE the cross-check is possible. (FYI-flagged to Mayor; intent unchanged.)
- **Location:** `enqueueOperatorMessage()`/`routedToChet()` live in `TeamsMessagesController:85-113`, NOT the executor.
- Everything else in §1 HOLDS at the anchors below.

## File Structure
- **Task 1** — Create `database/migrations/*_create_teams_personas_table.php`, `app/Models/TeamsPersona.php`, `app/Support/TeamsPersonaConfig.php` (registry accessor). Modify `app/Support/TeamsBotConfig.php` (`appIds()` ∪ personas; `forAppId()` returns persona row). Test `tests/Feature/Teams/TeamsPersonaRegistryTest.php`.
- **Task 2** — Modify `app/Http/Middleware/VerifyBotFrameworkJwt.php` (surface matched aud), `app/Services/Teams/TeamsIdentityResolver.php` (aud cross-check + persona resolve), `app/Services/Teams/ResolvedSender.php` (+`personaKey`), `app/Http/Controllers/Api/TeamsMessagesController.php` (consume). Test `tests/Feature/Teams/InboundAudBindingTest.php`.
- **Task 3** — Modify `app/Services/Teams/TeamsBotClient.php` (per-persona creds), `app/Services/Agent/Escalation/OperatorDelivery.php`, `app/Services/Chet/OperatorBridgeToolExecutor.php` (postToOperator targets/actor), `app/Services/Teams/TeamsReplyService.php` (actor). Test `tests/Feature/Teams/PerPersonaOutboundTest.php`.
- **Task 4** — Create `database/migrations/*_add_persona_lane_to_operator_inbox.php`. Modify `app/Models/OperatorInbox.php`, `app/Http/Controllers/Api/TeamsMessagesController.php` (enqueue stamps lane; routedToPersona), `app/Services/Chet/OperatorBridgeToolExecutor.php` (`pollOperatorMessages(+$tokenLabel)`), the operator-bridge tool schema + dispatch. Test `tests/Feature/Teams/PersonaLanedOperatorPollTest.php`.
- **Task 5** — (scope pending Mayor confirm) persona→actor indirection + Settings "AI Staff" roster stub + persona typing routing + Gus scaffold seeder. Files TBD on confirm.

---

### Task 1: Persona registry (`teams_personas` + model + config) + `appIds()`/`forAppId()` seam

**Files:**
- Create: `database/migrations/2026_07_05_000001_create_teams_personas_table.php`
- Create: `app/Models/TeamsPersona.php`
- Create: `app/Support/TeamsPersonaConfig.php`
- Modify: `app/Support/TeamsBotConfig.php` (`appIds()` :73-78, `forAppId()` :88-95)
- Test: `tests/Feature/Teams/TeamsPersonaRegistryTest.php`

**Interfaces:**
- Produces: `TeamsPersona` model (`persona_key`, `display_name`, `role_blurb`, `avatar_ref`, `bot_app_id`, `bot_client_secret` [encrypted cast], `tenant_id`, `mcp_token_label`, `actor_user_id` [nullable FK users], `conversation_refs` [json], `enabled` [bool]); accessor `hasSecret(): bool`. `TeamsPersonaConfig::enabled(): Collection<TeamsPersona>`, `::byAppId(string $appId): ?TeamsPersona`, `::byTokenLabel(string $label): ?TeamsPersona`. `TeamsBotConfig::forAppId()` now returns `array{app_id,tenant_id,persona_key:?string}` (persona_key null = legacy bot).

**Steps:**
- [ ] **Step 1 — Failing test:** `TeamsPersonaRegistryTest`:
  - `test_app_ids_union_legacy_and_enabled_personas`: set `teams_bot_app_id=legacy-app`; create an ENABLED persona `bot_app_id=persona-app` and a DISABLED persona `bot_app_id=disabled-app`; assert `TeamsBotConfig::appIds()` === `['legacy-app','persona-app']` (order: legacy first), disabled excluded.
  - `test_for_app_id_returns_persona_row_for_persona_app`: assert `TeamsBotConfig::forAppId('persona-app')` === `['app_id'=>'persona-app','tenant_id'=>$persona->tenant_id,'persona_key'=>'gus']`.
  - `test_for_app_id_returns_legacy_marker_for_legacy_app`: `forAppId('legacy-app')` === `['app_id'=>'legacy-app','tenant_id'=>TeamsBotConfig::tenantId(),'persona_key'=>null]`.
  - `test_for_app_id_null_for_unknown`: `forAppId('nope')` === null.
  - `test_secret_non_exposure`: create persona with `bot_client_secret='s3cr3t'`; assert DB column is NOT the plaintext (encrypted), `$persona->bot_client_secret === 's3cr3t'` (decrypt cast), and `$persona->hasSecret() === true`; a persona with null secret → `hasSecret() === false`.
  - `test_label_existence_validation`: (see Step 3) saving a persona whose `mcp_token_label` has no matching `McpToken` throws/validation-fails.
- [ ] **Step 2 — Run, verify fail** (`php artisan test tests/Feature/Teams/TeamsPersonaRegistryTest.php`) — table/model missing.
- [ ] **Step 3 — Implement:**
  - Migration: `teams_personas` — `id`; `persona_key` (string, unique); `display_name` (string); `role_blurb` (text, nullable); `avatar_ref` (string, nullable); `bot_app_id` (string, unique); `bot_client_secret` (text, nullable); `tenant_id` (string, nullable); `mcp_token_label` (string, nullable, index); `actor_user_id` (nullable, `foreignId`→users nullOnDelete); `conversation_refs` (json, nullable); `enabled` (bool, default false, index); timestamps.
  - `TeamsPersona` model: `$fillable` the above (except id/timestamps); `$casts = ['bot_client_secret'=>'encrypted','conversation_refs'=>'array','enabled'=>'bool']`; `scopeEnabled`; `hasSecret(): bool => filled($this->getRawOriginal('bot_client_secret'))`; `actor()` belongsTo User; label-existence validation via a `saving` model event that, when `mcp_token_label` is filled, asserts `McpToken::where('label',$mcp_token_label)->exists()` else throws `\InvalidArgumentException` (mirror the non-null-safety used elsewhere).
  - `TeamsPersonaConfig`: static `enabled()` (cached per-request), `byAppId()`, `byTokenLabel()` (enabled only).
  - `TeamsBotConfig::appIds()`: `array_values(array_unique(array_merge($legacy ? [$legacy] : [], TeamsPersonaConfig::enabled()->pluck('bot_app_id')->all())))`.
  - `TeamsBotConfig::forAppId()`: if `$persona = TeamsPersonaConfig::byAppId($appId)` → `['app_id'=>$appId,'tenant_id'=>$persona->tenant_id,'persona_key'=>$persona->persona_key]`; elseif `$appId === legacy` → `['app_id'=>$appId,'tenant_id'=>self::tenantId(),'persona_key'=>null]`; else null. (Preserve the existing `in_array` fail-closed guard.)
- [ ] **Step 4 — Run, verify pass.** Then `php artisan test tests/Feature/Teams tests/Feature/Mcp` (legacy regression) green.
- [ ] **Step 5 — Commit** (`psa-kh22 T1: teams_personas registry + appIds/forAppId persona seam`).

---

### Task 2: Inbound signed-aud → persona binding (+ `ResolvedSender.personaKey`)

**Files:**
- Modify: `app/Http/Middleware/VerifyBotFrameworkJwt.php` (surface matched aud — near :82/:94)
- Modify: `app/Services/Teams/TeamsIdentityResolver.php` (:28-30, :63-70)
- Modify: `app/Services/Teams/ResolvedSender.php` (:15-25)
- Modify: `app/Http/Controllers/Api/TeamsMessagesController.php` (resolve call site)
- Test: `tests/Feature/Teams/InboundAudBindingTest.php`

**Interfaces:**
- Consumes: `TeamsBotConfig::forAppId()` (Task 1, returns `persona_key`).
- Produces: request attribute `teams_bot_app_id` (the matched aud); `ResolvedSender` gains readonly `?string $personaKey` (after `$appId`); `TeamsIdentityResolver::resolve()` asserts aud↔recipient and sets `personaKey`.

**Steps:**
- [ ] **Step 1 — Failing test** (`InboundAudBindingTest` — test at the resolver/controller layer with faked middleware attributes, NOT end-to-end JWT per the orientation's harness note):
  - `test_matching_aud_and_recipient_resolves_persona`: request with attribute `teams_bot_app_id='persona-app'` and activity `recipient.id='28:persona-app'` (persona `gus`) → resolver returns a `ResolvedSender` with `personaKey==='gus'`.
  - `test_aud_recipient_mismatch_is_rejected_and_audited`: attribute `teams_bot_app_id='persona-app'`, activity `recipient.id='28:other-app'` → resolve REJECTS (returns null / throws the reject path) AND writes an audit row/log (assert the audit surface, not a fallback resolution). No `ResolvedSender` produced.
  - `test_legacy_app_still_resolves_persona_null`: attribute + recipient both legacy app → `ResolvedSender` with `personaKey===null` (legacy path unchanged).
- [ ] **Step 2 — Run, verify fail.**
- [ ] **Step 3 — Implement:**
  - `VerifyBotFrameworkJwt`: after the aud SET match (:82/`audienceMatches`), compute the matched appId (the element of `appIds()` that matched the token's aud) and `('teams_bot_app_id', $matchedAud)` via `$request->attributes->set(...)` alongside the existing `teams_bot_service_url` at :94. (Single string aud → that value; array aud → the single intersect element; if >1 intersect, that's already anomalous → reject.)
  - `TeamsIdentityResolver::resolve()`: read the validated aud from the request attribute; strip28 the `recipient.id`; **assert equality** — mismatch → audit (`Log::warning('[Teams] aud/recipient mismatch — rejected', [...ids...])` + an audit row if the audit table is used by siblings) and return the reject sentinel (null). On match, `forAppId(validatedAud)` → set `personaKey` from the returned `persona_key`.
  - `ResolvedSender`: add `public readonly ?string $personaKey = null` after `$appId`; thread it through the resolver's constructor call (:63-70).
  - Controller: no behavior change beyond carrying `personaKey` forward.
- [ ] **Step 4 — Run, verify pass** + legacy Teams inbound tests green.
- [ ] **Step 5 — Commit** (`psa-kh22 T2: signed-aud→persona binding + ResolvedSender.personaKey`).

---

### Task 3: Per-persona outbound (parameterize the four coupling clusters)

**Files:** Modify `app/Services/Teams/TeamsBotClient.php` (:169-191 token/creds), `app/Services/Agent/Escalation/OperatorDelivery.php` (:64 enabled-gate), `app/Services/Chet/OperatorBridgeToolExecutor.php` (:102,128-129 postToOperator targets/actor), `app/Services/Teams/TeamsReplyService.php` (:73,105,130 actor). Test `tests/Feature/Teams/PerPersonaOutboundTest.php`.

**Interfaces:**
- Consumes: `TeamsPersona` (Task 1) — `bot_app_id`, `bot_client_secret`, `tenant_id`, `conversation_refs`, `actor_user_id`, `display_name`.
- Produces: a persona-scoped bot context. Recommended shape: `TeamsBotClient::forPersona(TeamsPersona $p): self` returning a client whose `token()` reads the persona's creds (cache key `TOKEN_CACHE_KEY.':'.$p->bot_app_id` — already per-appId at :177) instead of the global singletons; when no persona, the existing global path is byte-identical. `OperatorDelivery`/`TeamsReplyService`/`postToOperator` select conversation/serviceUrl/actor from the persona when present, else the global defaults.

**Steps:** (TDD — one cluster at a time; assert per-persona creds/targets/actor are used AND that the no-persona path is unchanged.)
- [ ] **Step 1 — Failing tests:** persona-scoped `token()` requests the persona's client_id/secret/authority (fake Guzzle, assert POST body); `OperatorDelivery::send` for a persona uses the persona's enabled-gate + conversation/serviceUrl; `TeamsReplyService` reply attributes to the persona's actor name/id; a null-persona call is byte-identical to today. (Mirror existing Teams client tests for the Guzzle-fake harness.)
- [ ] **Step 2 — Run, verify fail.**
- [ ] **Step 3 — Implement** the persona-context threading (value object or `forPersona()`), preserving the singleton path when persona is null. (Note the container-shared `TeamsBotClient` — a per-persona instance must not mutate the shared one; return a fresh configured instance.)
- [ ] **Step 4 — Run, verify pass** + legacy outbound tests green.
- [ ] **Step 5 — Commit** (`psa-kh22 T3: per-persona outbound across the four clusters`).

---

### Task 4: Persona-laned, token-scoped operator inbox (the two-panel blocker)

**Files:** Create `database/migrations/2026_07_05_000002_add_persona_lane_to_operator_inbox.php`. Modify `app/Models/OperatorInbox.php`, `app/Http/Controllers/Api/TeamsMessagesController.php` (:85-113 enqueue + routedToPersona), `app/Services/Chet/OperatorBridgeToolExecutor.php` (:29-36 dispatch, :153-195 `pollOperatorMessages`), the operator-bridge tool schema. Test `tests/Feature/Teams/PersonaLanedOperatorPollTest.php`.

**Interfaces:**
- Consumes: `TeamsPersonaConfig::byTokenLabel()` (Task 1), the resolved `personaKey` from Task 2, the server-derived `$tokenLabel` (from `mcp_staff_token` attribute → `McpStaffToken::label`, the exact pattern `pollSignals` uses via `McpStaffController:761-766`).
- Produces: `operator_inbox` columns `persona` (string, nullable, indexed), `kind` (string, default `'human'`), `sender_persona` (string, nullable); `pollOperatorMessages(array $input, ?string $tokenLabel)` derives persona server-side and filters SELECT **and** ack by lane; `routedToPersona()` replaces `routedToChet()`.

**Steps:**
- [ ] **Step 1 — Failing tests** (`PersonaLanedOperatorPollTest` — the KEY cross-persona isolation tests):
  - `test_poll_is_token_scoped_and_fails_closed_on_empty_label`: `pollOperatorMessages` with null/empty label → error (mirror `pollSignals` :200-202).
  - `test_cross_persona_isolation_select_and_ack`: two personas A,B (each with its own `mcp_token_label`), inbox rows on the same `conversation_id` but `persona='A'` vs `persona='B'`; polling with A's token returns ONLY A's rows and acking with A's cursor leaves B's rows un-acked (assert both SELECT and the delivered/ack marker are laned).
  - `test_enqueue_stamps_persona_from_resolved_sender`: an inbound activity resolved to persona `gus` → `enqueueOperatorMessage` writes `persona='gus'`, `kind='human'`, `sender_persona=null`.
  - `test_legacy_lane_regression`: with no persona (legacy), enqueue writes `persona=null`/legacy-lane and the legacy poll still drains it (single-lane behavior preserved).
- [ ] **Step 2 — Run, verify fail.**
- [ ] **Step 3 — Implement:** migration (nullable columns + backfill existing rows to the legacy lane + swap the composite index `['conversation_id','delivered_at','id']` for a persona-aware `['persona','delivered_at','id']`); model fillable/casts; `enqueueOperatorMessage` stamps `persona`/`kind`/`sender_persona` from the aud-bound `ResolvedSender.personaKey`; dispatch passes `$tokenLabel` to `poll_operator_messages` (mirror the `:34` poll_signals dispatch); `pollOperatorMessages` derives the persona via `TeamsPersonaConfig::byTokenLabel($tokenLabel)` (fail-closed), filters SELECT + the delivered_at/cursor ack by that persona lane; rename `routedToChet()`→`routedToPersona()` keying on the resolved persona. NO caller-supplied persona param.
- [ ] **Step 4 — Run, verify pass** + full Teams/Mcp regression green.
- [ ] **Step 5 — Commit** (`psa-kh22 T4: persona-laned token-scoped operator inbox`).

---

### Task 5: First-class AI actors + Settings "AI Staff" roster stub + persona typing + Gus scaffold  — SCOPE PENDING MAYOR CONFIRM

**Decision flagged (bead + Mayor):** the bead says roster **stub**; ~15 sites thread `TechnicianConfig::aiActorUserId()`/`aiActorName()`. P1 RECOMMENDATION = the MECHANISM, not a 15-site blanket refactor: (i) `TeamsPersona.actor_user_id` (Task 1) links a persona to its actor `users` row; (ii) a `PersonaActorResolver` (or a `TechnicianConfig` overload) that, GIVEN an authenticated persona context, resolves that persona's actor id/name — used at the persona-authored write path(s) that exist in P1, defaulting to the global actor when no persona; (iii) a read-only Settings › Integrations "AI Staff" roster stub listing personas (name/role/enabled/hasSecret booleans — no secrets); (iv) route persona `sendTyping` through the Task 3 per-persona client. Gus scaffold seeder inserts a DISABLED `gus` persona (role_blurb = tech/helpdesk voice, no secret) — never described as the finished blessed path.

**Detailed steps deferred until the Mayor confirms depth** (minimal mechanism as above vs full 15-site indirection). Tasks 1-4 are independent of this and proceed first.

---

## Final Verification
- [ ] `vendor/bin/pint --test` clean; targeted Teams/Operator/Mcp suites + the new persona tests green; then full `php artisan test`.
- [ ] Cross-persona isolation, aud-binding-reject, legacy-bot-regression, and secret-non-exposure tests all present and load-bearing.
- [ ] Final whole-branch review (security lens: single-authorization-boundary, no caller-supplied persona/conversation/sender, fail-closed, secret non-exposure, legacy preserved).
- [ ] PR (base main) + comment on psa-kh22 + notify Mayor; HOLD MERGE. PR body: drift notes (aud surfacing); the actor-attribution P1-depth decision; dormancy; the four test locks.
