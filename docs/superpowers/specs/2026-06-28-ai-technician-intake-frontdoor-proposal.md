---
type: proposal
status: for-mayor-review (SCOPE — not yet a build plan)
tags: [soundit-dev, ai-technician, intake, front-door, trip-critical, review-me]
created: 2026-06-28
bead: psa-xcyo
related: ["[[2026-06-28-ai-technician-increment-H-escalation-primitive]]", "[[trip-readiness-tracker]]"]
---
# AI Technician — Intake Front-Door: scoping proposal (analyze-and-route inbound comms)

> **STEP 1 deliverable (psa-xcyo).** Breadth-first orientation + a decision-forward proposal for the Mayor to
> converge into a build plan. NOT yet a build. Same approach as Increment H (which caught the wrong premise).
> The headline finding, like H: **most of the machinery exists; the genuine new part is the analyze-and-route
> JUDGMENT — and it is narrow.** I surface the hard calls with recommendations so we can converge fast.

## 1. The gap (what "front door" means)
The Aug-1 bar wants the **inbound front door mostly handled while Charlie's away**: incoming email / calls /
voicemails analyzed and routed — **attach to an existing ticket / merge a duplicate / create new / flag
spam-or-noise** — instead of a human hand-triaging each one. Today that judgment is **deterministic and
email-only**; the AI never decides an inbound comm's *route*.

## 2. Current reality (mapped from source — the load-bearing context)

### Email — the ONLY channel that auto-creates tickets
- Entry: Graph webhook (real-time) + a 5-min poll fallback → **`EmailService::processInbound`** (EmailService.php:209)
  — a single **deterministic if-ladder**: `isAutoReply` (drop OOO/bounces) → **`matchToExistingTicket`** (attach) →
  `evaluateSpam` (**unknown senders only**) → **`autoCreateTicketFromEmail`** (known sender + client resolved + <24h)
  → else `notifyUnresolvedEmail` (a human "Needs Attention" queue).
- **Attach = purely reply-threading**: `matchToExistingTicket` (EmailService.php:510) matches only the Graph
  `conversation_id`, the `In-Reply-To` header, or an explicit subject token `[T-123]`/`[ID:123]`/`[#123]`.
  **There is NO semantic "this is the same open issue, attach it" anywhere.**
  → **THE biggest gap:** a known contact who sends a *fresh* email (new subject, no `Re:`, no token) about an
  already-open issue fails all five checks and **`autoCreateTicketFromEmail` mints a DUPLICATE ticket.**
- **Dedup**: exact-message (`internet_message_id`) + a 2-hour same-subject window for **Mesh/Zorus vendor bursts
  only** (EmailService.php:696). No general "two emails, same issue" dedup.
- **Spam**: deterministic `spamScore` + AI `aiSpamCheck` for **unknown senders pre-ticket** (dismiss, no ticket);
  separately, `JunkDetector` (Triage) auto-*closes* junk **after** a ticket exists (a 2nd layer). **Known-sender
  spam still creates a ticket**, then may be closed downstream.
- **Unknown non-spam sender** → cannot auto-create (needs `client_id`) → sits in the manual queue. **No email
  prospect/lead intake.**

### Phone / voicemail — NEVER auto-creates a ticket
- Plivo webhook (PlivoWebhookController:214) records the call, detects voicemail, transcribes (store-only),
  resolves the caller async (`ResolveCallerFromPeople`), and **emails staff** (`notifyNewVoicemail`, ticketId
  null). **No `Ticket::create`/link anywhere in the call path.**
- Every call→ticket is **manual** (CallController `storeTicket`/`linkTicket`; ProspectController for unknown→
  prospect). The transcript + sentiment + **charge-classification are computed but unused for routing.**
- **Unknown caller** → just logs a warning, sits in a facet. `ProspectIntakeService::provisionFromCall` (unknown
  → Prospect+Ticket) exists but is **manual-only, phone-only, no LLM.**
- Gaps: a voicemail about an existing open ticket is **not linked**; **no call dedup**; **no post-call spam**.

### Existing machinery to REUSE (don't rebuild)
- **Spam/junk**: `JunkDetector` (deterministic + `aiConfirm`) + `EmailService::spamScore`/`aiSpamCheck`/`isAutoReply`.
- **Deterministic attach**: `matchToExistingTicket` (thread/token) + the Mesh/Zorus burst-link.
- **The dedup PRIMITIVE**: the agent's **`search_tickets`** read tool (client-scoped keyword search) + the AI-set
  ticket **`keywords`** (set specifically so "future runs can find related tickets"). The "is this a duplicate?"
  judgment can be built on this — the search exists; only the judgment-to-act is missing.
- **Merge MECHANISM**: `TicketService::mergeTickets` (parent_ticket_id, repoints notes/calls/emails/assets) —
  ready, but invoked **only manually** from the merge UI.
- **Contact resolution**: three per-channel resolvers (`resolveSender` / `findPersonByPhoneNumber` /
  `ContactResolver`) sharing `Person::whereEmailMatch`. No unified resolver.
- **Prospect**: `ProspectIntakeService::provisionFromCall` + the prospect-stage gate.
- **Wake/agent plumbing**: `RunTechnicianLoop`/`RunTechnicianAgent` seams; the agent's held/gated ACT pattern;
  the digest. NOTE: every agent wake is keyed to an **existing ticket** — there is **no pre-ticket inbound wake**.

## 3. Where the judgment slots in (my recommendation — a KEY DECISION for you)
**Not a new agent tool** (the agent is ticket-keyed / wakes on existing tickets; intake is *pre-ticket*). **Not a
triage stage** (triage runs *after* a ticket exists). I recommend a focused, standalone **`IntakeRouter`** service
(the AI judgment) invoked at the **two create-decision hook points**, with the existing deterministic gates kept
as the cheap pre-filter:
- **Email**: inside `processInbound`, at the moment it would `autoCreateTicketFromEmail` (i.e. a **known** sender,
  not thread-matched). Run the route judgment THERE — so the cheap deterministic gates (auto-reply, thread-attach,
  unknown-spam) still run first; the AI only fires in the genuinely-ambiguous "known sender, no thread match" case.
- **Phone**: at voicemail/transcription completion (`TranscriptionService` finally block) for a **resolved** caller
  — bringing phone into auto-intake for the first time (today it's nothing).
This is the surgical, lowest-risk shape: it closes the duplicate-ticket gap + adds voicemail routing, **reusing**
the spam/resolution/merge/search machinery, and the AI runs only on the ambiguous minority (cost/latency control).

## 4. The hard judgment calls (with my recommended trip-scoped posture)
| Call | Today | Recommended trip posture |
|---|---|---|
| **new-vs-attach** (the duplicate problem) | deterministic thread-only → dup tickets | AI judges "same open issue on this client?" via `search_tickets`. **Auto-attach only on HIGH confidence; CREATE-NEW otherwise** (a new ticket is recoverable; a wrong-attach buries a new issue). |
| **merge-dup** (two existing tickets are one issue) | 100% manual | **Do NOT auto-merge** (destructive — closes a ticket, repoints data). **Suggest/HOLD** a merge for operator approval (cockpit lane), or defer entirely to a later increment. |
| **contact resolution** (who is this) | per-channel resolvers; unknown dead-ends | Reuse the resolvers. **Auto-handle KNOWN senders only.** Unknown sender/caller → **HOLD for a human** (the existing queue) — do NOT auto-prospect (spam-vs-lead is high-risk; ProspectIntake stays deliberate). |
| **spam/noise** | deterministic + JunkDetector; known-sender spam slips through | Reuse the existing gates. The intake AI may **FLAG** suspected known-sender spam (currently unfiltered) — **never auto-dismiss a known client's email** (high-risk false-negative). |

## 5. Trip-scoped IN / OUT
**IN (the MVP):**
- The **semantic attach judgment for KNOWN-sender emails** — auto-attach high-confidence, create-new otherwise
  (closes the duplicate-ticket gap).
- The **same for resolved-caller voicemails** — attach-to-open-ticket or create (phone's first auto-intake).
- **Held-by-default → calibrate → graduate**: ships dormant behind a flag; the route is a SUGGESTION surfaced in
  the existing Needs-Attention queue / a cockpit lane for the risky cases; the safe action (create-new, the current
  default) and high-confidence attach can auto-apply. Digest counts by route for calibration (mirror propose_close
  held→auto-threshold).
**OUT (defer — surfaced so we agree on the boundary):**
- **Auto-merge** of two existing tickets (suggest/hold only, or a later increment) — destructive.
- **Unknown-sender auto-prospect/lead intake** (keep human; high false-positive risk).
- **Auto-dismissing known-sender spam** (flag only).
- A unified cross-channel resolver rewrite (reuse the three per-channel ones).
- Inbound real-time *conversation* (that's the guidance-loop D/E, post-trip).

## 6. Safety / blast radius (internal routing — NO client send here)
Like H, this is **internal-only** (it routes; it does not reply to a client). Blast radius of a WRONG route:
- **Wrong attach** (a new issue glued to an unrelated ticket) → the new issue is buried. *Mitigate:* high-confidence
  only; **create-new is the safe default**; held mode for ambiguous.
- **Wrong create** (a duplicate) → an extra ticket — annoying, recoverable, and the *current* behavior anyway. Low.
- **Wrong spam-dismiss** (a real request lost) → *Mitigate:* never auto-dismiss known senders (flag only); reuse the
  conservative existing gates.
- **Wrong merge** → destructive. *Mitigate:* don't auto-merge (suggest/hold).
- **Deterministic floor** (mirror the agent features): inbound email/voicemail content is **untrusted**; the AI's
  route is a *suggestion* gated by a confidence floor + the deterministic pre-gates — injected content must not be
  able to *force* an attach/merge/dismiss or pick the target. Output-scan any surfaced text.
**Net posture:** auto only the recoverable/high-confidence actions; hold the destructive/ambiguous; dormant by flag.

## 7. Risks + the calibration story
- **Over-attaching** (burying new issues) — the top risk. Mitigated by high-confidence-only + create-new default +
  held mode + digest visibility; calibrate the confidence band during the soak before flipping any auto.
- **AI cost/latency on every inbound** — mitigated by running the judgment **only in the ambiguous case** (the
  deterministic gates handle the clear majority cheaply).
- **The tempting-but-risky unknown-sender path** — explicitly out of scope for the trip.
- **Calibration**: ship dormant → held suggestions surfaced → measure attach/create/(suggested-)merge accuracy via
  digest counts → graduate the high-confidence attach band to auto. Same held→auto graduation as propose_close.

## 8. Open questions for you (to converge into the build plan)
1. **Held surface**: reuse the existing "Needs Attention" email queue + a cockpit "Intake / route review" lane, or
   a dedicated intake inbox? (I lean: reuse + a cockpit lane.)
2. **Auto vs hold the high-confidence ATTACH** at first enable — auto-attach when confident, or hold ALL routes for
   operator confirm during the initial soak then graduate? (I lean: hold-all first, graduate attach after calibration.)
3. **Phone scope for the MVP**: voicemails only (resolved caller), or also missed/answered calls? (I lean: voicemails
   with a resolved caller only — that's where the transcript + the gap are.)
4. **Merge**: suggest/hold in this increment, or defer merge entirely to a follow-up? (I lean: defer auto, optionally
   surface a non-acting "possible duplicate" hint.)
5. **Tool vs service shape**: a standalone `IntakeRouter` invoked at the two hook points (my rec), vs a new pre-ticket
   agent wake/tool. (I lean: the service — the agent is ticket-keyed.)

## 9. Proposed build shape (once converged) — for sizing only
~A 4–5 task TDD/SDD increment, dormant: (1) `IntakeRouter` + config/dormancy flag + the route enum/record; (2) the
email hook (known-sender, no-thread-match → route judgment, reuse search_tickets); (3) the voicemail hook; (4) the
held surface (queue/cockpit lane + digest counts); (5) calibration/visibility. Reuses spam/resolution/merge/search;
no client send; deterministic floor; output-scanned. Mirrors the H delivery rigor (opus whole-branch + /soundpsa-review-pr).
