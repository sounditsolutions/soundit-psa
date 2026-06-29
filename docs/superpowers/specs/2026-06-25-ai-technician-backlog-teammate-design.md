# AI Technician — The Backlog Teammate (adaptive old-ticket onboarding)

> Design doc. Status: brainstormed + approved-in-shape + **baseline-validated** (Charlie, 2026-06-25; an overnight dev experiment confirmed the judgment quality + 0 false-closes — see "Baseline validation"). Next: implementation plan (writing-plans).

## Why this exists

A small MSP's stale ticket backlog is not debris. It's the pile of things that got **stuck because they were genuinely hard, ambiguous, or over your head in the moment** — and quietly avoiding them compounds into a low-grade sense of helplessness that most one- and two-person shops carry and rarely talk about. (Sound IT's own board, 2026-06-25: 101 open, ~68 untouched 30+ days, 28 over 90 days, half with no recorded response — and **zero P1**. It's not on-fire; it's avoided.)

So "help with the backlog" does not mean "ring an alarm about how much is stale" (the first naive cut — the emergency-flood incident, where the deterministic age-sweep flagged 70 stale tickets as emergencies the moment it was enabled) and it does not mean "run a script that closes old tickets." It means a **teammate that helps you finally face the pile**: read a gnarly stuck ticket and come back with *"here's what this actually is, here's how I'd approach it, here's a draft for when you're ready — and you've got eight like it, so here's the SOP so it never strands you again."* That move — bringing capability, a path, and documented know-how to the stuff you've been avoiding — turns helpless into capable. It is the single most resonant reason a small MSP would "hire" the third employee they can't afford.

This is the next capability after the trip-critical Technician arc (1A draft → 1B cockpit → 1C notify → Phase 2 backstop, all shipped dormant). It is **not** the emergency backstop, and the contrast is the whole point (see Principles).

## What we're building

An **adaptive AI teammate** whose first job is an **onboarding pass over your old tickets** — learning your clients, their environments, and how you work by reading the history — and who, like a good new hire, **recognizes opportunities and acts on them**: closes the dead, brings expertise + a proposed path to the stuck, captures undocumented facts into the client wiki, and surfaces the questions that become better SOPs.

It **proposes everything at first** (held for your approval — maximum transparency is how a new teammate earns the rope to do more), works at a **human pace** (a steady drip + a daily briefing, never 70 things five minutes after you flip it on), and **earns autonomy** as it learns your calls. It reuses your existing assessment, resolution, and wiki-mining machinery as rails; what is genuinely new is the **adaptive judgment, the open-ended opportunity-recognition, the SOP/question surface, and the propose-everything-held-and-paced posture.**

**v1 is bounded:** the onboarding pass over the *existing* old/stale backlog, **internal-only**, **held**, **paced** — proving the adaptive shape on a finite, low-risk, immediately-valuable surface. It then extends naturally to live tickets and the pending-client / awaiting-us / client-facing work (later slices, separate specs).

## Principles (these are the design, not decoration)

1. **Adaptive in the head, controlled in the hands.** The AI is *unconstrained* in how it reads, reasons, prioritizes, spots patterns, and decides what it would recommend — that is zero-risk and is where all the value lives. The human stays in control of **consequences** — anything that closes a ticket, writes to the wiki, sends anything, or touches a client is a *proposed* action, one tap from you. The approval gate is not a cage on the AI's thinking; it is the very thing that lets you hand a still-junior AI open-ended judgment, because a bad call costs a veto, not a closed-live-ticket.
2. **This is the opposite stance from the emergency backstop — deliberately.** A relied-on "never miss a real emergency" detector *must* be deterministic and predictable. Clearing a backlog is judgment work — exactly where adaptiveness earns its keep. Knowing which job wants which stance is most of the game; the flood was the wrong stance (deterministic alarm) applied to the wrong job (judgment work).
3. **Signal, not enumeration.** The output is a teammate's briefing — "I cleared ~30 obvious ones; two need your eyes; here's a pattern; want me to always handle the Acme monitoring noise?" — not a five-bucket spreadsheet.
4. **Paced, not a flood.** Cadence is a hard requirement. The pass works through the finite backlog over days, a bounded batch at a time.
5. **Earns autonomy on evidence.** Starts proposing everything; as it learns what you wave through, it asks about those less and does more — the trust dial is real and moves on demonstrated agreement, never front-loaded.

## How it relates to what already exists (reuse + supersede, don't rebuild)

The system already has, in **reactive / deterministic** form, most of the plumbing:

- **`ConversationReviewer`** (`triage:review-open`) already reads open tickets, classifies `resolved / waiting-customer / waiting-us / junk / active` with confidence + an internal reasoning note, and can **auto-close** `resolved`/`junk` above a threshold. → The teammate **reuses its assessment signal** but replaces the binary auto-close/just-note posture with adaptive, held proposals. While the teammate earns trust, **the deterministic auto-close (`triage_review_auto_close`) is turned OFF so they don't double-act**; the teammate's judgment is the successor.
- **`TicketResolutionDrafter` / `GenerateTicketResolution`** auto-drafts a resolution summary on close. → Free downstream: when the teammate's proposed close is approved, this fills the resolution.
- **Wiki-mining** (`MineTicketKnowledge` → `WikiFactExtractor` → per-client facts/overview) fires on close-with-resolution. → Free downstream knowledge capture. The teammate's *new* contribution is proposing wiki facts / SOP gaps it notices **while reading old open tickets** (which mining does not do today — mining only runs post-resolution).
- **`WikiMaintainService::staleOpenTicketSweep`** already *flags* long-idle open tickets carrying undocumented knowledge — but only flags. → The teammate is what finally **acts** on that signal.
- **Technician machinery** — `TechnicianRun`, `TechnicianActionGate`, `CockpitQuery`, `ContextBuilder`, `OperatorNotifier`, `DigestBuilder`, the AI actor — are the rails the teammate runs on.

Net: this is **not** a new closer or a new miner. It is the **adaptive judgment + opportunity-recognition layer** that rides the existing rails and supersedes the deterministic auto-close with earned, held judgment.

## Baseline validation (2026-06-25 dev experiment)

Before building, we ran the *existing* reactive AI (the `ConversationReviewer`, auto-close ON) overnight on 50 seeded aged tickets matching this backlog's shape (15 resolved-not-closed, 10 ghosted, 8 awaiting-us, 8 pending, 5 junk, 4 active). Two results shape this design:

1. **The safety model is validated empirically — ZERO false-closes.** Across all 30 should-stay-open tickets, including all 8 safety-critical *awaiting-us* ones (we owe the client a reply), the AI closed **none**; the awaiting-us tickets that got a full assessment were correctly flagged "Needs Our Attention." Judgment on the live-vs-dead distinction was effectively 100% correct, with quoted evidence. → The "AI judges freely, consequences stay held/safe" thesis holds: even with auto-close ON, the conservative judgment never endangered a live ticket. The teammate's propose-held + **flag-don't-close** posture is the safe default, and this is the evidence for it.

2. **The weak link is action-wiring, not judgment — which is exactly the teammate's job.** The AI judged all 50 correctly, but the existing reviewer only *converted* that into a close on **4 of the ~20** it should have: only ~13/50 produced the structured verdict that triggers auto-close; the other ~37 judged correctly *in prose* but never acted. → This is the core argument FOR the teammate: **reuse the reviewer's assessment, but own the action proposal** — take the (good) judgment and explicitly propose the action per ticket, held for approval, instead of leaning on the brittle implicit auto-close that silently no-ops.

3. **The docs-as-byproduct works:** the closes mined ~16 good-quality wiki facts — the "makes the shop smarter" loop is real and rides the existing wiki pipeline for free.

(Throughput: the AI pass was fast — 41 min for 50 tickets, ~1.5M tokens, 0 failures; the overnight queue lingering was a ticket-notification artifact, not AI latency. Full analysis: `.superpowers/sdd/overnight-baseline-analysis.md`.)

## The opportunity set

The AI is prompted as a teammate with a goal and the ticket's full context, and a **bounded toolbox of gated actions** — it reasons freely about which fits and why (open-ended judgment), and may always choose "leave it / I'm not sure." It is NOT a fixed classifier; the "opportunities" below are the available tools, not rigid output labels.

v1 tools (all low-risk / internal / held):
- **Propose close** — for the dead / resolved-just-never-closed / abandoned. Sets `resolved` (reopenable) on approval, with a disposition-aware note ("resolved — client confirmed sorted 104 days ago" vs "no response after N days"). Downstream resolution-draft + mining fire for free.
- **Bring a path to the stuck** — for the hard/avoided ones: an internal note with *"here's what this is, here's how I'd approach it,"* optionally a held draft of the fix/reply, and a **flag for your eyes**. (Actually sending/executing is a later, client-facing slice.)
- **Propose a wiki fact / SOP entry** — capture undocumented client/environment knowledge it learned from the ticket, routed to the existing wiki pipeline as a held proposal.
- **Raise a question / surface an SOP gap** — "why do we handle X this way? there's no SOP and I found 8 of these" — surfaced in the briefing as a process-improvement candidate.

Explicitly NOT in v1: any client-facing send, autonomous execution, auto-close. Recognition is open-ended; **consequences are bounded and held.**

## Architecture (the loop)

A scheduled **backlog-worker** (sibling of the emergency sweep; gated on `TechnicianConfig::enabled()` → ships dormant):

1. **Candidate selection (paced).** Open, *operational-client* tickets (excludes prospects), oldest/stalest first, a **bounded batch per run** (config; the onboarding pass deliberately drips through the finite backlog over days). Skips tickets a human touched recently and ones already worked this pass.
2. **Adaptive assessment.** For each candidate, the AI actor reads the ticket via `ContextBuilder` (reusing `ConversationReviewer`'s context where sensible) and, prompted as the junior-tech teammate, decides the right move(s) from the toolbox, with reasoning + confidence. Free judgment; bounded actions.
3. **Held proposals through the gate.** Each chosen action becomes a `TechnicianRun` action (new internal `action_type`s: `propose_close`, `propose_wiki`, `flag_attention`, `raise_question`) routed through `TechnicianActionGate` — **HELD** (never auto-executes in v1), audited, with the reasoning/opportunity-type in `proposed_meta`. No client-send path touched.
4. **Briefing + cockpit.** A new cockpit **"Backlog"** lane (extends `CockpitQuery`) lists the held proposals to approve/veto at your pace; a **daily AI-narrative briefing** (extends `DigestBuilder`/`OperatorNotifier`) gives the signal — "worked 12 old tickets: closed 8, 2 need you (stuck on X), 1 SOP gap, pattern: client Y never self-closes." One nudge, not N.
5. **Act on approval.** The gate executor performs the *internal* consequence (set status / write the wiki proposal / record the flag). Disposition + AI-proposed + operator-approved attribution in the immutable audit log.
6. **Record decisions (autonomy seed).** Every approve/veto is recorded — the data a future autonomy dial learns from (v2: auto-approve patterns you've consistently waved through).

## Pacing

Hard requirement. A bounded batch per scheduled run + a once-daily briefing; the onboarding pass works the finite backlog down over days, not in one dump. No proposal storm on enable. (The flood is the anti-pattern this exists to avoid.)

## Trust & autonomy

v1: **propose-everything-held** + record every decision. The deterministic `triage_review_auto_close` is turned off while the teammate earns trust (no double-acting). **Forward path (v2+, not built now):** a learned autonomy dial — the teammate stops asking about classes of action you've consistently approved and starts doing them and telling you, per the recorded-decision data. Autonomy grows on evidence, never front-loaded.

## Safety & dormancy

- Gated on `enabled()` → ships dormant; nothing runs in prod until you flip it on (and then it's paced + held).
- **Internal-only in v1** — zero client contact; the entire client-facing surface is deferred. This sidesteps the "AI in front of the client" risk entirely for the first proving ground.
- Operational clients only; held consequences; append-only audit; the judgment/consequence split above.
- Three layers between "AI thinks X" and "X happens": the conservative candidate rule, the AI's own reasoning/confidence, and your approval.

## Data & interfaces

- Reuse `TechnicianRun` + new internal `action_type`s; reasoning/opportunity/confidence in `proposed_meta`.
- New `backlog-worker` command + schedule (`->when(enabled())`).
- `CockpitQuery` "Backlog" lane; `DigestBuilder` narrative-briefing section; `OperatorNotifier` daily nudge.
- Reuse `ContextBuilder`, the AI actor, `TechnicianActionGate`, the wiki pipeline, the resolution drafter.
- A decisions log (reuse the audit log) as the autonomy-dial seed.

## Out of scope / deferred (the v1 cut)

- Any client-facing send or autonomous execution (later client-facing slices).
- The learned autonomy dial (v2 — v1 only records the data).
- The pending-client-nudge and awaiting-us-reply backlog slices (separate specs).
- Full conversational back-and-forth with the teammate (v1 = narrative briefing + per-item approve/veto; richer dialogue later).
- The Phase 2 emergency-detector "anchor age to coverage-start" fix — **separate small follow-up** (tracked under psa-uvuy / its own bead); different surface, kept out to stay focused.

## Testing

- Candidate selection: paced batch size, operational-only, oldest-first, skips recent/already-worked, dormant when disabled.
- Adaptive assessment: the AI produces a held proposal with reasoning; never auto-executes; "leave it / unsure" path; disposition-aware close note.
- Gate: each new `action_type` held by default; approval performs only the internal consequence; audit attribution correct; append-only preserved.
- Briefing/cockpit: the Backlog lane lists held proposals; the daily briefing summarizes (and does not flood).
- Coordination: with `triage_review_auto_close` off, no double-acting on the same ticket.
- Reuse seams: approved close → resolution-draft + wiki-mining fire; proposed wiki fact routes through redaction.

## Open questions for the plan stage

- Exact pacing defaults (batch size per run; onboarding vs steady-state cadence).
- Whether the teammate *replaces* `triage:review-open` for old tickets or runs as a separate sweep that consumes its assessment (lean: separate sweep, reuse the assessment).
- The briefing's narrative shape (how much the AI summarizes vs. lists).
- Minimal proposed-wiki path: route to the existing miner as a held proposal vs. a lighter "suggested fact" surface.

## Decisions made (don't relitigate)

Adaptive judgment / human-owned consequences; internal-only + held + paced for v1; reuse-not-rebuild on the existing review/resolution/wiki rails; turn off the deterministic auto-close while earning trust; propose-everything-first, autonomy earned later; old-ticket backlog as the bounded first proving ground; the "why" (the helpless backlog) is the north star, not ticket-count cleanup.
