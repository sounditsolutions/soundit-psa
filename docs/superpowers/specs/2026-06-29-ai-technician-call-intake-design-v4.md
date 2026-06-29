---
type: design
status: for-final-go (Mayor + Charlie) — gated, do NOT build yet
tags: [soundit-dev, ai-technician, intake, call, spam, trip-critical, review-me]
created: 2026-06-29
bead: psa-xcyo
supersedes: call-intake design v3 (this = v3 unchanged + Charlie's 2 post-v3 thoughts, grounded)
---
# AI Technician — CALL intake design v4 (= v3 + reusable-service principle + spam-call handling)

> v4 = **v3 unchanged** (per-direction resolution + skip-if-ticketed; structured content-caller-ID; the unknown-
> number cascade; the CallIntakePipeline tail; IntakeRouter reuse; Q1 HOLD; Q2a/b) **PLUS Charlie's 2 post-v3
> thoughts**, grounded against the real code. STILL GATED on the final go — ground + surface, don't build.

## ADDITION 1 (Charlie) — build the pipeline as a CLEAN REUSABLE SERVICE (future voice agent rides it)
**Roadmap context, not a build task — but it shapes the service boundary now.** An ElevenLabs voice agent will
eventually answer calls live and SUPERSEDE voicemail; the caller-resolve → analyze → route smarts we build ARE
what it plugs into. So design `CallIntakePipeline` as a **channel-agnostic service with a clean input contract**,
not voicemail-coupled:
- **Input:** `{ call (or a future live-session), direction, customer_number, linkage_state, content (transcript/
  summary) }`. **Output:** `{ resolved_caller, route_decision (attach/create/HOLD), spam_verdict }`.
- Voicemail / answered / outbound are just the channels feeding it today; a live voice agent feeds the same
  service later (it already has the resolved caller + live transcript). **No rewrite** — the pipeline doesn't care
  how the content/caller arrived. Keep the transcription/Plivo specifics OUTSIDE the core (they're the adapter).
- Concretely: the `IntakeRouter` (already channel-agnostic — it takes content + a client) + a thin
  `CallIntakePipeline` orchestrator whose stages take the input contract, not a `PhoneCall` god-object. This is
  just good service design; it costs nothing now and saves the voice-agent rewrite later.

## ADDITION 2 (Charlie) — SPAM / MARKETING-CALL auto-handling (FOLD INTO this increment)
Today a spam call is flagged "needs follow-up" + highlighted until a human goes in, marks "followed up", and
blocks the number = pure admin overhead. v4 has the pipeline ASSESS spam and SUGGEST the handling (held-first),
graduating to auto for high-confidence — **splitting the genuinely-new bucket: spam → handle/suggest, real → HOLD.**

### Where it slots in
After the unknown-number resolution cascade (v3 refinement 2). The cascade already shrank "genuinely-new" to a
clean minimum; v4 splits THAT bucket: assess spam → if spam, the spam-handle path; else → HOLD (Q1).

### Assess (reuse + a focused check)
- **Soft signal (already computed):** `charge_classification == NoCharge` — the analysis prompt's No-Charge
  bucket explicitly includes "Sales/discovery call … wrong number/misdial," which overlaps spam. So No-Charge on
  an unknown caller is a spam *prior*, never the sole decider.
- **Spam-specific check on unknowns:** a focused AI assessment over the transcript/summary — "is this an
  unsolicited sales/marketing/robocall, not a real support request?" → `{is_spam, confidence, reason}` (mirror the
  email spam-check spirit; output-scanned). Combine the No-Charge prior + this check into a spam verdict.

### Handle (held-first → graduate; NEVER auto-block day 1)
- **HELD-FIRST (default):** surface a one-tap **"Looks like spam — mark followed-up + block #?"** in the cockpit
  Intake lane (one tap kills the manual go-in/mark/block overhead). On tap, apply the two grounded actions:
  - **mark followed up** — `$call->followed_up_at = now()` (grounded: `PhoneCall::isFollowedUp()`:154;
    `scopeUnknownCaller` = `client_id NULL AND followed_up_at NULL`, so this clears it from the unknown-caller facet;
    same stamp `ProspectController::dismiss` uses).
  - **block #** — `PhoneDirectoryEntry::updateOrCreate(['phone_number' => PhoneNumber::normalize($num)],
    ['list_type' => PhoneDirectoryListType::Blocked, 'reason' => 'AI intake: suspected spam', 'added_by_user_id' =>
    <intake system user>])` (grounded: model `phone_directory`, `list_type` Blocked/Allowed enum, `lookup`/`isBlocked`;
    the Plivo IVR already checks `isBlocked` → a blocked number is auto-rejected on FUTURE calls). Reversible by a
    human via `PhoneDirectoryController`.
- **GRADUATE:** auto mark+block for HIGH-CONFIDENCE spam after calibration — a new null-preserving threshold
  `intake_spam_block_auto_threshold` (default null = never = held), mirroring the attach auto-threshold. **Do NOT
  auto-block day 1** — a false-positive block would silence a real prospect; held-first + a conservative threshold
  + reversibility are the safety net.

### Safety (spam path)
The block is the one semi-destructive action (it rejects future calls from the number). So: held-first SUGGEST by
default (a human one-taps); auto only above an explicit high threshold after calibration; the block is reversible
(PhoneDirectoryController); the spam verdict is conservative (a real-looking unknown → HOLD, not block);
output-scan the spam reason. Internal-only (no client send). Dormant behind `intake_enabled`.

## Updated genuinely-new split (after the v3 cascade)
`spam (charge=NoCharge prior + spam-check high) → SUGGEST mark-followed-up + block (held → graduate auto-high-conf)`
· `real-looking unknown → HOLD for a human (Q1)`.

## Decisions for the final go (unchanged + 1 added)
- Q1 HOLD genuinely-(real)-new — stands (now an even smaller set, spam peeled off). Q2a Whisper/candidate seed —
  rec do it. Q2b charge-classification as a soft routing signal — now ALSO the spam prior (rec include).
- **NEW Q3:** spam auto-handling = held-first SUGGEST (mark-followed-up + block) → graduate auto-high-conf, NEVER
  auto-block day 1. Confirm the posture (and the conservative default threshold = null/never until calibrated).

## Build sizing (after the go) — adds ~1 task to v3's ~4-5
+ a `SpamAssessor` (No-Charge prior + the focused spam check) + the Intake-lane one-tap "mark-followed-up + block"
action (reuse the cockpit action pattern) + the `intake_spam_block_auto_threshold` graduation. Reuses
`followed_up_at` + `PhoneDirectoryEntry` + the IntakeRouter + the cockpit Intake lane. Held PR, opus whole-branch +
/soundpsa-review-pr, dormant.

## Ask
Final go on v4 (= v3 + the reusable-service boundary + the spam-handle path) + Q1 + Q2a/b + Q3 (the spam posture),
and I build it TDD/SDD held. Email leg is merged + deployed dormant.
