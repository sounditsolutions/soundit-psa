# Teams Personas P2 hardening (psa-7drx) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development. Steps use `- [ ]`.
> **Bead:** psa-7drx (8 items from the P1 merge review of PR #162; Mayor GO 2026-07-05). Single PR, base main, HOLD MERGE.

**Goal:** Apply the 8 pre-bring-up hardening follow-ups the P1 merge review surfaced — credential-complete audience gating, a save-time legacy-app_id collision reject (Mayor-RULED), persona-is-its-own-gate for @mention replies (decide+document), null-`conversation_refs` safety, auto-capture of a persona's conversation, an aud dedup, a poll-ack transaction, and a vacuous-test fix — none P1-reachable, all in the Teams AI-Staff Personas seam.

**Architecture:** Surgical hardenings across the persona registry (`TeamsPersonaConfig`, `TeamsBotConfig`, `TeamsPersona`), the inbound path (`VerifyBotFrameworkJwt`, `TeamsMessagesController`), and the operator bridge (`OperatorBridgeToolExecutor`). Everything stays dormant/held-only; no auto-act thresholds. Legacy single-bot behavior preserved.

**Tech Stack:** Laravel 12, PHPUnit + RefreshDatabase, encrypted casts, existing Bot Framework JWT middleware + MCP staff-token scoping.

## Global Constraints
- **Held-only / no auto-act:** never set `propose_close_auto_threshold` or any auto-act threshold. This is auth/config hardening only.
- **Fail-closed preserved:** unconfigured/half-configured personas must NOT join the JWT audience set. Legacy single-bot flows stay byte-identical when no persona is active.
- **Secret non-exposure preserved:** `bot_client_secret` stays `encrypted` + `$hidden`; `hasSecret()` uses `getRawOriginal`. No reveal.
- **Item 1 is RULED (do not soften to warn):** save-time REJECT with a message pointing to the clean migration path (clear legacy setting first, then register).
- **Item 3 & 8 are DECIDE-AND-DOCUMENT:** implement persona-is-its-own-gate / auto-capture unless a strong counter-reason exists in the code; state the decision in the PR.
- TDD throughout; `vendor/bin/pint` clean before each commit; full suite green at the end.

---

### Task 1 — Registry gating + model save-guards (items 2, 4, 1, 7b)

**Files:** Modify `app/Support/TeamsPersonaConfig.php`, `app/Support/TeamsBotConfig.php` (`appIds()` :86), `app/Models/TeamsPersona.php` (booted saving hook). Test: `tests/Feature/Teams/TeamsPersonaRegistryTest.php` + new cases.

**Interfaces:**
- Produces: `TeamsPersonaConfig::active(): Collection<TeamsPersona>` (enabled + `filled(bot_app_id)` + `filled(tenant_id)` + `hasSecret()`); `TeamsPersonaConfig::flush(): void`; `enabled()` memoized per-request. `byAppId()`/`byTokenLabel()`/`byKey()` resolve from `active()`. `TeamsBotConfig::appIds()` unions legacy with `active()` app_ids.

- [ ] **Step 1 — Failing tests** (`TeamsPersonaRegistryTest`):
  - `test_active_excludes_enabled_but_credential_incomplete_personas`: enabled persona missing `tenant_id` (or `bot_client_secret`, or `bot_app_id`) → NOT in `TeamsPersonaConfig::active()`, NOT in `TeamsBotConfig::appIds()`, `forAppId(its app_id)` → null; a fully-configured enabled persona IS present.
  - `test_enabled_memo_is_busted_on_persona_save`: call `TeamsPersonaConfig::enabled()` (warms memo); create a new enabled persona; assert the next `enabled()`/`appIds()` reflects it (memo busted on save).
  - `test_enabled_persona_cannot_claim_the_legacy_bot_app_id`: set `teams_bot_app_id='legacy-app'`; creating/enabling a persona with `bot_app_id='legacy-app'` and `enabled=true` throws `InvalidArgumentException` whose message contains "Clear the legacy" (or the exact clear-legacy-first phrasing); a DISABLED persona with the same id, or an enabled persona with a distinct id, saves fine; with legacy unset, any id is allowed.
  - `test_token_label_check_only_fires_when_label_is_dirty` (item 7b): create a persona with a valid `mcp_token_label`; delete that `McpToken`; then save an UNRELATED change (e.g. toggle `enabled`) → does NOT throw; but setting a NEW non-existent label DOES throw.
- [ ] **Step 2 — Run, verify fail.**
- [ ] **Step 3 — Implement:**
  - `TeamsPersonaConfig`: add `private static ?Collection $enabledMemo = null;`; `enabled()` returns `self::$enabledMemo ??= TeamsPersona::query()->enabled()->get();`; add `active()` = `self::enabled()->filter(fn (TeamsPersona $p) => filled($p->bot_app_id) && filled($p->tenant_id) && $p->hasSecret())->values();`; add `public static function flush(): void { self::$enabledMemo = null; }`; repoint `byAppId`/`byTokenLabel`/`byKey` to `self::active()->firstWhere(...)`.
  - `TeamsBotConfig::appIds()` (:86): change `TeamsPersonaConfig::enabled()->pluck('bot_app_id')` → `TeamsPersonaConfig::active()->pluck('bot_app_id')`.
  - `TeamsPersona::booted()`: add `static::saved(fn () => TeamsPersonaConfig::flush()); static::deleted(fn () => TeamsPersonaConfig::flush());`. In the `saving` hook: (7b) guard the existing label check with `$persona->isDirty('mcp_token_label') &&`; (1) add — after computing `$legacy = TeamsBotConfig::appId();` — `if ($persona->enabled && $legacy !== null && $persona->bot_app_id === $legacy) { throw new InvalidArgumentException("This persona's bot_app_id [{$legacy}] is already the legacy Teams bot. Clear the legacy teams_bot_app_id setting first, then register this persona."); }`.
- [ ] **Step 4 — Run, verify pass.** Then `php artisan test tests/Feature/Teams tests/Feature/Chet` (legacy + persona regression) green.
- [ ] **Step 5 — Commit** `psa-7drx T1: credential-complete audience gating + legacy-app_id reject + memo + dirty-guarded label check`.

---

### Task 2 — Inbound hardening (items 6, 3, 5, 8)

**Files:** Modify `app/Http/Middleware/VerifyBotFrameworkJwt.php` (`matchedAudience` :142), `app/Http/Controllers/Api/TeamsMessagesController.php` (reply gate :74; `routedToPersona` :114; inbound capture near :51-53), `app/Services/Chet/OperatorBridgeToolExecutor.php` (:144,147). Tests: `tests/Feature/Teams/InboundAudBindingTest.php`, `PersonaReplyGateTest.php` (new), `PersonaConversationCaptureTest.php` (new).

**Interfaces:** Consumes `TeamsPersonaConfig::active()`/`byKey()`. Produces persona-is-its-own-gate reply behavior + persona conversation auto-binding.

- [ ] **Step 1 — Failing tests:**
  - **item 6** (`InboundAudBindingTest`): a token whose `aud` array contains the SAME registered app_id twice (`['persona-app','persona-app']`) with a matching recipient resolves the persona (does NOT over-reject as ambiguous). Two DISTINCT registered app_ids still reject.
  - **item 3** (`PersonaReplyGateTest`): with `teams_bot_enabled=false`, an @mention to an ACTIVE persona that is ALREADY bound to conversation `conv-A` (its `conversation_refs['conversation_id']='conv-A'`) but arriving in a DIFFERENT conversation `conv-B` (so `routedToPersona` is false AND item-8 auto-capture does not fire because refs are already set) STILL replies — persona is its own gate — assert `TeamsReplyService::reply` invoked / a reply attempt. A legacy (null-persona) sender with the toggle off does NOT reply. With the toggle on, legacy replies as before. (This pre-bound setup is REQUIRED to isolate item 3 from item 8; see the item-3/item-8 interaction note in Step 3.)
  - **item 5** (`PersonaConversationCaptureTest` or reply-gate test): a persona with `conversation_refs = null` processed through the inbound path (and through `OperatorBridgeToolExecutor` postToOperator target resolution) raises NO array-offset warning and behaves as "no refs" (routedToPersona false).
  - **item 8** (`PersonaConversationCaptureTest`): first inbound activity resolved to an active persona with empty `conversation_refs` + a pinned serviceUrl WRITES `conversation_refs = ['conversation_id' => <activity conv id>, 'service_url' => <pinned url>]`; a second inbound turn does NOT overwrite an already-set binding.
- [ ] **Step 2 — Run, verify fail.**
- [ ] **Step 3 — Implement:**
  - `matchedAudience` (:142): `$intersect = array_values(array_unique(array_intersect(array_map('strval', $aud), $appIds)));`.
  - `TeamsMessagesController`: hoist `$personaActive = $sender?->personaKey !== null;` above the `routedToPersona` branch; reuse it there; change the reply gate (:74) to `if (($personaActive || TeamsBotConfig::enabled()) && $sender !== null && $this->serviceUrlPinned($request, $activity))`. Update the else-branch log to include the persona context.
  - Null-safety (item 5): `TeamsMessagesController` :114 → `((($persona->conversation_refs ?? [])['conversation_id'] ?? null) === $conversationId)`; `OperatorBridgeToolExecutor` :144/:147 → `(($persona->conversation_refs ?? [])['conversation_id'] ?? null)` / `['service_url']`.
  - Auto-capture (item 8): a private `captureConversationRefs(ResolvedSender $sender, array $activity, Request $request): void` called right after `resolve()` (before the `routedToPersona` branch) — guarded: only when `$sender?->personaKey !== null`, `$this->serviceUrlPinned($request,$activity)`, the activity has a non-empty `conversation.id`, and the persona (`TeamsPersonaConfig::byKey`) currently has no `conversation_refs['conversation_id']`. It writes `['conversation_id' => ..., 'service_url' => <pinned serviceUrl>]` via `forceFill(['conversation_refs' => ...])->save()`, wrapped in try/catch (fail-soft, log at info). **Decision to document in the PR:** this binds the persona to its (already aud-verified, hence safe) bot conversation on first contact, so the persona's subsequent DMs route consistently to its operator lane. Update any existing test that assumed a persona with null refs stays on the reply path, noting the rationale. **item-3/item-8 interaction:** because capture runs BEFORE the `routedToPersona` branch, the FIRST contact binds the persona and routes to the operator lane; the item-3 reply path (persona is its own gate) is therefore reached only when a persona is ALREADY bound to a different conversation and @mentioned elsewhere — which is exactly how the item-3 test is set up. Verify the two tests are mutually consistent.
- [ ] **Step 4 — Run, verify pass** + full `tests/Feature/Teams tests/Feature/Chet` regression green.
- [ ] **Step 5 — Commit** `psa-7drx T2: aud dedup + persona-is-its-own-gate reply + null-refs safety + conversation auto-capture`.

---

### Task 3 — Poll-ack transaction parity + vacuous-test fix (items 7a, 7c)

**Files:** Modify `app/Services/Chet/OperatorBridgeToolExecutor.php` (`pollOperatorMessages` ack :201-206), `tests/Feature/Signals/SignalDestinationModelTest.php` (:30). Test: `tests/Feature/Chet/PollOperatorMessagesToolTest.php` (existing — must stay green).

- [ ] **Step 1 — Failing test / RED for 7c:** In `SignalDestinationModelTest`, the encryption-at-rest assertion uses `DB::table('signal_destinations')->whereKey($destination->id)` — `whereKey` on a query BUILDER degrades via `__call` to `where('key', ...)` (no such column) → `value('address')` returns null → `assertNotSame('...', null)` is VACUOUS (always passes). Change it to `->where('id', $destination->id)`. To prove it was vacuous, first confirm the assertion still passes AND now reads the real ciphertext (add `$this->assertNotNull($rawAddress)` alongside). (No separate RED needed for 7a — it is a no-behavior-change parity wrap validated by the existing poll tests staying green.)
- [ ] **Step 2 — Implement:**
  - 7c: `SignalDestinationModelTest` — replace `whereKey($destination->id)` with `where('id', $destination->id)`; assert the fetched raw value is non-null AND not the plaintext (real encryption-at-rest check).
  - 7a: wrap the `pollOperatorMessages` ack in a transaction for parity with `pollSignals`: `if ($cursor > 0) { DB::transaction(function () use ($lane, $cursor): void { $this->laneScope(OperatorInbox::query(), $lane)->where('id','<=',$cursor)->whereNull('delivered_at')->update(['delivered_at' => now()]); }); }`. Ensure `DB` is imported (it is — pollSignals uses it).
- [ ] **Step 3 — Run, verify pass:** `php artisan test tests/Feature/Chet/PollOperatorMessagesToolTest.php tests/Feature/Signals/SignalDestinationModelTest.php` green; the operator-poll isolation tests unchanged.
- [ ] **Step 4 — Commit** `psa-7drx T3: transaction-wrap operator-poll ack (pollSignals parity) + fix vacuous encryption-at-rest assertion`.

---

## Final Verification
- [ ] `vendor/bin/pint --test` clean; targeted Teams/Chet/Signals suites + full `php artisan test` green.
- [ ] Whole-branch security review (opus): audience-set fail-closed (half-configured excluded, legacy-collision rejected), persona-is-its-own-gate correctness + no legacy regression, null-refs safety, auto-capture safety (aud-bound, fail-soft, no overwrite), aud dedup errs-correct, no held-only/threshold violation, secret non-exposure intact.
- [ ] PR (base main) + comment on psa-7drx + nudge Mayor; HOLD MERGE. PR body: the item-3 & item-8 decisions (persona-is-its-own-gate + auto-capture routing consequence), item-1 rule implemented, and the test locks.
