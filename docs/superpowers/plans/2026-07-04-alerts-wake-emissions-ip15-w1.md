# Alerts-Wake Intake Emissions (psa-ip15 W1) — FRESH-SESSION Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development. Steps use `- [ ]`.
> **Context:** W2 (see+manage tools) shipped as PR #159. This is W1 — the 4 intake signal emissions + 2 catalog types. Spec: vault `plans/2026-07-04-alerts-wake-intake-tools-spec.md` §1. Mayor's Q1 ruling (on the bead, 22:06Z) is folded in below. Source-verified against prod on 2026-07-04.

**Goal:** Emit 4 intake signals via the `SignalHub::emit` chokepoint (E1 email_received, E2 email_unresolved, E3 call_received, E4 call_transcribed) + add 2 new catalog types. Parallel-plane: ZERO native-path behavior change.

**Branch:** `psa-ip15-emissions` (already created off origin/main; empty). Base `main`. Fresh branch if it drifted: `git checkout -b psa-ip15-emissions origin/main`.

## Global Constraints (Mayor Q1 ruling = design law here)

- **(A) reference-only emit, ZERO `SignalHub`/allowlist/sink change.** Emit signature: `app(SignalHub::class)->emit(string $typeKey, ?Model $entity, string $summary, array $context = [])`. Pass `$entity` = the `Email`/`PhoneCall` model (this carries the id via entity_type/entity_id — the whole reference-only payload). Context: `['client_id' => $x->client_id]` ONLY (`client_id` is the sole allowlisted-and-useful key; enables client-scoped routing). Do NOT pass `outcome`/`direction`/`ticket_id`/etc. as context — `SignalHub::sanitizeContext()` silently drops non-allowlisted keys (allowlist = category/priority/client_id/destination_id). Do NOT edit that allowlist.
- **Summary = terse MECHANICAL descriptor ONLY.** e.g. `'inbound email unresolved (no ticket)'`, `'inbound email matched to existing ticket'`, `'call transcribed'`, direction/outcome words. **NEVER** the email subject / from-address / body, nor the call transcript. The reference-only rule applies to the summary string too (it lands on `signal_events.summary` and can reach sinks). Chet pulls real content via the W2 tools. **Test-lock this** (assert a distinctive subject/body/transcript string is ABSENT from every emitted summary).
- **Parallel-plane:** `SignalHub::emit` already catch-Throwables and returns null on any failure — a failed emit never breaks the native path. Add emits only at the anchor points below; change NOTHING else in the native flow. Existing email/call tests must stay byte-identical.
- **Dormant:** additive non-core routable catalog types; NO default routes seeded (Charlie composes routes).
- `vendor/bin/pint` clean; `tests/Feature/Signals` + `tests/Feature/Email` + the phone/transcription suites green.

---

### Task 1: Catalog — 2 new types

**Files:** `app/Services/Signals/SignalEventTypes.php`; `tests/Feature/Signals/SignalEventTypesTest.php`.

- [ ] In `SignalEventTypes::all()`, right after the existing `'intake.call_received' => [...]` block (they sit ~lines 30-39), add (mirroring the existing intake entries — structure is exactly `label`/`core`/`routable`):
  ```php
  'intake.email_unresolved' => [
      'label' => 'Intake email unresolved',
      'core' => false,
      'routable' => true,
  ],
  'intake.call_transcribed' => [
      'label' => 'Intake call transcribed',
      'core' => false,
      'routable' => true,
  ],
  ```
- [ ] `SignalEventTypesTest::test_registry_contains_exact_v1_catalog_...` asserts the EXACT ordered `array_keys($types)` (~lines 14-31). Add the two new keys in the SAME position they appear in `all()` (after `intake.call_received`). Run: `php artisan test tests/Feature/Signals/SignalEventTypesTest.php` → green. Commit.

---

### Task 2: E1 + E2 email emissions (`EmailService::processInbound`)

**Files:** `app/Services/EmailService.php` (method `processInbound` at :214-276 — the current body is quoted in the bead's drift comment for reference); new `tests/Feature/Signals/IntakeEmailEmissionsTest.php`.

**Placement (all AFTER the auto-reply :228 and spam :258 early-returns → noise guard falls out of placement; do NOT add any emit before those returns):**

- [ ] **E2** — inside the existing `if ($email->ticket_id === null && $email->received_at >= now()->subHours(24)) {` block (:273-275), right after the `notifyUnresolvedEmail($email)` call (:274):
  ```php
  app(\App\Services\Signals\SignalHub::class)->emit('intake.email_unresolved', $email, 'inbound email unresolved (no ticket)', ['client_id' => $email->client_id]);
  ```
  (Mirror-native by design: same 24h guard → E2 fires exactly where native gives up; >24h backfill correctly does NOT wake Chet. Per ruling.)

- [ ] **E1 site A (matched)** — inside `if ($ticket) { ... }`, immediately BEFORE the `return;` at :239:
  ```php
  app(\App\Services\Signals\SignalHub::class)->emit('intake.email_received', $email, 'inbound email matched to existing ticket', ['client_id' => $email->client_id]);
  ```

- [ ] **E1 site B (fallthrough: created or unresolved)** — as the LAST statement of the method, after the :273-275 block, before the closing `}` at :276:
  ```php
  // E1 umbrella intake feed for fallthrough survivors. Outcome label is derived from
  // whether a ticket now exists. NOTE: this labels a non-null ticket_id as "created";
  // the dormant intake-router attach sub-path (routeInboundEmail :721-729) could also
  // produce a non-null ticket_id via *attach* rather than create — accurate today
  // because that path is off by default (AgentConfig::intakeEnabled()). Revisit this
  // label if intake routing is enabled. (Per Mayor Q1 ruling — cheap-honesty comment.)
  $outcome = $email->ticket_id !== null ? 'ticket created' : 'unresolved (no ticket)';
  app(\App\Services\Signals\SignalHub::class)->emit('intake.email_received', $email, 'inbound email received — '.$outcome, ['client_id' => $email->client_id]);
  ```
  (`$email` was already `refresh()`ed at :272, so `ticket_id` is current here.)

- [ ] **Tests** (`IntakeEmailEmissionsTest`, mirror `tests/Feature/Signals/CoreEmissionsTest.php`'s `assertSingleSignalEvent($typeKey)` helper + `tests/Feature/ForwardAttributionTest.php` Email fixtures):
  - Noise-guard (FRESH — no existing scaffolding): auto-reply email (e.g. `subject => 'Out of Office'` or `from_address => 'postmaster@x.com'` — check `isAutoReply` at :283 for what trips it) → assert `dismissed_at` set AND `SignalEvent::where('type_key','intake.email_received')->count() === 0`. Spam from unknown sender (no client/person, `evaluateSpam` returns hit) → same.
  - Matched: email matching an existing open ticket (mirror ForwardAttributionTest) → exactly 1 `intake.email_received`, `entity_id === $email->id`.
  - Unresolved within 24h: inbound, no match, no auto-create (setting off), `received_at = now()` → 1 `intake.email_received` (summary contains 'unresolved') AND 1 `intake.email_unresolved`.
  - >24h unresolved: `received_at = now()->subHours(30)` → `intake.email_received` fires but `intake.email_unresolved` does NOT (native notify guard).
  - **Summary-mechanical lock:** email with `subject => 'SECRET SUBJECT'`, `from_address => 'secret@evil.test'` → after processInbound, assert NO emitted `intake.email_*` SignalEvent summary contains 'SECRET SUBJECT' or 'secret@evil.test'.
  - Parallel-plane: mirror `tests/Feature/Signals/EscalationParallelPlaneTest.php` — assert the native outcome (dismiss/match/notify) is unchanged AND `signal_deliveries` count is 0 (no routes). Optionally the "SignalHub subclass throws → native path completes identically" test.
  - Commit.

---

### Task 3: E3 + E4 call emissions

**Files:** `app/Http/Controllers/Api/PlivoWebhookController.php` (E3); `app/Services/TranscriptionService.php` (E4); new `tests/Feature/Signals/IntakeCallEmissionsTest.php`.

- [ ] **E3** — `PlivoWebhookController::handle()` has TWO terminal branches that both do `$call = $this->phoneCallService->handleCallEnded($callUuid, ...); $this->resolveRecordingAfterEnd($call); return response('OK', 200);` — the `dialAction === 'hangup'` branch (~:302-312) and the `CallStatus` terminal-list branch (~:316-320). Add the emit in BOTH, right after the `handleCallEnded` call, guarded by `if ($call)` (it returns `?PhoneCall`):
  ```php
  if ($call) {
      app(\App\Services\Signals\SignalHub::class)->emit('intake.call_received', $call, 'inbound call received', ['client_id' => $call->client_id]);
  }
  ```
  (Use the direction word — `$call->direction === CallDirection::Outbound ? 'outbound' : 'inbound'` — in the summary if you prefer; keep it mechanical. `client_id` is usually null at hangup — fine. Consider a tiny private helper to avoid duplicating the emit in both branches.)

- [ ] **E4** — `TranscriptionService::transcribe()`, immediately after the success `$call->update(['transcription_status' => TranscriptionStatus::Completed, ...])` block (~:262-266) and before/alongside the existing `if (AgentConfig::intakeEnabled()) CallIntakeJob::dispatch(...)` (~:273). Success-only (the `Failed` path is a separate catch at ~:277 → won't reach here):
  ```php
  app(\App\Services\Signals\SignalHub::class)->emit('intake.call_transcribed', $call, 'call transcribed', ['client_id' => $call->client_id]);
  ```

- [ ] **Tests** (`IntakeCallEmissionsTest`, mirror `CoreEmissionsTest`'s `PhoneCall::create([...])` fixtures — note `PhoneCall.client_id`/`person_id` are NOT fillable; set via direct-assign-then-save):
  - E3: drive both terminal branches (a `dialAction=hangup` POST and a `CallStatus=completed` POST to `/api/webhooks/plivo` or call `handle()` — check the route + the existing webhook-test pattern) → exactly 1 `intake.call_received` each, `entity_id === $call->id`.
  - E4: drive `transcribe()` to its Completed branch (mock Guzzle/Whisper + AI per `TranscriptionConfig`/`AiConfig`; there's NO existing e2e harness — `tests/Unit/Transcription/*` only unit-tests helpers, so this test needs HTTP fakes) → 1 `intake.call_transcribed`. Failed transcription → 0. (If a full transcribe() harness is too heavy, at minimum unit-test that the emit fires on the Completed path via a focused fixture; note any coverage gap.)
  - Summary-mechanical lock: assert no call-emission summary contains the transcript text.
  - Commit.

---

## Final Verification
- [ ] `vendor/bin/pint --test` clean; `php artisan test tests/Feature/Signals tests/Feature/Email` green; then full `php artisan test`.
- [ ] `/soundpsa-review-pr psa-ip15-emissions` — address findings (esp. parallel-plane + summary-mechanical lock).
- [ ] PR (base main) + comment on psa-ip15 + notify Mayor; hold merge. PR body: reference-only per Q1(A); the E1 outcome-approximation comment; mirror-native E2 (24h guard); noise-guard + summary-mechanical test locks; W1 completes psa-ip15 (with W2 PR #159).
- [ ] After BOTH W1+W2 merge, psa-ip15 is complete — the Mayor closes the bead.
