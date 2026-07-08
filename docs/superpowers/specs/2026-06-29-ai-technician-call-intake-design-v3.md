---
type: design
status: for-final-go (Mayor + Charlie) — gated, do NOT build yet
tags: [soundit-dev, ai-technician, intake, call, voicemail, trip-critical, review-me]
created: 2026-06-29
bead: psa-xcyo
supersedes: voicemail-stage-design v1/v2 (this folds in Charlie's 2 post-v2 refinements, grounded)
---
# AI Technician — CALL intake design v3 (voicemail + answered + outbound, grounded)

> Folds Charlie's 2 post-v2 refinements ("voicemail looks really good") into the converged design, **grounded
> against the real code**. Build STILL GATED on the Mayor+Charlie final go. Reuses the mature TranscriptionService;
> the call intake = a few surgical gaps + a tail, NOT a from-scratch pipeline.

## Reuse (verified): the existing pipeline runs as-is for ALL calls
`ResolveCallerFromPeople` (async, pre-transcribe) → `TranscriptionService::transcribe` (Whisper + stereo
diarization + chunking + the Claude analysis pass → call_summary / next_steps / sentiment / charge_classification /
cleaned_transcript). **TranscriptionService already transcribes EVERY recorded call** (the Plivo webhook
auto-transcribes any recording ≥ min duration — voicemail or answered), and `resolveSpeakerLabels` already maps
the **customer party per direction** (inbound → customer = A-leg/from-party; outbound → customer = B-leg/to-party).

## The build = close gaps 1-3 + the broadened tail (all dormant, held-first, internal-only)

### GAP 1 — structured content-caller-ID + resolve/create (v2, unchanged)
The Claude analysis pass content-IDs the caller in free-text transcript labels but emits NO structured field.
ADD a structured `caller_from_content {name, company, signals, confidence}` (extend the analysis prompt or a
focused confirm step) → resolve to a known Person (can OVERRIDE a wrong number-match per the 3→1 feedback edge).

### GAP 2 — routing via the EMAIL leg's IntakeRouter (v2, unchanged)
Reuse the shipped `IntakeRouter` for the call's attach-vs-create (held-first, dormant, SAME injection floor;
"content" = call_summary/cleaned_transcript, fenced + output-scanned).

### GAP 3 — the resolve→(create-if-new=HOLD)→route TAIL orchestrator (v2 + broadened by refinement 1)
A `CallIntakePipeline` of discrete, observable stages dispatched AFTER transcribe/analyze, with feedback edges.

### >>> REFINEMENT 1 (Charlie) — BROADEN: answered-inbound + outbound ride the SAME rails
The rails are call-type-agnostic (TranscriptionService transcribes all). Two concrete additions, both grounded:
- **Skip-if-already-ticketed ENTRY GUARD.** An answered/outbound call may already be linked (a tech created/linked
  it live). The pipeline's first check: `if ($call->isLinkedToTicket()) return;` (`PhoneCall::isLinkedToTicket()` =
  `ticket_id !== null`, model:149). Voicemails are never live-linked → always proceed. So only *un-ticketed* calls
  get routed.
- **Per-direction CUSTOMER resolution (a real fix).** `resolveSpeakerLabels` already picks the customer side per
  direction for diarization, but the *resolution* (`PhoneCallService::findPersonByPhoneNumber`) runs on
  `from_number` only — correct for inbound, WRONG for outbound (where `from_number` is our DID and the customer is
  `to_number`). Generalize: resolve the **customer number** = `from_number` (inbound) / `to_number` (outbound).
  (This makes outbound + answered calls resolve the right party before routing.)

### >>> REFINEMENT 2 (Charlie) — ROBUST unknown-NUMBER resolution cascade (grounded; shrinks HOLD to a clean min)
Don't rely solely on the ASR-transcribed name. For a customer number with NO direct contact match, cascade
(short-circuit on the first hit):
- **(a) Call-history by number** — a prior call to/from this number that WAS resolved (an unsaved number may have
  been associated before). GROUNDED: a focused `PhoneCall::where(fn => from_number = X OR to_number = X)
  ->whereNotNull('person_id')->latest()->first()` → reuse its `person_id`/`client_id`. (Reuses the number-history
  pattern already in `PhoneCallService`; `getCandidateCallers` covers people-sharing-the-current-number.)
- **(b) Whole-DB name/company search** on Gap-1's content-identified identity (find the person/org by name even
  though the number is new). GROUNDED: there is **no unified global-search service** (QuickSearchController is a UI
  quick-search; AssistantToolExecutor searches tickets only) — compose the two existing model scopes:
  **`Person::scopeSearch($contentName)`** (first/last/email LIKE, Person:158) + **`Client::scopeSearch($contentCompany)`**
  (name LIKE, Client:198). Prefer a high-confidence single match; ambiguous/multiple → treat as unresolved (don't
  guess). Scope to the call's client when one is known, else DB-wide.
- **(c) Genuinely-new** (all miss) → **HOLD for a human** (Q1) — now a small, clean set.

### Enhancements (Mayor recs, pending Charlie's final go)
- **Q2a Whisper-`prompt` seed** + the candidate-LIST seed into the analysis "expected participants" when ambiguous
  (OpenAI supports the `prompt` bias; currently unwired) — REC: do it (low-risk, isolated to the call path).
- **Q2b charge-classification as a soft routing signal** — `charge_classification` (Billable / No-Charge) is
  already computed; use it as a SOFT signal to down-rank routing a clearly no-charge/wrong-number call (e.g. a
  wrong-number VM → don't create a ticket; HOLD/dismiss). REC: include as a soft signal, never the sole decider.

## The pipeline (explicit observable stages, call-type-agnostic)
`skip-if-ticketed → resolve customer# (per direction) → [transcribe+analyze already done] → content-caller-ID
(Gap 1) → resolve cascade (direct match → call-history → name/company search → HOLD) → route (IntakeRouter, Gap 2)
→ summary (exists, uses confirmed caller)`. Stages feed/correct earlier ones (content-ID overrides a wrong number
match). Dormant behind `intake_enabled`; held-first; fail-soft (a transcription/route error never loses the call —
it still emails staff as today).

## Decisions for the final go
- **Q1 (the fork): genuinely-new (after the full cascade) → HOLD (Mayor+dev rec)** vs Charlie's stage-4
  auto-Prospect. The cascade now makes truly-genuinely-new a SMALL clean set; HOLD avoids minting a junk
  Prospect+Ticket from a spam/wrong-number call; any call that names a known contact (or hits call-history) is
  already RESOLVED and routes. Confirm HOLD for the trip.
- **Q2a** Whisper/candidate seed — REC do it. **Q2b** charge-classification soft signal — REC include.

## Safety / scope (same as the email leg)
Internal routing only (no client send — zero client risk); dormant behind `intake_enabled`; held-first; injection
floor (client-scoped candidates, fenced + scanned content); fail-soft; reuse TranscriptionService /
ResolveCallerFromPeople / IntakeRouter / Person::scopeSearch / Client::scopeSearch / ProspectIntake (deferred
path); no migration if avoidable (resolution + intake state on `phone_calls` / `proposed_meta`).

## Build sizing (after the go) — TDD/SDD, held PR, opus whole-branch + /soundpsa-review-pr
~4-5 tasks: (1) per-direction customer resolution + the skip-if-ticketed guard; (2) structured content-caller-ID
(Gap 1); (3) the unknown-number resolution cascade (call-history + Person/Client search) (refinement 2); (4) the
CallIntakePipeline tail + the IntakeRouter phone hook (Gaps 2/3); (5) the Q2 seed + charge-signal + surfacing
(Intake lane gains call-source + digest counts). Reuses the email leg's IntakeRouter + cockpit Intake lane.

## Ask
Final go on the v3 framing (broadened rails + the resolution cascade) + Q1 (HOLD genuinely-new) + Q2a/Q2b, and I
build it TDD/SDD held. Email leg is merged + deployed dormant.
